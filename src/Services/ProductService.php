<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductDescription;
use Feeder\Core\Models\ProductGuideline;
use Feeder\Core\Models\ProductImage;
use Feeder\Core\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function createProduct(
        array $productData,
        array $descriptions = [],
        array $variants = [],
        array $images = [],
        ?array $guideline = null
    ): Product {
        return DB::transaction(function () use ($productData, $descriptions, $variants, $images, $guideline) {
            $product = Product::query()->create([
                'id' => $productData['id'] ?? (string) Str::uuid(),
                'supplier_id' => $productData['supplier_id'],
                'category_id' => $productData['category_id'],
                'name' => $productData['name'],
                'weight' => $productData['weight'],
                'status' => $productData['status'] ?? ProductStatus::DRAFT,
                'system_visible' => $productData['system_visible'] ?? true,
                'web_visible' => $productData['web_visible'] ?? true,
                'price_locked' => $productData['price_locked'] ?? false,
                'created_by' => $productData['created_by'] ?? $productData['supplier_id'],
                'updated_by' => $productData['updated_by'] ?? $productData['supplier_id'],
            ]);

            foreach ($descriptions as $description) {
                ProductDescription::query()->create([
                    'id' => $description['id'] ?? (string) Str::uuid(),
                    'product_id' => $product->id,
                    'locale' => $description['locale'],
                    'description' => $description['description'] ?? null,
                    'created_by' => $description['created_by'] ?? $product->supplier_id,
                    'updated_by' => $description['updated_by'] ?? $product->supplier_id,
                ]);
            }

            foreach ($variants as $index => $variant) {
                ProductVariant::query()->create([
                    'id' => $variant['id'] ?? (string) Str::uuid(),
                    'product_id' => $product->id,
                    'name' => $variant['name'],
                    'barcode' => $variant['barcode'] ?? null,
                    'cost' => $variant['cost'],
                    'selling_price' => $variant['selling_price'],
                    'suggested_price' => $variant['suggested_price'] ?? null,
                    'company_commission' => $variant['company_commission'] ?? 150.00,
                    'sort_order' => $variant['sort_order'] ?? $index,
                    'is_active' => $variant['is_active'] ?? true,
                    'created_by' => $variant['created_by'] ?? $product->supplier_id,
                    'updated_by' => $variant['updated_by'] ?? $product->supplier_id,
                ]);
            }

            foreach ($images as $index => $image) {
                ProductImage::query()->create([
                    'id' => $image['id'] ?? (string) Str::uuid(),
                    'product_id' => $product->id,
                    'file_uuid' => $image['file_uuid'],
                    'sort_order' => $image['sort_order'] ?? $index,
                    'is_primary' => $image['is_primary'] ?? ($index === 0),
                ]);
            }

            if ($guideline) {
                ProductGuideline::query()->create([
                    'id' => $guideline['id'] ?? (string) Str::uuid(),
                    'product_id' => $product->id,
                    'file_uuid' => $guideline['file_uuid'],
                ]);
            }

            return $product->load(['supplier', 'category', 'descriptions', 'variants', 'images', 'guideline']);
        });
    }

    public function updateProduct(
        Product $product,
        array $productData,
        array $descriptions = [],
        array $variants = [],
        array $images = [],
        ?array $guideline = null
    ): Product {
        return DB::transaction(function () use ($product, $productData, $descriptions, $variants, $images, $guideline) {
            $product->fill([
                'category_id' => $productData['category_id'] ?? $product->category_id,
                'name' => $productData['name'] ?? $product->name,
                'weight' => $productData['weight'] ?? $product->weight,
                'status' => $productData['status'] ?? $product->status,
                'system_visible' => $productData['system_visible'] ?? $product->system_visible,
                'web_visible' => $productData['web_visible'] ?? $product->web_visible,
                'price_locked' => $productData['price_locked'] ?? $product->price_locked,
                'updated_by' => $productData['updated_by'] ?? $product->supplier_id,
            ]);
            $product->save();

            foreach ($descriptions as $description) {
                $locale = $description['locale'] ?? null;

                if (! $locale) {
                    continue;
                }

                ProductDescription::query()->updateOrCreate(
                    ['product_id' => $product->id, 'locale' => $locale],
                    [
                        'description' => $description['description'] ?? null,
                        'created_by' => $description['created_by'] ?? $product->supplier_id,
                        'updated_by' => $description['updated_by'] ?? $product->supplier_id,
                    ]
                );
            }

            foreach ($variants as $index => $variant) {
                $variantId = $variant['id'] ?? null;

                if (($variant['delete'] ?? false) && $variantId) {
                    $product->variants()->whereKey($variantId)->delete();
                    continue;
                }

                $attributes = [
                    'product_id' => $product->id,
                    'name' => $variant['name'],
                    'barcode' => $variant['barcode'] ?? null,
                    'cost' => $variant['cost'],
                    'selling_price' => $variant['selling_price'],
                    'suggested_price' => $variant['suggested_price'] ?? null,
                    'company_commission' => $variant['company_commission'] ?? 150.00,
                    'sort_order' => $variant['sort_order'] ?? $index,
                    'is_active' => $variant['is_active'] ?? true,
                    'updated_by' => $variant['updated_by'] ?? $product->supplier_id,
                ];

                if ($variantId) {
                    $existing = $product->variants()->whereKey($variantId)->first();

                    if ($existing) {
                        $existing->fill($attributes);
                        $existing->save();
                        continue;
                    }
                }

                ProductVariant::query()->create([
                    'id' => $variantId ?? (string) Str::uuid(),
                    'product_id' => $product->id,
                    'name' => $attributes['name'],
                    'barcode' => $attributes['barcode'],
                    'cost' => $attributes['cost'],
                    'selling_price' => $attributes['selling_price'],
                    'suggested_price' => $attributes['suggested_price'],
                    'company_commission' => $attributes['company_commission'],
                    'sort_order' => $attributes['sort_order'],
                    'is_active' => $attributes['is_active'],
                    'created_by' => $variant['created_by'] ?? $product->supplier_id,
                    'updated_by' => $attributes['updated_by'],
                ]);
            }

            foreach ($images as $index => $image) {
                $imageId = $image['id'] ?? null;

                if (($image['delete'] ?? false) && $imageId) {
                    $product->images()->whereKey($imageId)->delete();
                    continue;
                }

                if ($imageId) {
                    $existing = $product->images()->whereKey($imageId)->first();

                    if ($existing) {
                        $existing->fill([
                            'file_uuid' => $image['file_uuid'] ?? $existing->file_uuid,
                            'sort_order' => $image['sort_order'] ?? $index,
                            'is_primary' => $image['is_primary'] ?? $existing->is_primary,
                        ]);
                        $existing->save();
                        continue;
                    }
                }

                ProductImage::query()->create([
                    'id' => $imageId ?? (string) Str::uuid(),
                    'product_id' => $product->id,
                    'file_uuid' => $image['file_uuid'],
                    'sort_order' => $image['sort_order'] ?? $index,
                    'is_primary' => $image['is_primary'] ?? ($index === 0),
                ]);
            }

            if ($guideline !== null) {
                if (empty($guideline['file_uuid'])) {
                    $product->guideline()->delete();
                } else {
                    ProductGuideline::query()->updateOrCreate(
                        ['product_id' => $product->id],
                        ['file_uuid' => $guideline['file_uuid']]
                    );
                }
            }

            return $product->refresh()->load(['supplier', 'category', 'descriptions', 'variants', 'images', 'guideline']);
        });
    }
}
