<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\OrderType;
use Feeder\Core\Enums\SupplierFulfillmentStatus;
use Feeder\Core\Exceptions\SupplierFulfillmentException;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderFulfillment;
use Feeder\Core\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Query helpers for supplier fulfillment lists (Fresh / Print / Packaging / OOS).
 * No UI — domain queries only.
 */
class SupplierFulfillmentQueryService
{
    public function __construct(
        private readonly SupplierOrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * Fresh eligibility:
     * CONFIRMED + NEW + owned by supplier + not cancelled fulfillment +
     * (no fulfillment OR fulfillment.status = FRESH).
     */
    public function freshOrdersQuery(int $supplierId): Builder
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        return Order::query()
            ->where('supplier_id', $supplierId)
            ->where('status', OrderStatus::CONFIRMED->value)
            ->where('order_type', OrderType::NEW->value)
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('fulfillment')
                    ->orWhereHas('fulfillment', function (Builder $fulfillment): void {
                        $fulfillment->active()
                            ->where('status', SupplierFulfillmentStatus::FRESH->value);
                    });
            })
            ->orderBy('confirmed_at')
            ->orderBy('id');
    }

    /**
     * @return Collection<int, Order>
     */
    public function freshOrders(int $supplierId): Collection
    {
        return $this->freshOrdersQuery($supplierId)->with(['items', 'fulfillment'])->get();
    }

    /**
     * Dispatch Orders queue: supplier-owned orders in DISPATCHED or RETURNED lifecycle status.
     */
    public function dispatchOrdersQuery(int $supplierId): Builder
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        return Order::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', [
                OrderStatus::DISPATCHED->value,
                OrderStatus::RETURNED->value,
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /**
     * Apply Dispatch Orders list filters (search / status / courier / dispatch-date range).
     *
     * Search includes order number, customer, phones, waybill, and invoice number.
     * Date range is evaluated against the order's DISPATCHED status-history timestamp.
     *
     * @param  array{
     *     search?: string,
     *     status?: string|null,
     *     courier_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null
     * }  $filters
     */
    public function applyDispatchListFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('order_number', 'like', '%'.$search.'%')
                    ->orWhere('customer_name_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('primary_phone_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('secondary_phone_snapshot', 'like', '%'.$search.'%')
                    ->orWhereHas('shipment', function (Builder $shipment) use ($search): void {
                        $shipment->where('tracking_number', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('fulfillment', function (Builder $fulfillment) use ($search): void {
                        $fulfillment->where('invoice_number', 'like', '%'.$search.'%');
                    });
            });
        }

        $status = strtoupper(trim((string) ($filters['status'] ?? '')));
        if ($status === OrderStatus::DISPATCHED->value || $status === OrderStatus::RETURNED->value) {
            $query->where('status', $status);
        }

        $courierId = isset($filters['courier_id']) ? (int) $filters['courier_id'] : 0;
        if ($courierId > 0) {
            $query->whereHas('shipment', function (Builder $shipment) use ($courierId): void {
                $shipment->where('courier_id', $courierId);
            });
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $query->whereExists(function ($sub) use ($dateFrom): void {
                $sub->selectRaw('1')
                    ->from('order_status_histories as osh_dispatch_from')
                    ->whereColumn('osh_dispatch_from.order_id', 'orders.id')
                    ->where('osh_dispatch_from.to_status', OrderStatus::DISPATCHED->value)
                    ->whereDate('osh_dispatch_from.created_at', '>=', $dateFrom);
            });
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $query->whereExists(function ($sub) use ($dateTo): void {
                $sub->selectRaw('1')
                    ->from('order_status_histories as osh_dispatch_to')
                    ->whereColumn('osh_dispatch_to.order_id', 'orders.id')
                    ->where('osh_dispatch_to.to_status', OrderStatus::DISPATCHED->value)
                    ->whereDate('osh_dispatch_to.created_at', '<=', $dateTo);
            });
        }

        return $query;
    }

    public function ordersInStatusQuery(int $supplierId, SupplierFulfillmentStatus $status): Builder
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $query = Order::query()
            ->where('supplier_id', $supplierId);

        // Print / Packing queues are driven by the canonical OrderStatus lifecycle.
        // Warehouse fulfillment rows remain for reservations, invoices, and packing scans.
        //
        // Print page: PRINT (awaiting invoice print) and PRINTED (invoice printed, still
        // fulfillment PRINT until Send to Packing — preserves existing Print UI).
        // Packing page: PACKING (and PRINTED only appears here after Send to Packing
        // moves fulfillment to PACKAGING; precursor PRINTED stays on Print until then).
        $orderStatuses = match ($status) {
            SupplierFulfillmentStatus::PRINT => [
                OrderStatus::PRINT->value,
                OrderStatus::PRINTED->value,
            ],
            SupplierFulfillmentStatus::PACKAGING => [
                OrderStatus::PACKING->value,
            ],
            default => null,
        };

        if ($orderStatuses !== null) {
            return $query
                ->whereIn('status', $orderStatuses)
                ->whereHas('fulfillment', function (Builder $fulfillment) use ($status): void {
                    $fulfillment->active()->where('status', $status->value);
                })
                ->orderBy('confirmed_at')
                ->orderBy('id');
        }

        return $query
            ->where('status', OrderStatus::CONFIRMED->value)
            ->whereHas('fulfillment', function (Builder $fulfillment) use ($status): void {
                $fulfillment->active()->where('status', $status->value);
            })
            ->orderBy('confirmed_at')
            ->orderBy('id');
    }

    public function isFreshEligible(Order $order, int $supplierId): bool
    {
        if ((int) $order->supplier_id !== $supplierId) {
            return false;
        }

        if ($order->status !== OrderStatus::CONFIRMED) {
            return false;
        }

        if ($order->order_type !== OrderType::NEW) {
            return false;
        }

        $fulfillment = $order->fulfillment;

        if ($fulfillment === null) {
            return true;
        }

        return $fulfillment->isActive()
            && $fulfillment->status === SupplierFulfillmentStatus::FRESH;
    }

    public function activeFulfillment(Order $order): ?OrderFulfillment
    {
        $fulfillment = $order->fulfillment;

        if ($fulfillment === null || $fulfillment->isCancelled()) {
            return null;
        }

        return $fulfillment;
    }

    /**
     * Apply shared list filters (search / product / courier / date range) to a supplier order query.
     *
     * @param  array{
     *     search?: string,
     *     product_id?: int|null,
     *     courier_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     has_warranty?: bool|null
     * }  $filters
     */
    public function applyListFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('order_number', 'like', '%'.$search.'%')
                    ->orWhere('customer_name_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('primary_phone_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('secondary_phone_snapshot', 'like', '%'.$search.'%');
            });
        }

        $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : 0;
        if ($productId > 0) {
            $query->whereHas('items', function (Builder $items) use ($productId): void {
                $items->where('product_id', $productId);
            });
        }

        if (! empty($filters['has_warranty'])) {
            $query->whereHas('items.product', function (Builder $products): void {
                $products->withWarranty();
            });
        }

        $courierId = isset($filters['courier_id']) ? (int) $filters['courier_id'] : 0;
        if ($courierId > 0) {
            // Fresh orders keep courier on draft_courier_id until shipment booking.
            // Print/Packing orders use shipment.courier_id. Match either.
            $query->where(function (Builder $inner) use ($courierId): void {
                $inner->where('draft_courier_id', $courierId)
                    ->orWhereHas('shipment', function (Builder $shipment) use ($courierId): void {
                        $shipment->where('courier_id', $courierId);
                    });
            });
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $query->whereDate('confirmed_at', '>=', $dateFrom);
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $query->whereDate('confirmed_at', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * Locate a supplier-owned order by Feeder Order ID (order_number), legacy ORD-*, or UUID.
     * Never resolves pure-numeric references as orders.id — commercial IDs are numeric.
     */
    public function findOwnedOrder(int $supplierId, string|int $orderReference): ?Order
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $reference = trim((string) $orderReference);

        if ($reference === '') {
            return null;
        }

        return Order::query()
            ->where('supplier_id', $supplierId)
            ->where(function (Builder $inner) use ($reference): void {
                $inner->where('order_number', $reference)
                    ->orWhere('uuid', $reference)
                    ->orWhere('legacy_order_number', $reference);
            })
            ->first();
    }

    /**
     * Locate a supplier-owned order by shipment tracking / waybill number.
     *
     * Tracking numbers are not globally unique. Matches are always supplier-scoped.
     * Zero matches → null. Multiple matches → explicit ambiguity error (never first()).
     *
     * @throws SupplierFulfillmentException When more than one owned shipment matches.
     */
    public function findOwnedOrderByTrackingNumber(int $supplierId, string $trackingNumber): ?Order
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $tracking = trim($trackingNumber);

        if ($tracking === '') {
            return null;
        }

        $orderIds = Shipment::query()
            ->where('supplier_id', $supplierId)
            ->where('tracking_number', $tracking)
            ->limit(2)
            ->pluck('order_id');

        if ($orderIds->isEmpty()) {
            return null;
        }

        if ($orderIds->count() > 1) {
            throw new SupplierFulfillmentException(
                'AMBIGUOUS_TRACKING_NUMBER',
                'Multiple orders found for this waybill. Please scan the correct order identifier or contact support.',
            );
        }

        return Order::query()
            ->where('supplier_id', $supplierId)
            ->whereKey($orderIds->first())
            ->first();
    }
}
