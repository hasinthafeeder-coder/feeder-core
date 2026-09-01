<?php

namespace Feeder\Core\Services;

use Carbon\Carbon;
use Feeder\Core\Models\GoodsReceivedNote;
use Feeder\Core\Models\GoodsReceivedNoteItem;
use Feeder\Core\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceivedNoteService
{
    public function createGrn(
        int $supplierId,
        array $headerData,
        array $items,
        ?int $invoiceFileId = null,
    ): GoodsReceivedNote {
        return DB::transaction(function () use ($supplierId, $headerData, $items, $invoiceFileId) {
            $grn = GoodsReceivedNote::query()->create([
                'supplier_id' => $supplierId,
                'invoice_number' => $headerData['invoice_number'] ?? null,
                'invoice_file_id' => $invoiceFileId,
                'received_date' => $headerData['received_date'],
                'notes' => $headerData['notes'] ?? null,
                'created_by' => $headerData['created_by'] ?? $supplierId,
                'updated_by' => $headerData['updated_by'] ?? $supplierId,
            ]);

            $grn->update([
                'grn_number' => $this->generateGrnNumber(
                    $grn->id,
                    Carbon::parse($headerData['received_date'])
                ),
            ]);

            $this->syncItems($grn, $items, $supplierId);

            return $grn->fresh($this->defaultRelations());
        });
    }

    public function updateGrn(
        GoodsReceivedNote $grn,
        array $headerData,
        array $items,
        ?int $invoiceFileId = null,
        bool $replaceInvoiceFile = false,
    ): GoodsReceivedNote {
        return DB::transaction(function () use ($grn, $headerData, $items, $invoiceFileId, $replaceInvoiceFile) {
            $updateData = [
                'invoice_number' => $headerData['invoice_number'] ?? null,
                'received_date' => $headerData['received_date'],
                'notes' => $headerData['notes'] ?? null,
                'updated_by' => $headerData['updated_by'] ?? $grn->supplier_id,
            ];

            if ($replaceInvoiceFile) {
                $updateData['invoice_file_id'] = $invoiceFileId;
            }

            $grn->update($updateData);

            $grn->items()->delete();
            $this->syncItems($grn, $items, (int) $grn->supplier_id);

            return $grn->fresh($this->defaultRelations());
        });
    }

    /**
     * Soft-delete the GRN. Stock reversal will be added in a future phase.
     */
    public function deleteGrn(GoodsReceivedNote $grn): void
    {
        DB::transaction(function () use ($grn) {
            $grn->delete();
        });
    }

    public function generateGrnNumber(int $id, ?Carbon $referenceDate = null): string
    {
        $year = ($referenceDate ?? now())->year;

        return sprintf('GRN-%d-%06d', $year, $id);
    }

    /**
     * @param  list<array{
     *     product_id: int,
     *     product_variant_id: int,
     *     received_quantity: int,
     *     damaged_quantity: int,
     *     unit_cost: float|string,
     *     notes?: string|null
     * }>  $items
     */
    public function validateSupplierProductOwnership(int $supplierId, array $items): void
    {
        foreach ($items as $index => $item) {
            $variant = ProductVariant::query()
                ->with('product')
                ->find($item['product_variant_id'] ?? null);

            if ($variant === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => ['The selected product variant is invalid.'],
                ]);
            }

            if ((int) ($item['product_id'] ?? 0) !== (int) $variant->product_id) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => ['The selected variant does not belong to the selected product.'],
                ]);
            }

            if ((int) $variant->product?->supplier_id !== $supplierId) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => ['You are not allowed to use this product variant.'],
                ]);
            }
        }
    }

    /**
     * @param  list<array{
     *     product_id: int,
     *     product_variant_id: int,
     *     received_quantity: int,
     *     damaged_quantity: int,
     *     unit_cost: float|string,
     *     notes?: string|null
     * }>  $items
     */
    protected function syncItems(GoodsReceivedNote $grn, array $items, int $supplierId): void
    {
        $this->validateSupplierProductOwnership($supplierId, $items);

        $variantIds = collect($items)->pluck('product_variant_id');

        if ($variantIds->count() !== $variantIds->unique()->count()) {
            throw ValidationException::withMessages([
                'items' => ['Each product variant can only appear once in a GRN.'],
            ]);
        }

        foreach ($items as $item) {
            $variant = ProductVariant::query()
                ->with('product')
                ->findOrFail($item['product_variant_id']);

            GoodsReceivedNoteItem::query()->create([
                'grn_id' => $grn->id,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'received_quantity' => (int) $item['received_quantity'],
                'damaged_quantity' => (int) ($item['damaged_quantity'] ?? 0),
                'unit_cost' => $item['unit_cost'],
                'product_name_snapshot' => $variant->product->name,
                'variant_name_snapshot' => $variant->name,
                'notes' => $item['notes'] ?? null,
            ]);

            $this->updateVariantCostIfChanged($variant, $item['unit_cost'], $supplierId);
        }
    }

    protected function updateVariantCostIfChanged(ProductVariant $variant, float|string $unitCost, int $updatedBy): void
    {
        if (bccomp((string) $variant->cost, (string) $unitCost, 2) === 0) {
            return;
        }

        $variant->update([
            'cost' => $unitCost,
            'updated_by' => $updatedBy,
        ]);
    }

    /**
     * @return list<string>
     */
    protected function defaultRelations(): array
    {
        return [
            'items',
            'supplier.company',
            'invoiceFile',
            'creator',
            'updater',
        ];
    }
}
