<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderPaymentMethod;
use Feeder\Core\Enums\OrderPaymentReviewStatus;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderFulfillment;
use Illuminate\Validation\ValidationException;

/**
 * Invoice number helpers for supplier print / fulfillment.
 *
 * Invoice numbers mirror orders.order_number (canonical 11-digit commercial ID).
 * They are stable once generated and idempotent on repeated ensure calls.
 */
class SupplierInvoiceService
{
    public function generateInvoiceNumber(OrderFulfillment $fulfillment): string
    {
        $fulfillment->loadMissing('order');

        $orderNumber = trim((string) ($fulfillment->order?->order_number ?? ''));

        if ($orderNumber === '') {
            throw ValidationException::withMessages([
                'order_number' => ['Order number is required before generating an invoice number.'],
            ]);
        }

        return $orderNumber;
    }

    /**
     * Ensure a stable invoice number exists. Safe to call repeatedly.
     * Always equals the parent order's order_number.
     */
    public function ensureInvoiceNumber(OrderFulfillment $fulfillment): string
    {
        $fulfillment->loadMissing('order');

        $orderNumber = trim((string) ($fulfillment->order?->order_number ?? ''));

        if ($orderNumber === '') {
            throw ValidationException::withMessages([
                'order_number' => ['Order number is required before generating an invoice number.'],
            ]);
        }

        if ($fulfillment->invoice_number === $orderNumber) {
            return $orderNumber;
        }

        $fulfillment->forceFill([
            'invoice_number' => $orderNumber,
            'invoice_generated_at' => $fulfillment->invoice_generated_at ?? now(),
        ])->save();

        return $orderNumber;
    }

    /**
     * Payment label for invoice printing without adding payment_method to orders.
     *
     * @return array{label: string, is_payment_collected: bool, cod_amount: string|null}
     */
    public function resolvePaymentDisplay(Order $order): array
    {
        $hasApprovedBankTransfer = $order->paymentSubmissions()
            ->where('method', OrderPaymentMethod::BANK_TRANSFER)
            ->where('review_status', OrderPaymentReviewStatus::APPROVED)
            ->exists();

        if ($hasApprovedBankTransfer) {
            return [
                'label' => 'Payment Collected',
                'is_payment_collected' => true,
                'cod_amount' => null,
            ];
        }

        return [
            'label' => 'COD',
            'is_payment_collected' => false,
            'cod_amount' => (string) $order->customer_payable_amount,
        ];
    }

    /**
     * Printing requires shipment + tracking + courier (+ city when required by data).
     */
    public function assertPrintReady(Order $order): void
    {
        $order->loadMissing('shipment.courier', 'shipment.city');

        $shipment = $order->shipment;

        if ($shipment === null) {
            throw ValidationException::withMessages([
                'shipment' => ['A booked shipment is required before printing.'],
            ]);
        }

        if ($shipment->tracking_number === null || trim((string) $shipment->tracking_number) === '') {
            throw ValidationException::withMessages([
                'tracking_number' => ['A waybill / tracking number is required before printing.'],
            ]);
        }

        if ($shipment->courier_id === null) {
            throw ValidationException::withMessages([
                'courier_id' => ['Courier information is required before printing.'],
            ]);
        }

        if ($shipment->courier_city_id === null) {
            throw ValidationException::withMessages([
                'courier_city_id' => ['Courier city is required before printing.'],
            ]);
        }
    }

    public function isPrintReady(Order $order): bool
    {
        try {
            $this->assertPrintReady($order);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * Build the invoice/waybill print payload from order snapshots (not live product prices).
     *
     * @return array<string, mixed>
     */
    public function buildPrintPayload(Order $order): array
    {
        $order->loadMissing([
            'supplier.company',
            'resellerCompany',
            'address',
            'items.variant',
            'shipment.courier',
            'shipment.city.state',
            'shipment.supplierCourierAccount',
            'fulfillment',
        ]);

        $this->assertPrintReady($order);

        $fulfillment = $order->fulfillment;
        if ($fulfillment === null || $fulfillment->isCancelled()) {
            throw ValidationException::withMessages([
                'fulfillment' => ['Active fulfillment is required before printing.'],
            ]);
        }

        $invoiceNumber = $this->ensureInvoiceNumber($fulfillment);
        $payment = $this->resolvePaymentDisplay($order);
        $shipment = $order->shipment;
        $address = $order->address;
        $courierLabel = $shipment?->supplierCourierAccount?->account_label
            ?: $shipment?->courier?->name;

        return [
            'supplier' => [
                'company_name' => $order->supplier?->company?->name,
            ],
            'customer_care' => [
                'reseller_company_phone' => $order->resellerCompany?->phone,
            ],
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'invoice_number' => $invoiceNumber,
                'issued_date' => ($fulfillment->invoice_generated_at ?? now())->toDateString(),
                'reseller_company_name' => $order->resellerCompany?->name,
                'payment' => $payment,
            ],
            'courier' => [
                'label' => $courierLabel,
                'name' => $shipment?->courier?->name,
                'tracking_number' => $shipment?->tracking_number,
                'city' => $shipment?->city?->city_name ?? $shipment?->city?->name,
                'district' => $shipment?->city?->district_name,
                'state' => $shipment?->city?->state?->name,
            ],
            'customer' => [
                'name' => $order->customer_name_snapshot ?? $address?->recipient_name,
                'address' => $address?->full_address_text
                    ?? trim(implode(', ', array_filter([
                        $address?->line1,
                        $address?->line2,
                        $address?->city_name,
                        $address?->district_name,
                    ]))),
                'contact_1' => $order->primary_phone_snapshot,
                'contact_2' => $order->secondary_phone_snapshot,
                'contact_display' => collect([
                    $order->primary_phone_snapshot,
                    $order->secondary_phone_snapshot,
                ])->filter(fn ($phone) => filled($phone))->implode(' | ') ?: null,
            ],
            'items' => $order->items->map(fn ($item) => [
                'order_item_id' => $item->id,
                'product_name' => $item->product_name_snapshot,
                'variant' => $item->variant_name_snapshot,
                'quantity' => (int) $item->quantity,
                'unit_selling_price' => (string) $item->unit_selling_price,
                'barcode' => $item->variant?->barcode ?? $item->barcode_snapshot,
            ])->values()->all(),
            'charges' => [
                'items_subtotal' => (string) $order->items_subtotal,
                'courier_fee' => (string) (
                    $shipment?->courier_fee_snapshot
                    ?? $order->courier_fee_amount
                    ?? '0.00'
                ),
                'customer_payable_amount' => (string) $order->customer_payable_amount,
                'payment_status' => $payment['label'],
                'is_payment_collected' => $payment['is_payment_collected'],
                'cod_amount' => $payment['cod_amount'],
            ],
        ];
    }
}
