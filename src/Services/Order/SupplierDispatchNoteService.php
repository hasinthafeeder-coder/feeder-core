<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Exceptions\SupplierFulfillmentException;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\DispatchNote;
use Feeder\Core\Models\DispatchNoteOrder;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Persists courier handover Dispatch Notes without changing order lifecycle.
 *
 * Creating a note associates current DISPATCHED orders for one courier batch.
 * It never calls OrderStatusService and never mutates order/shipment status.
 */
class SupplierDispatchNoteService
{
    /**
     * @return array<string, mixed>
     */
    public function createForCourier(int $supplierId, ?int $courierId, int $operatorUserId): array
    {
        if ($supplierId <= 0) {
            throw new SupplierFulfillmentException(
                'SUPPLIER_REQUIRED',
                'Supplier context is required to create a Dispatch Note.',
                status: 403,
            );
        }

        if ($courierId !== null && $courierId > 0) {
            $courierExists = Courier::query()->whereKey($courierId)->exists();
            if (! $courierExists) {
                throw new SupplierFulfillmentException(
                    'COURIER_NOT_FOUND',
                    'Courier service was not found.',
                    status: 404,
                );
            }
        } else {
            $courierId = null;
        }

        $note = DB::transaction(function () use ($supplierId, $courierId, $operatorUserId): DispatchNote {
            $orders = $this->pendingOrdersQuery($supplierId, $courierId)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($orders->isEmpty()) {
                throw new SupplierFulfillmentException(
                    'NO_PENDING_HANDOVER_ORDERS',
                    'No DISPATCHED orders await handover for this courier.',
                );
            }

            foreach ($orders as $order) {
                if ($order->dispatchNoteOrder !== null) {
                    throw new SupplierFulfillmentException(
                        'ORDER_ALREADY_ON_DISPATCH_NOTE',
                        'One or more orders are already on a Dispatch Note.',
                        [
                            'order_number' => (string) $order->order_number,
                        ],
                    );
                }
            }

            $handedOverAt = now();
            $cod = $this->codSnapshot($orders);

            $note = DispatchNote::query()->create([
                'dispatch_number' => $this->allocateDispatchNumber($handedOverAt),
                'supplier_id' => $supplierId,
                'courier_id' => $courierId,
                'created_by' => $operatorUserId,
                'orders_count' => $orders->count(),
                'total_cod_amount' => $cod['amount'],
                'currency_code' => $cod['currency_code'],
                'handed_over_at' => $handedOverAt,
            ]);

            foreach ($orders as $order) {
                DispatchNoteOrder::query()->create([
                    'dispatch_note_id' => $note->id,
                    'order_id' => $order->id,
                ]);
            }

            return $note->fresh([
                'courier',
                'creator',
                'supplier.company',
                'noteOrders.order.shipment',
                'noteOrders.order.fulfillment',
                'noteOrders.order.draftCourier',
            ]) ?? $note;
        });

        return $this->serializeNote($note);
    }

    /**
     * @return array<string, mixed>
     */
    public function showForSupplier(int $supplierId, string $uuid): array
    {
        $note = $this->ownedNoteQuery($supplierId)
            ->where('uuid', $uuid)
            ->with([
                'courier',
                'creator',
                'supplier.company',
                'noteOrders.order.shipment',
                'noteOrders.order.fulfillment',
                'noteOrders.order.draftCourier',
            ])
            ->first();

        if ($note === null) {
            throw new SupplierFulfillmentException(
                'DISPATCH_NOTE_NOT_FOUND',
                'Dispatch Note was not found.',
                status: 404,
            );
        }

        return $this->serializeNote($note);
    }

