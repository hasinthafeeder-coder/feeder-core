<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\Market;
use Illuminate\Validation\ValidationException;

class MarketDefaultCompanyCommissionService
{
    public const KEY = 'default_company_commission';

    /**
     * Seeded market defaults in each market's native currency.
     * These values are not derived from exchange rates.
     *
     * @var array<string, string>
     */
    public const MARKET_DEFAULTS = [
        'lk' => '150.00',
        'my' => '15.00',
        'th' => '50.00',
    ];

    public function __construct(protected SettingsService $settingsService)
    {
    }

    public function getDefaultCompanyCommission(Market|string $market): string
    {
        $market = $this->resolveMarket($market);
        $this->assertMarketHasActiveCurrency($market);

        $value = $this->settingsService->getForMarket(self::KEY, $market->id);

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'default_company_commission' => "Default company commission is not configured for the {$market->name} market.",
            ]);
        }

        return $this->normalizeMoneyValue((string) $value);
    }

    public function setDefaultCompanyCommission(Market|string $market, mixed $amount): string
    {
        $market = $this->resolveMarket($market);
        $this->assertMarketHasActiveCurrency($market);

        $normalized = $this->normalizeMoneyValue($amount);

        $this->settingsService->setForMarket(
            self::KEY,
            $market->id,
            $normalized,
            'financial',
            "Default company commission for new product variants in the {$market->name} market."
        );

        return $normalized;
    }

    public function hasDefaultCompanyCommission(Market|string $market): bool
    {
        $market = $this->resolveMarket($market);

        return $this->settingsService->existsForMarket(self::KEY, $market->id);
    }

    public function normalizeMoneyValue(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'default_company_commission' => 'Default company commission is required.',
            ]);
        }

        $string = trim((string) $amount);

        if ($string === '' || ! is_numeric($string)) {
            throw ValidationException::withMessages([
                'default_company_commission' => 'Default company commission must be a valid numeric value.',
            ]);
        }

        $value = (float) $string;

        if ($value < 0) {
            throw ValidationException::withMessages([
                'default_company_commission' => 'Default company commission cannot be negative.',
            ]);
        }

        if ($value > 100000000) {
            throw ValidationException::withMessages([
                'default_company_commission' => 'Default company commission exceeds the supported maximum.',
            ]);
        }

        return number_format($value, 2, '.', '');
    }

    protected function resolveMarket(Market|string $market): Market
    {
        if ($market instanceof Market) {
            return $market->loadMissing('currency', 'country');
        }

        $resolved = Market::query()
            ->where('uuid', $market)
            ->orWhere('code', $market)
            ->first();

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'market' => 'The specified market could not be found.',
            ]);
        }

        return $resolved->loadMissing('currency', 'country');
    }

    protected function assertMarketHasActiveCurrency(Market $market): void
    {
        $currency = $market->currency;

        if ($currency === null || ! $currency->is_active || ! filled($currency->iso_code)) {
            throw ValidationException::withMessages([
                'market' => "The {$market->name} market does not have a valid active currency.",
            ]);
        }
    }
}
