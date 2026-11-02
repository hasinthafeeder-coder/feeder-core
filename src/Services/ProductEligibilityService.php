<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\ProductApprovalStatus;
use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Shared sellability rules for reseller catalog and order write paths.
 *
 * A product is order-eligible when it is ACTIVE, system-visible, and approved
 * (or grandfathered ACTIVE with a null approval_status from before approval existed).
 */
class ProductEligibilityService
{
    public function isProductSellable(Product $product): bool
    {
        if ($product->status !== ProductStatus::ACTIVE) {
            return false;
        }

        if (! (bool) $product->system_visible) {
            return false;
        }

        return $this->isApprovedForSale($product);
    }

    public function isApprovedForSale(Product $product): bool
    {
        $approval = $product->approval_status;

        if ($approval === ProductApprovalStatus::APPROVED) {
            return true;
        }

        // Historical products created before approval: ACTIVE + null remain sellable.
        if ($approval === null && $product->status === ProductStatus::ACTIVE) {
            return true;
        }

        return false;
    }

    public function isVariantSellable(ProductVariant $variant): bool
    {
        if (! (bool) $variant->is_active) {
            return false;
        }

        $product = $variant->product;

        if ($product === null) {
            return false;
        }

        return $this->isProductSellable($product);
    }

    /**
     * Apply catalog/order listing constraints used by reseller surfaces.
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query
            ->where('status', ProductStatus::ACTIVE)
            ->where('system_visible', true)
            ->where(function (Builder $inner): void {
                $inner->where('approval_status', ProductApprovalStatus::APPROVED->value)
                    ->orWhereNull('approval_status');
            });
    }

    /**
     * @throws ValidationException
     */
    public function assertVariantSellable(
        ProductVariant $variant,
        string $errorKey = 'product_variant_id',
    ): void {
        if ($variant->product === null) {
            throw ValidationException::withMessages([
                $errorKey => ['The selected product variant is invalid.'],
            ]);
        }

        if (! (bool) $variant->is_active) {
            throw ValidationException::withMessages([
                $errorKey => ['The selected product variant is not available for ordering.'],
            ]);
        }

        if ($variant->product->status !== ProductStatus::ACTIVE) {
            throw ValidationException::withMessages([
                $errorKey => ['The selected product is not active.'],
            ]);
        }

        if (! (bool) $variant->product->system_visible) {
            throw ValidationException::withMessages([
                $errorKey => ['The selected product is not available for ordering.'],
            ]);
        }

        if (! $this->isApprovedForSale($variant->product)) {
            throw ValidationException::withMessages([
                $errorKey => ['The selected product is not approved for ordering.'],
            ]);
        }
    }

    /**
     * Human-readable import/validation error without ValidationException.
     */
    public function variantIneligibilityReason(ProductVariant $variant): ?string
    {
        if ($variant->product === null) {
            return 'Product variant does not exist.';
        }

        if (! (bool) $variant->is_active) {
            return 'Product variant is inactive.';
        }

        if ($variant->product->status !== ProductStatus::ACTIVE) {
            return 'Product is not active.';
        }

        if (! (bool) $variant->product->system_visible) {
            return 'Product is not available for ordering.';
        }

        if (! $this->isApprovedForSale($variant->product)) {
            return 'Product is not approved for ordering.';
        }

        return null;
    }
}
