<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\SupplierFulfillmentStatus;
use Feeder\Core\Models\Order;
use Feeder\Core\Services\StockService;
use Illuminate\Support\Collection;

/**
 * FIFO out-of-stock evaluation for Fresh orders.
 *
 * Fresh orders do not reserve stock. Earlier confirmed Fresh orders
 * (confirmed_at ASC) claim physical-available capacity before later ones.
 *
 * This service evaluates only — it does not auto-mark or notify.
 */
class SupplierFulfillmentStockEvaluationService
{
    public function __construct(
        private readonly SupplierFulfillmentQueryService $queryService,
        private readonly StockService $stockService,
        private readonly SupplierOrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * @return Collection<int, array{
     *     order: Order,
     *     is_out_of_stock: bool,
     *     shortages: list<array{product_variant_id: int, required: int, available_after_fifo: int}>
     * }>
     */
    public function evaluateFreshOrdersForSupplier(int $supplierId): Collection
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $orders = $this->queryService->freshOrders($supplierId);

        return $this->evaluateFreshOrderCollection($orders);
    }

    /**
     * Re-evaluate Fresh orders that contain any of the given variants.
     *
     * @param  list<int>  $variantIds
     * @return Collection<int, array{
     *     order: Order,
     *     is_out_of_stock: bool,
     *     shortages: list<array{product_variant_id: int, required: int, available_after_fifo: int}>
     * }>
     */
    public function evaluateFreshOrdersAffectedByVariants(int $supplierId, array $variantIds): Collection
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));

        if ($variantIds === []) {
            return collect();
        }

        $orders = $this->queryService->freshOrdersQuery($supplierId)
            ->whereHas('items', fn ($query) => $query->whereIn('product_variant_id', $variantIds))
            ->with(['items', 'fulfillment'])
            ->get();

        return $this->evaluateFreshOrderCollection($orders);
    }

    /**
     * Whether a single Fresh-eligible order would be out of stock under FIFO.
     */
    public function isOrderOutOfStockByFifo(Order $order, int $supplierId): bool
    {
        if (! $this->queryService->isFreshEligible($order, $supplierId)) {
            return false;
        }

        $results = $this->evaluateFreshOrdersAffectedByVariants(
            $supplierId,
            $order->items->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all(),
        );

        $match = $results->first(
            fn (array $row) => (int) $row['order']->id === (int) $order->id
        );

        return (bool) ($match['is_out_of_stock'] ?? false);
    }

    /**
     * @param  Collection<int, Order>  $orders  Must already be FIFO-ordered (confirmed_at ASC).
     * @return Collection<int, array{
     *     order: Order,
     *     is_out_of_stock: bool,
     *     shortages: list<array{product_variant_id: int, required: int, available_after_fifo: int}>
     * }>
     */
    public function evaluateFreshOrderCollection(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        $variantIds = $orders
            ->flatMap(fn (Order $order) => $order->items->pluck('product_variant_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        // Physical available already excludes active reservations + consumptions.
        $remaining = $this->stockService->availableQuantitiesForVariants($variantIds);

        $results = collect();

        foreach ($orders as $order) {
            $shortages = [];
            $canFulfill = true;

            // Snapshot remaining before claiming so shortage reporting is accurate.
            $projected = $remaining;

            foreach ($order->items as $item) {
                $variantId = (int) $item->product_variant_id;
                $required = (int) $item->quantity;
                $available = (int) ($projected[$variantId] ?? 0);

                if ($available < $required) {
                    $canFulfill = false;
                    $shortages[] = [
                        'product_variant_id' => $variantId,
                        'required' => $required,
                        'available_after_fifo' => max(0, $available),
                    ];
                }
            }

            if ($canFulfill) {
                foreach ($order->items as $item) {
                    $variantId = (int) $item->product_variant_id;
                    $remaining[$variantId] = (int) ($remaining[$variantId] ?? 0) - (int) $item->quantity;
                }
            }

            $results->push([
                'order' => $order,
                'is_out_of_stock' => ! $canFulfill,
                'shortages' => $shortages,
            ]);
        }

        return $results;
    }

    /**
     * Fresh-eligible orders that currently pass FIFO stock evaluation.
     *
     * Used by the Fresh New Orders list — OOS-by-FIFO orders are shown only
     * under Out of Stock. Does not mutate fulfillment status.
     *
     * @return Collection<int, array{
     *     order: Order,
     *     is_out_of_stock: bool,
     *     shortages: list<array{product_variant_id: int, required: int, available_after_fifo: int}>
     * }>
     */
    public function evaluateInStockFreshOrders(int $supplierId): Collection
    {
        return $this->evaluateFreshOrdersForSupplier($supplierId)
            ->filter(fn (array $row) => ! (bool) $row['is_out_of_stock'])
            ->values();
    }

    /**
     * Fresh-eligible orders that currently fail FIFO stock evaluation.
     *
     * Out of Stock on New Orders is a derived view of Fresh-eligible orders —
     * it does not mutate fulfillment status.
     *
     * @return Collection<int, array{
     *     order: Order,
     *     is_out_of_stock: bool,
     *     shortages: list<array{product_variant_id: int, required: int, available_after_fifo: int}>
     * }>
     */
    public function evaluateOutOfStockFreshOrders(int $supplierId): Collection
    {
        return $this->evaluateFreshOrdersForSupplier($supplierId)
            ->filter(fn (array $row) => (bool) $row['is_out_of_stock'])
            ->values();
    }

    /**
     * Orders currently marked OUT_OF_STOCK that would now be fulfillable under FIFO.
     *
     * Retained for domain transitions that still use the OUT_OF_STOCK fulfillment
     * status (cancel / return-to-fresh). New Orders list visibility uses
     * {@see evaluateOutOfStockFreshOrders()} instead.
     *
     * @return Collection<int, Order>
     */
    public function outOfStockOrdersThatCanReturnToFresh(int $supplierId): Collection
    {
        $oosOrders = $this->queryService
            ->ordersInStatusQuery($supplierId, SupplierFulfillmentStatus::OUT_OF_STOCK)
            ->with(['items', 'fulfillment'])
            ->get();

        if ($oosOrders->isEmpty()) {
            return collect();
        }

        // Evaluate as if they were competing with current Fresh pool + themselves by confirmed_at.
        $fresh = $this->queryService->freshOrders($supplierId);
        $combined = $fresh->concat($oosOrders)
            ->sortBy([
                fn (Order $order) => $order->confirmed_at?->timestamp ?? 0,
                fn (Order $order) => $order->id,
            ])
            ->values();

        $evaluation = $this->evaluateFreshOrderCollection($combined);
        $oosIds = $oosOrders->pluck('id')->all();

        return $evaluation
            ->filter(fn (array $row) => in_array((int) $row['order']->id, $oosIds, true) && ! $row['is_out_of_stock'])
            ->map(fn (array $row) => $row['order'])
            ->values();
    }
}
