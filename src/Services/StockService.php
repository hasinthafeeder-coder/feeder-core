<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\GoodsReceivedNoteItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Remaining stock = received - damaged - consumed.
     * Consumption is not implemented yet, so only GRN quantities apply.
     */
    public function remainingStockForVariant(int $variantId): int
    {
        $stock = $this->remainingStockForVariants([$variantId]);

        return $stock[$variantId] ?? 0;
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function remainingStockForVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $totals = GoodsReceivedNoteItem::query()
            ->select('goods_received_note_items.product_variant_id')
            ->selectRaw(
                'COALESCE(SUM(goods_received_note_items.received_quantity - goods_received_note_items.damaged_quantity), 0) as remaining_stock'
            )
            ->join('goods_received_notes', 'goods_received_notes.id', '=', 'goods_received_note_items.grn_id')
            ->whereNull('goods_received_notes.deleted_at')
            ->whereIn('goods_received_note_items.product_variant_id', $variantIds)
            ->groupBy('goods_received_note_items.product_variant_id')
            ->pluck('remaining_stock', 'product_variant_id');

        $stock = [];

        foreach ($variantIds as $variantId) {
            $stock[$variantId] = max(0, (int) ($totals[$variantId] ?? 0));
        }

        return $stock;
    }

    /**
     * Subquery that returns remaining stock for the current product_variants row.
     */
    public function remainingStockSubquery(): Builder
    {
        return GoodsReceivedNoteItem::query()
            ->selectRaw(
                'COALESCE(SUM(goods_received_note_items.received_quantity - goods_received_note_items.damaged_quantity), 0)'
            )
            ->join('goods_received_notes', 'goods_received_notes.id', '=', 'goods_received_note_items.grn_id')
            ->whereNull('goods_received_notes.deleted_at')
            ->whereColumn('goods_received_note_items.product_variant_id', 'product_variants.id');
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
}
