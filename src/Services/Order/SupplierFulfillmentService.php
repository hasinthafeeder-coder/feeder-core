<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\OrderType;
use Feeder\Core\Enums\StockReservationStatus;
use Feeder\Core\Enums\SupplierFulfillmentEventType;
use Feeder\Core\Enums\SupplierFulfillmentStatus;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderFulfillment;
use Feeder\Core\Models\OrderFulfillmentEvent;
use Feeder\Core\Models\OrderFulfillmentItem;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Models\StockConsumption;
use Feeder\Core\Models\StockReservation;
use Feeder\Core\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierFulfillmentService
{
    public function __construct(
        private readonly SupplierOrderAuthorizationGuard $authorizationGuard,
        private readonly SupplierFulfillmentQueryService $queryService,
        private readonly SupplierInvoiceService $invoiceService,
        private readonly StockService $stockService,
        private readonly OrderStatusService $orderStatusService,
    ) {
    }

    /**
     * Ensure a FRESH fulfillment record exists for an eligible confirmed NEW order.
     * Does not reserve stock.
     */
    public function ensureFresh(Order $order, int $supplierId, ?int $actorUserId = null): OrderFulfillment
    {
        return DB::transaction(function () use ($order, $supplierId, $actorUserId) {
            $order = Order::query()->with(['items', 'fulfillment'])->lockForUpdate()->findOrFail($order->id);
            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);
            $this->assertConfirmedNewOrder($order);

            $fulfillment = $order->fulfillment;

            if ($fulfillment !== null) {
                if ($fulfillment->isCancelled()) {
                    throw ValidationException::withMessages([
                        'fulfillment' => ['This order fulfillment was cancelled.'],
                    ]);
                }

                if ($fulfillment->status !== SupplierFulfillmentStatus::FRESH) {
                    throw ValidationException::withMessages([
                        'fulfillment' => [
                            'Order is already in '.$fulfillment->status->label().' and cannot enter Fresh.',
                        ],
                    ]);
                }

                return $fulfillment->fresh(['items']);
            }

            $fulfillment = $this->createFulfillmentSkeleton(
                $order,
                SupplierFulfillmentStatus::FRESH,
                $actorUserId ?? $supplierId,
            );

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::FRESH,
                $actorUserId ?? $supplierId,
            );

            return $fulfillment->fresh(['items']);
        });
    }

    /**
     * FRESH → PRINT with all-or-nothing stock reservation.
     */
    public function sendToPrint(Order $order, int $supplierId, ?int $actorUserId = null): OrderFulfillment
    {
        return DB::transaction(function () use ($order, $supplierId, $actorUserId) {
            $actorUserId ??= $supplierId;

            $order = Order::query()
                ->with(['items', 'fulfillment.items'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);

            $fulfillment = $order->fulfillment;

            if ($fulfillment !== null && $fulfillment->isCancelled()) {
                throw ValidationException::withMessages([
                    'fulfillment' => ['This order fulfillment was cancelled.'],
                ]);
            }

            if ($fulfillment !== null && $fulfillment->status !== SupplierFulfillmentStatus::FRESH) {
                throw ValidationException::withMessages([
                    'fulfillment' => [
                        'Send to Print is only valid from Fresh. Current state: '.$fulfillment->status->label().'.',
                    ],
                ]);
            }

            if ($order->status !== OrderStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'fulfillment' => [
                        'Send to Print is only valid from Confirmed. Current order status: '
                        .($order->status instanceof OrderStatus
                            ? $order->status->label()
                            : (string) $order->status)
                        .'.',
                    ],
                ]);
            }

            $this->assertConfirmedNewOrder($order);

            if ($fulfillment === null) {
                $fulfillment = $this->createFulfillmentSkeleton(
                    $order,
                    SupplierFulfillmentStatus::FRESH,
                    $actorUserId,
                );

                $this->recordEvent(
                    $order,
                    $fulfillment,
                    SupplierFulfillmentEventType::FRESH,
                    $actorUserId,
                );
            }

            $items = $order->items;
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['Order has no items to fulfill.'],
                ]);
            }

            $variantIds = $items->pluck('product_variant_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            // Serialize concurrent reservations for the same variants.
            ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $existingActive = StockReservation::query()
                ->active()
                ->where('order_id', $order->id)
                ->exists();

            if ($existingActive) {
                throw ValidationException::withMessages([
                    'stock' => ['Stock is already reserved for this order.'],
                ]);
            }

            $available = $this->stockService->availableQuantitiesForVariants($variantIds);
            $shortages = [];

            foreach ($items as $item) {
                $variantId = (int) $item->product_variant_id;
                $needed = (int) $item->quantity;
                $have = (int) ($available[$variantId] ?? 0);

                if ($have < $needed) {
                    $shortages[] = [
                        'order_item_id' => $item->id,
                        'product_variant_id' => $variantId,
                        'required' => $needed,
                        'available' => $have,
                    ];
                }
            }

            if ($shortages !== []) {
                throw ValidationException::withMessages([
                    'stock' => [
                        'Insufficient stock to send this order to print. No reservations were created.',
                    ],
                ]);
            }

            $reservationRows = [];

            foreach ($items as $item) {
                $reservation = StockReservation::query()->create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => (int) $item->quantity,
                    'status' => StockReservationStatus::ACTIVE,
                    'reserved_by' => $actorUserId,
                    'reserved_at' => now(),
                ]);

                $reservationRows[] = [
                    'reservation_id' => $reservation->id,
                    'order_item_id' => $item->id,
                    'product_variant_id' => (int) $item->product_variant_id,
                    'quantity' => (int) $item->quantity,
                ];

                // Consume from local available map so multi-line same-variant orders check correctly.
                $available[(int) $item->product_variant_id] -= (int) $item->quantity;
            }

            $fulfillment->update([
                'status' => SupplierFulfillmentStatus::PRINT,
                'updated_by' => $actorUserId,
            ]);

            $this->invoiceService->ensureInvoiceNumber($fulfillment->fresh());

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::STOCK_RESERVED,
                $actorUserId,
                ['reservations' => $reservationRows],
            );

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::MOVED_TO_PRINT,
                $actorUserId,
            );

            // Canonical order lifecycle: CONFIRMED → PRINT (sent to print queue; not yet printed).
            $this->orderStatusService->transition(
                $order,
                OrderStatus::PRINT,
                $actorUserId,
                null,
                'Sent to print by supplier',
            );

            return $fulfillment->fresh(['items', 'order.items']);
        });
    }

    /**
     * PRINT fulfillment → PACKAGING; canonical order lifecycle PRINTED → PACKING.
     * Requires prior PRINTED event (invoice actually printed) and print-ready shipment data.
     */
    public function sendToPackaging(Order $order, int $supplierId, ?int $actorUserId = null): OrderFulfillment
    {
        return DB::transaction(function () use ($order, $supplierId, $actorUserId) {
            $actorUserId ??= $supplierId;

            $order = Order::query()
                ->with(['fulfillment.items', 'shipment', 'fulfillmentEvents'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);

            if ($order->status !== OrderStatus::PRINTED) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Send to Packing is only valid from Printed. Current order status: '
                        .($order->status instanceof OrderStatus
                            ? $order->status->label()
                            : (string) $order->status)
                        .'.',
                    ],
                ]);
            }

            $fulfillment = $this->requireActiveFulfillment($order, SupplierFulfillmentStatus::PRINT);

            $this->invoiceService->assertPrintReady($order);

            if (! $this->hasPrintedEvent($order)) {
                throw ValidationException::withMessages([
                    'fulfillment' => [
                        'Only printed orders can move to Packaging. Print the invoice/waybill first.',
                    ],
                ]);
            }

            $fulfillment->update([
                'status' => SupplierFulfillmentStatus::PACKAGING,
                'updated_by' => $actorUserId,
            ]);

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::MOVED_TO_PACKAGING,
                $actorUserId,
            );

            // Canonical order lifecycle: PRINTED → PACKING.
            $this->orderStatusService->transition(
                $order,
                OrderStatus::PACKING,
                $actorUserId,
                null,
                'Sent to packing by supplier',
            );

            return $fulfillment->fresh(['items']);
        });
    }

    /**
     * Increment packed quantity by exactly 1 for the order item matching product_variants.barcode.
     */
    public function incrementPackedByBarcode(
        Order $order,
        string $productBarcode,
        int $supplierId,
        ?int $actorUserId = null,
    ): OrderFulfillmentItem {
        $barcode = trim($productBarcode);

        if ($barcode === '') {
            throw ValidationException::withMessages([
                'product_barcode' => ['A product barcode is required.'],
            ]);
        }

        return DB::transaction(function () use ($order, $barcode, $supplierId, $actorUserId) {
            $actorUserId ??= $supplierId;

            $order = Order::query()
                ->with(['items.variant', 'fulfillment.items'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);

            $fulfillment = $this->requireActiveFulfillment($order, SupplierFulfillmentStatus::PACKAGING);

            $orderItem = $order->items->first(
                fn ($item) => trim((string) ($item->variant?->barcode ?? '')) === $barcode
            );

            if ($orderItem === null) {
                throw ValidationException::withMessages([
                    'product_barcode' => ['Scanned product barcode does not belong to this order.'],
                ]);
            }

            $fulfillmentItem = $fulfillment->items->firstWhere('order_item_id', $orderItem->id);
            if ($fulfillmentItem === null) {
                throw ValidationException::withMessages([
                    'fulfillment_item' => ['Fulfillment item is missing for this order line.'],
                ]);
            }

            $previous = (int) $fulfillmentItem->packed_quantity;
            $nextPacked = $previous + 1;

            if ($nextPacked > (int) $orderItem->quantity) {
                throw ValidationException::withMessages([
                    'packed_quantity' => ['Cannot over-scan: packed quantity would exceed ordered quantity.'],
                ]);
            }

            $fulfillmentItem->update(['packed_quantity' => $nextPacked]);

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::PRODUCT_SCANNED,
                $actorUserId,
                [
                    'order_item_id' => (int) $orderItem->id,
                    'product_variant_id' => (int) $orderItem->product_variant_id,
                    'quantity' => 1,
                    'packed_quantity' => $nextPacked,
                    'barcode' => $barcode,
                ],
            );

            return $fulfillmentItem->fresh(['orderItem', 'variant']);
        });
    }

    public function hasPrintedEvent(Order $order): bool
    {
        return OrderFulfillmentEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', SupplierFulfillmentEventType::PRINTED->value)
            ->exists();
    }

    /**
     * Record a packed quantity for an order item (scanner API will call this later).
     * Invariant: 0 <= packed_quantity <= ordered_quantity.
     */
    public function setPackedQuantity(
        Order $order,
        int $orderItemId,
        int $packedQuantity,
        int $supplierId,
        ?int $actorUserId = null,
    ): OrderFulfillmentItem {
        return DB::transaction(function () use ($order, $orderItemId, $packedQuantity, $supplierId, $actorUserId) {
            $actorUserId ??= $supplierId;

            $order = Order::query()->with(['items', 'fulfillment.items'])->lockForUpdate()->findOrFail($order->id);
            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);

            $fulfillment = $this->requireActiveFulfillment($order, SupplierFulfillmentStatus::PACKAGING);

            $orderItem = $order->items->firstWhere('id', $orderItemId);
            if ($orderItem === null) {
                throw ValidationException::withMessages([
                    'order_item_id' => ['Order item not found on this order.'],
                ]);
            }

            if ($packedQuantity < 0 || $packedQuantity > (int) $orderItem->quantity) {
                throw ValidationException::withMessages([
                    'packed_quantity' => ['Packed quantity must be between 0 and the ordered quantity.'],
                ]);
            }

            $fulfillmentItem = $fulfillment->items->firstWhere('order_item_id', $orderItemId);
            if ($fulfillmentItem === null) {
                throw ValidationException::withMessages([
                    'fulfillment_item' => ['Fulfillment item is missing for this order line.'],
                ]);
            }

            $previous = (int) $fulfillmentItem->packed_quantity;
            $fulfillmentItem->update(['packed_quantity' => $packedQuantity]);

            $delta = $packedQuantity - $previous;
            if ($delta > 0) {
                $this->recordEvent(
                    $order,
                    $fulfillment,
                    SupplierFulfillmentEventType::PRODUCT_SCANNED,
                    $actorUserId,
                    [
                        'order_item_id' => $orderItemId,
                        'product_variant_id' => (int) $orderItem->product_variant_id,
                        'quantity' => $delta,
                        'packed_quantity' => $packedQuantity,
                    ],
                );
            }

            return $fulfillmentItem->fresh(['orderItem']);
        });
    }

    /**
     * PACKAGING → DISPATCHED with stock consumption. All-or-nothing.
     * Canonical order lifecycle: PACKING → DISPATCHED.
     */
    public function completePackaging(Order $order, int $supplierId, ?int $actorUserId = null): OrderFulfillment
    {
        return DB::transaction(function () use ($order, $supplierId, $actorUserId) {
            $actorUserId ??= $supplierId;

            $order = Order::query()
                ->with(['items', 'fulfillment.items', 'stockReservations'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);

            if ($order->status !== OrderStatus::PACKING) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Complete packing is only valid from Packing. Current order status: '
                        .($order->status instanceof OrderStatus
                            ? $order->status->label()
                            : (string) $order->status)
                        .'.',
                    ],
                ]);
            }

            $fulfillment = $this->requireActiveFulfillment($order, SupplierFulfillmentStatus::PACKAGING);

            $incomplete = [];
            foreach ($order->items as $item) {
                $fulfillmentItem = $fulfillment->items->firstWhere('order_item_id', $item->id);
                $packed = (int) ($fulfillmentItem?->packed_quantity ?? 0);

                if ($fulfillmentItem === null || $packed !== (int) $item->quantity) {
                    $incomplete[] = [
                        'order_item_id' => $item->id,
                        'ordered_quantity' => (int) $item->quantity,
                        'packed_quantity' => $packed,
                    ];
                }
            }

            if ($incomplete !== []) {
                throw ValidationException::withMessages([
                    'packed_quantity' => [
                        'All items must be fully packed before completing the package.',
                    ],
                ]);
            }

            $variantIds = $order->items->pluck('product_variant_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();

            $consumptionRows = [];

            foreach ($order->items as $item) {
                $reservation = StockReservation::query()
                    ->where('order_item_id', $item->id)
                    ->lockForUpdate()
                    ->first();

                if ($reservation === null
                    || $reservation->status !== StockReservationStatus::ACTIVE
                    || (int) $reservation->quantity !== (int) $item->quantity
                ) {
                    throw ValidationException::withMessages([
                        'stock' => [
                            'Active reservations matching ordered quantities are required before dispatch.',
                        ],
                    ]);
                }

                $consumption = StockConsumption::query()->create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'stock_reservation_id' => $reservation->id,
                    'quantity' => (int) $item->quantity,
                    'consumed_by' => $actorUserId,
                    'consumed_at' => now(),
                ]);

                $reservation->update([
                    'status' => StockReservationStatus::CONSUMED,
                    'consumed_at' => now(),
                ]);

                $consumptionRows[] = [
                    'consumption_id' => $consumption->id,
                    'reservation_id' => $reservation->id,
                    'order_item_id' => $item->id,
                    'product_variant_id' => (int) $item->product_variant_id,
                    'quantity' => (int) $item->quantity,
                ];
            }

            $fulfillment->update([
                'status' => SupplierFulfillmentStatus::DISPATCHED,
                'updated_by' => $actorUserId,
            ]);

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::PACKAGE_COMPLETED,
                $actorUserId,
            );

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::STOCK_CONSUMED,
                $actorUserId,
                ['consumptions' => $consumptionRows],
            );

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::DISPATCHED,
                $actorUserId,
            );

            // Canonical order lifecycle: PACKING → DISPATCHED.
            $this->orderStatusService->transition(
                $order,
                OrderStatus::DISPATCHED,
                $actorUserId,
                null,
                'Packing completed by supplier',
            );

            return $fulfillment->fresh(['items']);
        });
    }

    /**
     * Mark invoice/waybill as printed: PRINT → PRINTED.
     * Fulfillment stays PRINT until Send to Packing (existing Print UI workflow).
     * Idempotent when already PRINTED (reprint from Print page).
     */
    public function markPrinted(Order $order, int $supplierId, ?int $actorUserId = null): OrderFulfillment
    {
        return DB::transaction(function () use ($order, $supplierId, $actorUserId) {
            $actorUserId ??= $supplierId;

            $order = Order::query()->with(['fulfillment', 'shipment'])->lockForUpdate()->findOrFail($order->id);
            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);

            $fulfillment = $this->requireActiveFulfillment($order, SupplierFulfillmentStatus::PRINT);
            $this->invoiceService->assertPrintReady($order);
            $this->invoiceService->ensureInvoiceNumber($fulfillment);

            if ($order->status === OrderStatus::PRINTED) {
                if (! $this->hasPrintedEvent($order)) {
                    $this->recordEvent(
                        $order,
                        $fulfillment,
                        SupplierFulfillmentEventType::PRINTED,
                        $actorUserId,
                        [
                            'invoice_number' => $fulfillment->invoice_number,
                            'tracking_number' => $order->shipment?->tracking_number,
                        ],
                    );
                }

                return $fulfillment->fresh();
            }

            if ($order->status !== OrderStatus::PRINT) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only Print-queue orders can be marked as printed. Current order status: '
                        .($order->status instanceof OrderStatus
                            ? $order->status->label()
                            : (string) $order->status)
                        .'.',
                    ],
                ]);
            }

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::PRINTED,
                $actorUserId,
                [
                    'invoice_number' => $fulfillment->invoice_number,
                    'tracking_number' => $order->shipment?->tracking_number,
                ],
            );

            // Canonical order lifecycle: PRINT → PRINTED (invoice actually printed).
            $this->orderStatusService->transition(
                $order,
                OrderStatus::PRINTED,
                $actorUserId,
                null,
                'Invoice printed by supplier',
            );

            return $fulfillment->fresh();
        });
    }

    /**
     * Move eligible Fresh order to OUT_OF_STOCK (no reservation).
     */
    public function markOutOfStock(Order $order, int $supplierId, ?int $actorUserId = null): OrderFulfillment
    {
        return DB::transaction(function () use ($order, $supplierId, $actorUserId) {
            $actorUserId ??= $supplierId;

            $order = Order::query()->with(['items', 'fulfillment'])->lockForUpdate()->findOrFail($order->id);
            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);
            $this->assertConfirmedNewOrder($order);

            $fulfillment = $order->fulfillment;

            if ($fulfillment !== null && $fulfillment->isCancelled()) {
                throw ValidationException::withMessages([
                    'fulfillment' => ['This order fulfillment was cancelled.'],
                ]);
            }

            if ($fulfillment !== null
                && $fulfillment->status !== SupplierFulfillmentStatus::FRESH
                && $fulfillment->status !== SupplierFulfillmentStatus::OUT_OF_STOCK
            ) {
                throw ValidationException::withMessages([
                    'fulfillment' => [
                        'Only Fresh orders can be moved to Out of Stock. Current state: '.$fulfillment->status->label().'.',
                    ],
                ]);
            }

            if ($fulfillment === null) {
                $fulfillment = $this->createFulfillmentSkeleton(
                    $order,
                    SupplierFulfillmentStatus::OUT_OF_STOCK,
                    $actorUserId,
                );
            } else {
                $fulfillment->update([
                    'status' => SupplierFulfillmentStatus::OUT_OF_STOCK,
                    'updated_by' => $actorUserId,
                ]);
            }

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::OUT_OF_STOCK,
                $actorUserId,
            );

            return $fulfillment->fresh(['items']);
        });
    }

    /**
     * OUT_OF_STOCK → Fresh (clear fulfillment back to FRESH, still no reservation).
     */
    public function returnFromOutOfStock(Order $order, int $supplierId, ?int $actorUserId = null): OrderFulfillment
    {
        return DB::transaction(function () use ($order, $supplierId, $actorUserId) {
            $actorUserId ??= $supplierId;

            $order = Order::query()->with(['fulfillment'])->lockForUpdate()->findOrFail($order->id);
            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);

            $fulfillment = $this->requireActiveFulfillment($order, SupplierFulfillmentStatus::OUT_OF_STOCK);

            $fulfillment->update([
                'status' => SupplierFulfillmentStatus::FRESH,
                'updated_by' => $actorUserId,
            ]);

            $this->recordEvent(
                $order,
                $fulfillment,
                SupplierFulfillmentEventType::FRESH,
                $actorUserId,
                ['returned_from' => SupplierFulfillmentStatus::OUT_OF_STOCK->value],
            );

            return $fulfillment->fresh(['items']);
        });
    }

    /**
     * Cancel an order at any lifecycle status before DISPATCHED.
     * Releases reservations and cancels fulfillment via OrderStatusService.
     */
    public function cancel(Order $order, int $supplierId, ?int $actorUserId = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $supplierId, $actorUserId, $reason) {
            $actorUserId ??= $supplierId;

            $order = Order::query()
                ->with(['fulfillment', 'stockReservations'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->authorizationGuard->assertSupplierOrderAccess($order, $supplierId);

            $status = $order->status instanceof OrderStatus
                ? $order->status
                : OrderStatus::tryFrom((string) $order->status);

            if ($status === null || ! $status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => [
                        $status === OrderStatus::DISPATCHED
                            ? 'Dispatched orders cannot be cancelled.'
                            : 'This order cannot be cancelled.',
                    ],
                ]);
            }

            $fulfillment = $order->fulfillment;

            if ($fulfillment !== null && $fulfillment->isCancelled()) {
                throw ValidationException::withMessages([
                    'fulfillment' => ['This order fulfillment is already cancelled.'],
                ]);
            }

            $wasOutOfStock = $fulfillment?->status === SupplierFulfillmentStatus::OUT_OF_STOCK;

            if ($fulfillment !== null) {
                $this->recordEvent(
                    $order,
                    $fulfillment,
                    $wasOutOfStock
                        ? SupplierFulfillmentEventType::CANCELLED_OUT_OF_STOCK
                        : SupplierFulfillmentEventType::CANCELLED,
                    $actorUserId,
                    ['reason' => $reason],
                );
            }

            // OrderStatusService enforces canCancel, releases stock, and cancels fulfillment.
            return $this->orderStatusService->transition(
                $order,
                OrderStatus::CANCELLED,
                $actorUserId,
                null,
                $reason ?? 'Cancelled by supplier',
            );
        });
    }

    public function canCancel(Order $order, int $supplierId): bool
    {
        if ((int) $order->supplier_id !== $supplierId) {
            return false;
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status === null || ! $status->canCancel()) {
            return false;
        }

        $fulfillment = $order->fulfillment;

        if ($fulfillment !== null && $fulfillment->isCancelled()) {
            return false;
        }

        return true;
    }

    private function createFulfillmentSkeleton(
        Order $order,
        SupplierFulfillmentStatus $status,
        int $actorUserId,
    ): OrderFulfillment {
        $fulfillment = OrderFulfillment::query()->create([
            'order_id' => $order->id,
            'status' => $status,
            'invoice_number' => $order->order_number,
            'invoice_generated_at' => now(),
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);

        foreach ($order->items as $item) {
            OrderFulfillmentItem::query()->create([
                'order_fulfillment_id' => $fulfillment->id,
                'order_item_id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'packed_quantity' => 0,
            ]);
        }

        return $fulfillment->fresh(['items']);
    }

    private function requireActiveFulfillment(
        Order $order,
        SupplierFulfillmentStatus $expected,
    ): OrderFulfillment {
        $fulfillment = $order->fulfillment;

        if ($fulfillment === null || $fulfillment->isCancelled()) {
            throw ValidationException::withMessages([
                'fulfillment' => ['Order has no active supplier fulfillment record.'],
            ]);
        }

        if ($fulfillment->status !== $expected) {
            throw ValidationException::withMessages([
                'fulfillment' => [
                    'Expected fulfillment state '.$expected->label()
                    .', current state is '.$fulfillment->status->label().'.',
                ],
            ]);
        }

        return $fulfillment;
    }

    private function assertConfirmedNewOrder(Order $order): void
    {
        if ($order->status !== OrderStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'status' => ['Only confirmed orders can enter supplier fulfillment.'],
            ]);
        }

        if ($order->order_type !== OrderType::NEW) {
            throw ValidationException::withMessages([
                'order_type' => ['Exchange orders are not supported in Phase 2A fulfillment.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordEvent(
        Order $order,
        OrderFulfillment $fulfillment,
        SupplierFulfillmentEventType $type,
        ?int $actorUserId,
        ?array $metadata = null,
    ): OrderFulfillmentEvent {
        return OrderFulfillmentEvent::query()->create([
            'order_id' => $order->id,
            'order_fulfillment_id' => $fulfillment->id,
            'event_type' => $type,
            'actor_user_id' => $actorUserId,
            'metadata' => $metadata,
        ]);
    }
}
