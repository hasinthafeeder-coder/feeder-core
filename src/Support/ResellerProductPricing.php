<?php

namespace Feeder\Core\Support;

use Feeder\Core\Models\Currency;
use Feeder\Core\Models\ProductVariant;

class ResellerProductPricing
{
    public static function resellerCost(ProductVariant $variant): float
    {
        return (float) $variant->cost + (float) $variant->company_commission;
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
