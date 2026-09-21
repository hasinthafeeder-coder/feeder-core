<?php

namespace Feeder\Core\Support;

use Feeder\Core\Models\Currency;
use Feeder\Core\Models\ProductVariant;
use InvalidArgumentException;

class ResellerProductPricing
{
    public const ERROR_SELECTED_PRICE_REQUIRED = 'selected_selling_price_required';

    public const ERROR_SUGGESTED_RANGE_UNAVAILABLE = 'suggested_price_range_unavailable';

    public const ERROR_SELECTED_PRICE_OUT_OF_RANGE = 'selected_selling_price_out_of_range';

    public static function resellerCost(ProductVariant $variant): float
    {
        return (float) $variant->cost + (float) $variant->company_commission;
    }

    /**
     * Authoritative customer-facing unit price for reseller order lines.
     *
     * Locked: variant.selling_price (selected price ignored).
     * Unlocked: selected must fall within suggested_price_min..suggested_price_max.
     *
     * @throws InvalidArgumentException when an unlocked selection is missing or out of range
     */
    public static function customerUnitPrice(
        ProductVariant $variant,
        bool $priceLocked,
        ?float $selectedSellingPrice = null,
    ): float {
        if ($priceLocked) {
            return round((float) $variant->selling_price, 2);
        }

        if ($selectedSellingPrice === null) {
            throw new InvalidArgumentException(self::ERROR_SELECTED_PRICE_REQUIRED);
        }

        $selected = round($selectedSellingPrice, 2);
        $min = $variant->suggested_price_min !== null
            ? round((float) $variant->suggested_price_min, 2)
            : null;
        $max = $variant->suggested_price_max !== null
            ? round((float) $variant->suggested_price_max, 2)
            : null;

        if ($min === null || $max === null) {
            throw new InvalidArgumentException(self::ERROR_SUGGESTED_RANGE_UNAVAILABLE);
        }

        if ($selected < $min || $selected > $max) {
            throw new InvalidArgumentException(self::ERROR_SELECTED_PRICE_OUT_OF_RANGE);
        }

        return $selected;
    }

    /**
     * Default customer-facing unit price for catalog / UI display.
     * Locked → selling_price; unlocked → suggested_price_min.
     */
    public static function defaultCustomerUnitPrice(ProductVariant $variant, bool $priceLocked): ?float
    {
        if ($priceLocked) {
            return $variant->selling_price !== null
                ? round((float) $variant->selling_price, 2)
                : null;
        }

        if ($variant->suggested_price_min !== null) {
            return round((float) $variant->suggested_price_min, 2);
        }

        return null;
    }

    /**
     * Reseller unit profit for a resolved customer-facing unit price.
     *
     * Profit = customer_unit_price - cost - company_commission
     */
    public static function unitProfit(ProductVariant $variant, float $customerUnitPrice): float
    {
        return round($customerUnitPrice - self::resellerCost($variant), 2);
    }

    /**
     * @return array{min: float|null, max: float|null}
     */
    public static function commissionRange(ProductVariant $variant, bool $priceLocked): array
    {
        $cost = self::resellerCost($variant);

        if ($priceLocked) {
            if ($variant->selling_price === null) {
                return ['min' => null, 'max' => null];
            }

            $commission = (float) $variant->selling_price - $cost;

            return ['min' => $commission, 'max' => $commission];
        }

        if ($variant->suggested_price_min !== null && $variant->suggested_price_max !== null) {
            return [
                'min' => (float) $variant->suggested_price_min - $cost,
                'max' => (float) $variant->suggested_price_max - $cost,
            ];
        }

        if ($variant->suggested_price !== null) {
            $commission = (float) $variant->suggested_price - $cost;

            return ['min' => $commission, 'max' => $commission];
        }

        return ['min' => null, 'max' => null];
    }

    public static function formatCommission(?Currency $currency, bool $priceLocked, ProductVariant $variant): string
    {
        $range = self::commissionRange($variant, $priceLocked);

        if ($range['min'] === null && $range['max'] === null) {
            return '—';
        }

        if ($range['min'] !== null && $range['max'] !== null && (float) $range['min'] !== (float) $range['max']) {
            return sprintf(
                '%s — %s',
                CurrencyDisplay::formatAmount($currency, $range['min']),
                CurrencyDisplay::formatAmount($currency, $range['max'])
            );
        }

        return CurrencyDisplay::formatAmount($currency, $range['max'] ?? $range['min']);
    }
}