    public function findOwnedOrFail(int $supplierId, string $uuid): DispatchNote
    {
        $note = $this->ownedNoteQuery($supplierId)
            ->where('uuid', $uuid)
            ->with([
                'courier',
                'creator',
                'supplier.company',
                'noteOrders.order.shipment',
                'noteOrders.order.fulfillment',
                'noteOrders.order.draftCourier',
            ])
            ->first();

        if ($note === null) {
            throw new SupplierFulfillmentException(
                'DISPATCH_NOTE_NOT_FOUND',
                'Dispatch Note was not found.',
                status: 404,
            );
        }

        return $note;
    }

    /**
     * @return Collection<int, DispatchNote>
     */
    public function listForSupplier(int $supplierId): Collection
    {
        if ($supplierId <= 0) {
            return collect();
        }

        return $this->ownedNoteQuery($supplierId)
            ->with([
                'courier',
                'creator',
                'supplier.company',
                'noteOrders.order.shipment',
                'noteOrders.order.fulfillment',
                'noteOrders.order.draftCourier',
            ])
            ->orderByDesc('handed_over_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeNote(DispatchNote $note): array
    {
        $handedOverAt = $note->handed_over_at instanceof Carbon
            ? $note->handed_over_at
            : null;
        $formatted = $this->formatTimestamp($handedOverAt);
        $orders = $note->noteOrders
            ->map(fn (DispatchNoteOrder $row) => $row->order)
            ->filter()
            ->values()
            ->map(fn (Order $order) => $this->serializeOrder($order))
            ->all();

        return [
            'id' => (string) $note->id,
            'uuid' => (string) $note->uuid,
            'dispatch_no' => (string) $note->dispatch_number,
            'state' => 'RECORDED',
            'shipment_status' => null,
            'courier' => $note->courier?->name ?: 'No courier recorded',
            'courier_id' => $note->courier_id !== null ? (string) $note->courier_id : '',
            'dispatch_date' => $formatted['date'],
            'dispatch_date_display' => $formatted['date_display'],
            'dispatch_time' => $formatted['time'],
            'dispatch_time_display' => $formatted['time_display'],
            'operator' => $this->operatorLabel($note->creator),
            'orders_count' => (int) $note->orders_count,
            'total_cod' => $this->formatMoney(
                $note->total_cod_amount === null ? null : (string) $note->total_cod_amount
            ),
            'currency_code' => (string) ($note->currency_code ?? ''),
            'supplier' => $this->supplierLabel($note->supplier),
            'note_recorded' => true,
            'orders' => $orders,
        ];
    }

    private function ownedNoteQuery(int $supplierId): Builder
    {
        return DispatchNote::query()->forSupplier($supplierId);
    }

    private function pendingOrdersQuery(int $supplierId, ?int $courierId): Builder
    {
        $query = Order::query()
            ->where('supplier_id', $supplierId)
            ->where('status', OrderStatus::DISPATCHED->value)
            ->where(function (Builder $ownership) use ($supplierId): void {
                $ownership->whereHas('items.product', function (Builder $product) use ($supplierId): void {
                    $product->where('supplier_id', $supplierId);
                })->orWhereDoesntHave('items.product');
            })
            ->whereDoesntHave('dispatchNoteOrder')
            ->with(['dispatchNoteOrder', 'shipment', 'draftCourier']);

        // Match SupplierAfterDispatchWorkspaceService::courier():
        // shipment.courier when present, otherwise draftCourier.
        if ($courierId === null) {
            $query->where(function (Builder $courierScope): void {
                $courierScope
                    ->where(function (Builder $noShipment): void {
                        $noShipment
                            ->whereDoesntHave('shipment')
                            ->whereNull('draft_courier_id');
                    })
                    ->orWhere(function (Builder $shipmentWithoutCourier): void {
                        $shipmentWithoutCourier
                            ->whereHas('shipment', function (Builder $shipment): void {
                                $shipment->whereNull('courier_id');
                            })
                            ->whereNull('draft_courier_id');
                    });
            });
        } else {
            $query->where(function (Builder $courierScope) use ($courierId): void {
                $courierScope
                    ->whereHas('shipment', function (Builder $shipment) use ($courierId): void {
                        $shipment->where('courier_id', $courierId);
                    })
                    ->orWhere(function (Builder $draftFallback) use ($courierId): void {
                        $draftFallback
                            ->where(function (Builder $missingShipmentCourier): void {
                                $missingShipmentCourier
                                    ->whereDoesntHave('shipment')
                                    ->orWhereHas('shipment', function (Builder $shipment): void {
                                        $shipment->whereNull('courier_id');
                                    });
                            })
                            ->where('draft_courier_id', $courierId);
                    });
            });
        }

        return $query;
    }

    private function allocateDispatchNumber(Carbon $at): string
    {
        $prefix = 'DN-'.$at->copy()->timezone(config('app.timezone'))->format('Ymd').'-';

        $latest = DispatchNote::query()
            ->where('dispatch_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('dispatch_number')
            ->value('dispatch_number');

        $next = 1;
        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $matches) === 1) {
            $next = ((int) $matches[1]) + 1;
        }

        return sprintf('%s%03d', $prefix, $next);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array{amount: ?string, currency_code: ?string}
     */
    private function codSnapshot(Collection $orders): array
    {
        $currency = null;
        $conflict = false;
        $total = '0.00';
        $hasAmount = false;

        foreach ($orders as $order) {
            $code = (string) ($order->currency_code_snapshot ?? '');
            if ($currency === null) {
                $currency = $code;
            } elseif ($currency !== $code) {
                $conflict = true;
            }

            if ($order->customer_payable_amount !== null) {
                $hasAmount = true;
                $total = $this->addMoney($total, (string) $order->customer_payable_amount);
            }
        }

        if ($conflict || ! $hasAmount) {
            return [
                'amount' => $conflict ? null : ($hasAmount ? $total : null),
                'currency_code' => $conflict ? null : ($currency !== '' ? $currency : null),
            ];
        }

        return [
            'amount' => $total,
            'currency_code' => $currency !== '' ? $currency : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrder(Order $order): array
    {
        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::from((string) $order->status);

        return [
            'order_number' => (string) $order->order_number,
            'customer_name' => (string) $order->customer_name_snapshot,
            'primary_phone' => (string) $order->primary_phone_snapshot,
            'waybill' => $order->shipment?->tracking_number,
            'invoice_number' => $order->fulfillment?->invoice_number,
            'cod_amount' => $this->formatMoney(
                $order->customer_payable_amount === null ? null : (string) $order->customer_payable_amount
            ),
            'currency_code' => (string) ($order->currency_code_snapshot ?? ''),
            'state' => $status->value,
        ];
    }

    private function operatorLabel(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $email = trim((string) ($user->email ?? ''));

        return $email !== '' ? $email : null;
    }

    private function supplierLabel(?User $supplier): string
    {
        $company = trim((string) ($supplier?->company?->name ?? ''));
        if ($company !== '') {
            return $company;
        }

        $email = trim((string) ($supplier?->email ?? ''));

        return $email !== '' ? $email : 'Supplier';
    }

    /**
     * @return array{display: ?string, date: string, date_display: ?string, time: ?string, time_display: ?string}
     */
    private function formatTimestamp(?Carbon $value): array
    {
        if ($value === null) {
            return [
                'display' => null,
                'date' => '',
                'date_display' => null,
                'time' => null,
                'time_display' => null,
            ];
        }

        $local = $value->copy()->timezone(config('app.timezone'));

        return [
            'display' => $local->format('d M Y, H:i'),
            'date' => $local->toDateString(),
            'date_display' => $local->format('d M Y'),
            'time' => $local->format('H:i'),
            'time_display' => $local->format('h:i A'),
        ];
    }

    private function formatMoney(?string $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2);
    }

    private function addMoney(string $left, string $right): string
    {
        if (function_exists('bcadd')) {
            return bcadd($left, $right, 2);
        }

        return number_format(((float) $left) + ((float) $right), 2, '.', '');
    }
}
