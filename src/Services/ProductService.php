<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\User;
use Feeder\Core\Models\ProductDescription;
use Feeder\Core\Models\ProductImage;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Support\CommercialIdentifier;
use Feeder\Core\Support\ProductVariantBarcodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function __construct(
        protected CompanyCommissionService $companyCommissionService,
        protected ProductVariantBarcodeGenerator $barcodeGenerator,
        protected ProductApprovalService $productApprovalService,
    ) {
    }
    protected function generateUniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = trim((string) Str::slug($value ?: 'product'));

        if ($base === '') {
            $base = 'product';
        }

        $slug = $base;
        $count = 1;

        while (Product::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base . '-' . $count;
            $count++;
        }

        return $slug;
    }

    public function resolveStatus(
        string $action,
        ?ProductStatus $currentStatus = null,
        bool $isCreate = false
    ): ProductStatus {
        return match ($action) {
            'publish', 'activate' => ProductStatus::ACTIVE,
            'deactivate' => ProductStatus::INACTIVE,
            'draft' => ($isCreate || $currentStatus === null || $currentStatus === ProductStatus::DRAFT)
                ? ProductStatus::DRAFT
                : $currentStatus,
            'save', 'update' => $isCreate
                ? ProductStatus::DRAFT
                : ($currentStatus ?? ProductStatus::DRAFT),
            default => $isCreate
                ? ProductStatus::DRAFT
                : ($currentStatus ?? ProductStatus::DRAFT),
        };
    }

    /**
     * @param  bool  $lockAdminFinancialFields  When true (supplier portal), company
     *     commission cannot be set from the request, and existing variant costs
     *     cannot be changed. New variants may still receive an initial cost.
     */
    public function createProduct(
        array $productData,
        array $descriptions = [],
        array $variants = [],
        array $images = [],
        ?array $guideline = null,
        bool $lockAdminFinancialFields = false,
    ): Product {
        return DB::transaction(function () use (
            $productData,
            $descriptions,
            $variants,
            $images,
            $guideline,
            $lockAdminFinancialFields
        ) {
            unset($productData['market_id']);

            $status = $productData['status'] instanceof ProductStatus
                ? $productData['status']
                : ProductStatus::from((string) ($productData['status'] ?? ProductStatus::DRAFT->value));

            $marketId = $this->resolveSupplierOperationMarketId((int) $productData['supplier_id']);

            $product = Product::query()->create([
                'uuid' => $productData['uuid'] ?? (string) Str::uuid(),
                'supplier_id' => $productData['supplier_id'],
                'category_id' => $productData['category_id'],
                'market_id' => $marketId,
                'name' => $productData['name'],
                'slug' => $this->generateUniqueSlug($productData['slug'] ?? $productData['name']),
                'status' => $status,
                'system_visible' => $productData['system_visible'] ?? true,
                'web_visible' => $productData['web_visible'] ?? true,
                'price_locked' => $productData['price_locked'] ?? false,
                'guideline_file_id' => $guideline['file_id'] ?? $productData['guideline_file_id'] ?? null,
                'published_at' => $status === ProductStatus::ACTIVE
                    ? ($productData['published_at'] ?? now())
                    : null,
                'created_by' => $productData['created_by'] ?? $productData['supplier_id'],
                'updated_by' => $productData['updated_by'] ?? $productData['supplier_id'],
            ]);

            $this->syncDescriptions($product, $descriptions);
            $this->syncVariants(
                $product,
                $this->applyPriceLockToVariants($variants, (bool) ($productData['price_locked'] ?? false)),
                true,
                $lockAdminFinancialFields
            );
            $this->syncImages($product, $images, true);

            return $product->load($this->defaultRelations());
        });
    }

    /**
     * @param  bool  $lockAdminFinancialFields  When true (supplier portal), company
     *     commission cannot be set from the request, and existing variant costs
     *     cannot be changed. New variants may still receive an initial cost.
     * @param  bool  $markApproved  When true (explicit admin publish/activate only),
     *     set approval_status to APPROVED. Ordinary saves must not rewrite historical
     *     ACTIVE + null grandfather rows.
     */
    public function updateProduct(
        Product $product,
        array $productData,
        array $descriptions = [],
        array $variants = [],
        array $images = [],
        ?array $guideline = null,
        bool $lockAdminFinancialFields = false,
        bool $markApproved = false,
    ): Product {
        return DB::transaction(function () use (
            $product,
            $productData,
            $descriptions,
            $variants,
            $images,
            $guideline,
            $lockAdminFinancialFields,
            $markApproved
        ) {
            unset($productData['supplier_id'], $productData['created_by'], $productData['uuid'], $productData['market_id']);

            $status = array_key_exists('status', $productData)
                ? ($productData['status'] instanceof ProductStatus
                    ? $productData['status']
                    : ProductStatus::from((string) $productData['status']))
                : $product->status;

            $fill = [
                'category_id' => $productData['category_id'] ?? $product->category_id,
                'name' => $productData['name'] ?? $product->name,
                'status' => $status,
                'system_visible' => $productData['system_visible'] ?? $product->system_visible,
                'web_visible' => $productData['web_visible'] ?? $product->web_visible,
                'price_locked' => $productData['price_locked'] ?? $product->price_locked,
                'updated_by' => $productData['updated_by'] ?? $product->updated_by ?? $product->supplier_id,
            ];

            if (array_key_exists('name', $productData) && $productData['name'] !== $product->name) {
                $fill['slug'] = $this->generateUniqueSlug($productData['name'], $product->id);
            }

            if ($guideline !== null) {
                $fill['guideline_file_id'] = $guideline['file_id'] ?? null;
            }

            if ($status === ProductStatus::ACTIVE && $product->published_at === null) {
                $fill['published_at'] = now();
            }

            $product->fill($fill);

            // Explicit admin publish/activate only — never on ordinary saves, so
            // historical ACTIVE + null grandfather rows are left untouched.
            if ($markApproved && ! $lockAdminFinancialFields && $status === ProductStatus::ACTIVE) {
                $this->productApprovalService->markApprovedByAdminLifecycle(
                    $product,
                    isset($productData['updated_by']) ? (int) $productData['updated_by'] : null
                );
            }

            $product->save();

            $this->syncDescriptions($product, $descriptions);
            $this->syncVariants(
                $product,
                $this->applyPriceLockToVariants(
                    $variants,
                    (bool) ($productData['price_locked'] ?? $product->price_locked)
                ),
                false,
                $lockAdminFinancialFields
            );
            $this->syncImages($product, $images, false);

            return $product->refresh()->load($this->defaultRelations());
        });
    }

    public function deactivateProduct(Product $product, ?int $updatedBy = null): Product
    {
        if ($product->status !== ProductStatus::ACTIVE && $product->status !== ProductStatus::DRAFT) {
            return $product;
        }

        $product->fill([
            'status' => ProductStatus::INACTIVE,
            'updated_by' => $updatedBy ?? $product->supplier_id,
        ]);
        $product->save();

        return $product->refresh()->load($this->defaultRelations());
    }

    public function activateProduct(Product $product, ?int $updatedBy = null, bool $markApproved = false): Product
    {
        if ($product->status !== ProductStatus::INACTIVE && $product->status !== ProductStatus::DRAFT) {
            return $product;
        }

        return DB::transaction(function () use ($product, $updatedBy, $markApproved): Product {
            $product->fill([
                'status' => ProductStatus::ACTIVE,
                'published_at' => $product->published_at ?? now(),
                'updated_by' => $updatedBy ?? $product->supplier_id,
            ]);

            if ($markApproved) {
                $this->productApprovalService->markApprovedByAdminLifecycle($product, $updatedBy);
            }

            $product->save();

            return $product->refresh()->load($this->defaultRelations());
        });
    }

    public function deleteProduct(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $product->delete();
        });
    }

    protected function applyPriceLockToVariants(array $variants, bool $priceLocked): array
    {
        foreach ($variants as $index => $variant) {
            if (($variant['delete'] ?? false) === true) {
                continue;
            }

            if ($priceLocked) {
                $variants[$index]['suggested_price_min'] = null;
                $variants[$index]['suggested_price_max'] = null;
            } else {
                $variants[$index]['suggested_price'] = null;
            }
        }

        return $variants;
    }

    protected function syncDescriptions(Product $product, array $descriptions): void
    {
        foreach ($descriptions as $description) {
            $languageCode = $description['language_code'] ?? $description['locale'] ?? null;

            if (! $languageCode) {
                continue;
            }

            ProductDescription::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'language_code' => $languageCode,
                ],
                [
                    'description' => $description['description'] ?? null,
                ]
            );
        }
    }

    protected function syncVariants(
        Product $product,
        array $variants,
        bool $isCreate,
        bool $lockAdminFinancialFields = false,
    ): void {
        $retainedIds = [];
        $product->loadMissing('market');
        $market = $product->market;

        foreach ($variants as $index => $variant) {
            if (($variant['delete'] ?? false) && ! empty($variant['id'])) {
                $product->variants()->whereKey($variant['id'])->delete();
                continue;
            }

            $variantId = $variant['id'] ?? null;
            $existing = $variantId
                ? $product->variants()->whereKey($variantId)->first()
                : null;

            $barcode = trim((string) ($variant['barcode'] ?? ''));
            if ($barcode === '') {
                $barcode = $existing?->barcode
                    ?: $this->barcodeGenerator->allocateNext((int) $product->market_id);
            } else {
                $this->assertBarcodeMatchesMarket($barcode, $market, $index);
            }

            // Suppliers cannot change live cost on existing variants or set commission.
            // New variants may receive an initial cost; commission always uses market default / existing.
            if ($lockAdminFinancialFields) {
                unset($variant['company_commission']);

                if ($existing !== null) {
                    $variant['cost'] = $existing->cost;
                }
            }

            $attributes = [
                'name' => $variant['name'],
                'barcode' => $barcode,
                'cost' => $variant['cost'],
                'selling_price' => $variant['selling_price'],
                'weight' => $variant['weight'] ?? null,
                'suggested_price' => $variant['suggested_price'] ?? null,
                'suggested_price_min' => $variant['suggested_price_min'] ?? null,
                'suggested_price_max' => $variant['suggested_price_max'] ?? null,
                'company_commission' => $this->resolveVariantCompanyCommission($product, $variant, $existing),
                'sort_order' => $variant['sort_order'] ?? $index,
                'is_active' => $variant['is_active'] ?? true,
                'updated_by' => $variant['updated_by'] ?? $product->updated_by ?? $product->supplier_id,
            ];

            // Only persist when explicitly provided so Admin updates without the field
            // do not reset an existing supplier-configured reorder level to 0.
            if (array_key_exists('reorder_level', $variant)) {
                $attributes['reorder_level'] = (int) $variant['reorder_level'];
            }

            if ($existing) {
                $existing->fill($attributes);
                $existing->save();
                $retainedIds[] = $existing->id;
                continue;
            }

            $created = ProductVariant::query()->create([
                ...$attributes,
                'uuid' => $variant['uuid'] ?? (string) Str::uuid(),
                'product_id' => $product->id,
                'created_by' => $variant['created_by'] ?? $product->supplier_id,
            ]);

            $retainedIds[] = $created->id;
        }

        if (! $isCreate) {
            $product->variants()
                ->when(
                    count($retainedIds) > 0,
                    fn ($query) => $query->whereNotIn('id', $retainedIds),
                    fn ($query) => $query
                )
                ->delete();
        }
    }

    private function assertBarcodeMatchesMarket(string $barcode, ?Market $market, int $index): void
    {
        if ($market === null || $market->market_number === null) {
            throw ValidationException::withMessages([
                "variants.{$index}.barcode" => ['Product market does not have a configured market number.'],
            ]);
        }

        if (! CommercialIdentifier::isValidProductBarcodeForMarket($barcode, (int) $market->market_number)) {
            throw ValidationException::withMessages([
                "variants.{$index}.barcode" => [
                    'Barcode must be exactly 6 digits starting with market number '.$market->market_number.'.',
                ],
            ]);
        }
    }

    protected function syncImages(Product $product, array $images, bool $isCreate): void
    {
        foreach ($images as $index => $image) {
            if (($image['delete'] ?? false) && ! empty($image['id'])) {
                $product->images()->whereKey($image['id'])->delete();
                continue;
            }

            $fileId = $image['file_id'] ?? null;

            if (empty($fileId)) {
                continue;
            }

            $attributes = [
                'file_id' => (int) $fileId,
                'sort_order' => $image['sort_order'] ?? $index,
                'is_primary' => $image['is_primary'] ?? ($index === 0 && $product->images()->count() === 0),
            ];

            if (! empty($image['id'])) {
                $existing = $product->images()->whereKey($image['id'])->first();

                if ($existing) {
                    $existing->fill($attributes);
                    $existing->save();
                    continue;
                }
            }

            ProductImage::query()->create([
                ...$attributes,
                'product_id' => $product->id,
            ]);
        }
    }

    protected function defaultRelations(): array
    {
        return [
            'supplier.company',
            'category',
            'market',
            'descriptions',
            'variants',
            'images.file',
            'guidelineFile',
        ];
    }

    protected function resolveVariantCompanyCommission(Product $product, array $variant, ?ProductVariant $existing): string
    {
        $explicitAmount = array_key_exists('company_commission', $variant)
            ? $variant['company_commission']
            : null;

        if ($explicitAmount !== null && $explicitAmount !== '') {
            return $this->companyCommissionService->resolveCompanyCommission($product, null, $explicitAmount);
        }

        return $this->companyCommissionService->resolveCompanyCommission($product, $existing);
    }

    protected function resolveSupplierOperationMarketId(int $supplierId): int
    {
        /** @var User|null $supplier */
        $supplier = User::query()
            ->with('company')
            ->find($supplierId);

        $operationMarketId = $supplier?->company?->operation_market_id;

        if ($operationMarketId === null) {
            throw ValidationException::withMessages([
                'supplier' => 'The supplier operation market is not configured.',
            ]);
        }

        return (int) $operationMarketId;
    }
}
