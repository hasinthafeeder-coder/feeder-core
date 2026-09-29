<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\StockReservationStatus;
use Feeder\Core\Models\GoodsReceivedNoteItem;
use Feeder\Core\Models\StockConsumption;
use Feeder\Core\Models\StockReservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Available stock = received - damaged - reserved(active) - consumed.
     *
     * Preserves the historical remainingStock* API names used by stock list pages.
     */
    public function remainingStockForVariant(int $variantId): int
    {
        return $this->availableQuantityForVariant($variantId);
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function remainingStockForVariants(array $variantIds): array
    {
        return $this->availableQuantitiesForVariants($variantIds);
    }

    public function receivedQuantityForVariant(int $variantId): int
    {
        return $this->receivedQuantitiesForVariants([$variantId])[$variantId] ?? 0;
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function receivedQuantitiesForVariants(array $variantIds): array
    {
        return $this->grnAggregateForVariants($variantIds, 'received_quantity');
    }

    public function damagedQuantityForVariant(int $variantId): int
    {
        return $this->damagedQuantitiesForVariants([$variantId])[$variantId] ?? 0;
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function damagedQuantitiesForVariants(array $variantIds): array
    {
        return $this->grnAggregateForVariants($variantIds, 'damaged_quantity');
    }

    public function reservedQuantityForVariant(int $variantId): int
    {
        return $this->reservedQuantitiesForVariants([$variantId])[$variantId] ?? 0;
    }

    /**
     * Active reservations only.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function reservedQuantitiesForVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $totals = StockReservation::query()
            ->select('product_variant_id')
            ->selectRaw('COALESCE(SUM(quantity), 0) as reserved_quantity')
            ->where('status', StockReservationStatus::ACTIVE->value)
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->pluck('reserved_quantity', 'product_variant_id');

        return $this->fillMissingVariantTotals($variantIds, $totals);
    }

    public function consumedQuantityForVariant(int $variantId): int
    {
        return $this->consumedQuantitiesForVariants([$variantId])[$variantId] ?? 0;
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function consumedQuantitiesForVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $totals = StockConsumption::query()
            ->select('product_variant_id')
            ->selectRaw('COALESCE(SUM(quantity), 0) as consumed_quantity')
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->pluck('consumed_quantity', 'product_variant_id');

        return $this->fillMissingVariantTotals($variantIds, $totals);
    }

    public function availableQuantityForVariant(int $variantId): int
    {
        return $this->availableQuantitiesForVariants([$variantId])[$variantId] ?? 0;
    }

    /**
     * AVAILABLE = RECEIVED - DAMAGED - RESERVED - CONSUMED
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function availableQuantitiesForVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $physical = $this->physicalAvailableQuantitiesForVariants($variantIds);
        $reserved = $this->reservedQuantitiesForVariants($variantIds);
        $consumed = $this->consumedQuantitiesForVariants($variantIds);

        $available = [];

        foreach ($variantIds as $variantId) {
            $available[$variantId] = max(
                0,
                (int) ($physical[$variantId] ?? 0)
                - (int) ($reserved[$variantId] ?? 0)
                - (int) ($consumed[$variantId] ?? 0)
            );
        }

        return $available;
    }

    /**
     * Alias for available quantity — stock that can still be reserved.
     */
    public function reservableQuantityForVariant(int $variantId): int
    {
        return $this->availableQuantityForVariant($variantId);
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function reservableQuantitiesForVariants(array $variantIds): array
    {
        return $this->availableQuantitiesForVariants($variantIds);
    }

    /**
     * Physical on-hand before reservation/consumption: RECEIVED - DAMAGED.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function physicalAvailableQuantitiesForVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $totals = GoodsReceivedNoteItem::query()
            ->select('goods_received_note_items.product_variant_id')
            ->selectRaw(
                'COALESCE(SUM(goods_received_note_items.received_quantity - goods_received_note_items.damaged_quantity), 0) as physical_stock'
            )
            ->join('goods_received_notes', 'goods_received_notes.id', '=', 'goods_received_note_items.grn_id')
            ->whereNull('goods_received_notes.deleted_at')
            ->whereIn('goods_received_note_items.product_variant_id', $variantIds)
            ->groupBy('goods_received_note_items.product_variant_id')
            ->pluck('physical_stock', 'product_variant_id');

        return $this->fillMissingVariantTotals($variantIds, $totals);
    }

    /**
     * Full stock breakdown for a variant.
     *
     * @return array{received: int, damaged: int, reserved: int, consumed: int, available: int}
     */
    public function stockBreakdownForVariant(int $variantId): array
    {
        $received = $this->receivedQuantityForVariant($variantId);
        $damaged = $this->damagedQuantityForVariant($variantId);
        $reserved = $this->reservedQuantityForVariant($variantId);
        $consumed = $this->consumedQuantityForVariant($variantId);

        return [
            'received' => $received,
            'damaged' => $damaged,
            'reserved' => $reserved,
            'consumed' => $consumed,
            'available' => max(0, $received - $damaged - $reserved - $consumed),
        ];
    }

    /**
     * Correlated scalar subquery: available stock for the current product_variants row.
     * Used by StockListService filters/list pages.
     *
     * AVAILABLE = RECEIVED - DAMAGED - RESERVED - CONSUMED
     */
    public function remainingStockSubquery(): QueryBuilder
    {
        return DB::query()->selectRaw(
            'GREATEST(
                0,
                COALESCE((
                    SELECT SUM(grni.received_quantity - grni.damaged_quantity)
                    FROM goods_received_note_items AS grni
                    INNER JOIN goods_received_notes AS grn ON grn.id = grni.grn_id
                    WHERE grn.deleted_at IS NULL
                      AND grni.product_variant_id = product_variants.id
                ), 0)
                - COALESCE((
                    SELECT SUM(sr.quantity)
                    FROM stock_reservations AS sr
                    WHERE sr.status = ?
                      AND sr.product_variant_id = product_variants.id
                ), 0)
                - COALESCE((
                    SELECT SUM(sc.quantity)
                    FROM stock_consumptions AS sc
                    WHERE sc.product_variant_id = product_variants.id
                ), 0)
            )',
            [StockReservationStatus::ACTIVE->value]
        );
    }

    /**
     * @param  'in_stock'|'out_of_stock'|''  $stockStatus
     */
    public function applyStockStatusFilter(Builder $query, string $stockStatus): Builder
    {
        if ($stockStatus === '') {
            return $query;
        }

        $subquery = $this->remainingStockSubquery();
        $operator = $stockStatus === 'in_stock' ? '>' : '<=';

        return $query->whereRaw(
            '('.$subquery->toSql().") {$operator} 0",
            $subquery->getBindings()
        );
    }

    /**
     * @param  list<int>  $variantIds
     * @param  Collection<int|string, mixed>  $totals
     * @return array<int, int>
     */
    private function fillMissingVariantTotals(array $variantIds, Collection $totals): array
    {
        $stock = [];

        foreach ($variantIds as $variantId) {
            $stock[$variantId] = max(0, (int) ($totals[$variantId] ?? 0));
        }

        return $stock;
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    private function grnAggregateForVariants(array $variantIds, string $column): array
    {
        if ($variantIds === []) {
            return [];
        }

        if (! in_array($column, ['received_quantity', 'damaged_quantity'], true)) {
            throw new \InvalidArgumentException('Unsupported GRN aggregate column.');
        }

        $totals = GoodsReceivedNoteItem::query()
            ->select('goods_received_note_items.product_variant_id')
            ->selectRaw("COALESCE(SUM(goods_received_note_items.{$column}), 0) as aggregate_quantity")
            ->join('goods_received_notes', 'goods_received_notes.id', '=', 'goods_received_note_items.grn_id')
            ->whereNull('goods_received_notes.deleted_at')
            ->whereIn('goods_received_note_items.product_variant_id', $variantIds)
            ->groupBy('goods_received_note_items.product_variant_id')
            ->pluck('aggregate_quantity', 'product_variant_id');

        return $this->fillMissingVariantTotals($variantIds, $totals);
    }
}
