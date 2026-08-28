<?php

namespace Feeder\Core\Support;

use Feeder\Core\Models\Currency;
use Feeder\Core\Models\Market;

class CurrencyDisplay
{
    public const UNAVAILABLE_LABEL = 'Currency unavailable';

    public static function currencyFromMarket(?Market $market): ?Currency
    {
        if ($market === null) {
            return null;
        }

        if ($market->relationLoaded('currency')) {
            return $market->getRelation('currency');
        }

        return $market->currency;
    }

    public static function inputLabel(?Currency $currency): string
    {
        if ($currency === null || blank($currency->iso_code)) {
            return self::UNAVAILABLE_LABEL;
        }

        return (string) $currency->iso_code;
    }

    public static function formatAmount(?Currency $currency, float|string|null $amount): string
    {
        if ($currency === null || blank($currency->iso_code)) {
            return self::UNAVAILABLE_LABEL;
        }

        if ($amount === null) {
            return '—';
        }

        $decimals = (int) ($currency->decimal_places ?? 2);

        return sprintf(
            '%s %s',
            $currency->iso_code,
            number_format((float) $amount, $decimals, '.', ',')
        );
    }

    public static function formatCurrencyDescriptor(?Currency $currency): string
    {
        if ($currency === null || blank($currency->iso_code)) {
            return self::UNAVAILABLE_LABEL;
        }

        $symbol = filled($currency->symbol) ? ' ('.$currency->symbol.')' : '';

        return $currency->iso_code.$symbol;
    }
}
