<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerMarketServiceChargeOverride;
use Feeder\Core\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ResellerServiceChargeService
{
    public const DEFAULT_KEY = 'default_reseller_service_charge';

    /**
     * Seeded market defaults in each market's native currency.
     *
     * @var array<string, string>
     */
    public const MARKET_DEFAULTS = [
        'lk' => '75.00',
        'my' => '15.00',
        'th' => '30.00',
    ];

    public function __construct(
        protected SettingsService $settingsService,
        protected MarketService $marketService,
    ) {
    }

    public function getDefaultCharge(Market|string $market): string
    {
        $market = $this->resolveMarket($market);
        $this->assertMarketHasActiveCurrency($market);

        $value = $this->settingsService->getForMarket(self::DEFAULT_KEY, $market->id);

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'default_reseller_service_charge' => "Default reseller service charge is not configured for the {$market->name} market.",
            ]);
        }

        return $this->normalizeMoneyValue((string) $value);
    }

    /**
     * @deprecated Use getDefaultCharge(Market) for market-aware resolution.
     */
    public function getDefaultServiceCharge(): string
    {
        return $this->getDefaultCharge('lk');
    }

    public function setDefaultCharge(Market|string $market, mixed $amount): string
    {
        $market = $this->resolveMarket($market);
        $this->assertMarketHasActiveCurrency($market);

        $normalized = $this->normalizeMoneyValue($amount);

        $this->settingsService->setForMarket(
            self::DEFAULT_KEY,
            $market->id,
            $normalized,
            'financial',
            "Default reseller service charge for orders in the {$market->name} market."
        );

        return $normalized;
    }

    public function hasDefaultCharge(Market|string $market): bool
    {
        $market = $this->resolveMarket($market);

        return $this->settingsService->existsForMarket(self::DEFAULT_KEY, $market->id);
    }

    public function getResellerOverride(User $reseller, Market|string $market): ?string
    {
        $market = $this->resolveMarket($market);

        $override = ResellerMarketServiceChargeOverride::query()
            ->where('user_id', $reseller->id)
            ->where('market_id', $market->id)
            ->first();

        if ($override === null || $override->amount === null || $override->amount === '') {
            return null;
        }

        return $this->normalizeMoneyValue((string) $override->amount, null, true);
    }

    public function resolveServiceCharge(User $reseller, Market|string $market): string
    {
        return $this->getResellerOverride($reseller, $market)
            ?? $this->getDefaultCharge($market);
    }

    /**
     * @deprecated Use resolveServiceCharge(User, Market) for market-aware resolution.
     */
    public function getEffectiveCharge(User $reseller): string
    {
        return $this->resolveServiceCharge($reseller, 'lk');
    }

    public function setResellerOverride(User $reseller, Market|string $market, mixed $amount): User
    {
        $market = $this->resolveMarket($market);
        $this->assertResellerHasMarketAccess($reseller, $market);

        $normalized = $this->normalizeMoneyValue($amount, null, true);

        if ($normalized === null) {
            return $this->clearResellerOverride($reseller, $market);
        }

        ResellerMarketServiceChargeOverride::query()->updateOrCreate(
            [
                'user_id' => $reseller->id,
                'market_id' => $market->id,
            ],
            [
                'amount' => $normalized,
            ]
        );

        return $reseller->fresh();
    }

    public function clearResellerOverride(User $reseller, Market|string $market): User
    {
        $market = $this->resolveMarket($market);

        ResellerMarketServiceChargeOverride::query()
            ->where('user_id', $reseller->id)
            ->where('market_id', $market->id)
            ->delete();

        return $reseller->fresh();
    }

    /**
     * @return Collection<int, array{
     *     market: Market,
     *     default_charge: string,
     *     override: ?string,
     *     effective_charge: string,
     *     uses_market_default: bool
     * }>
     */
    public function buildResellerProfileContext(User $reseller): Collection
    {
        $reseller->loadMissing('company.allowedMarkets.country', 'company.allowedMarkets.currency');

        return $reseller->company?->allowedMarkets
            ?->sortBy('name')
            ->map(function (Market $market) use ($reseller): array {
                $defaultCharge = $this->getDefaultCharge($market);
                $override = $this->getResellerOverride($reseller, $market);

                return [
                    'market' => $market,
                    'default_charge' => $defaultCharge,
                    'override' => $override,
                    'effective_charge' => $override ?? $defaultCharge,
                    'uses_market_default' => $override === null,
                ];
            }) ?? collect();
    }

    public function assertResellerHasMarketAccess(User $reseller, Market $market): void
    {
        $reseller->loadMissing('company.allowedMarkets');

        $hasAccess = $reseller->company?->allowedMarkets
            ?->contains(fn (Market $allowedMarket) => (int) $allowedMarket->id === (int) $market->id) ?? false;

        if (! $hasAccess) {
            throw ValidationException::withMessages([
                'market_id' => 'The reseller does not have access to the selected market.',
            ]);
        }
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

    protected function normalizeMoneyValue(mixed $amount, ?string $fallback = null, bool $allowNull = false): ?string
    {
        if ($amount === null || $amount === '') {
            if ($allowNull) {
                return null;
            }

            if ($fallback !== null) {
                return $this->normalizeMoneyValue($fallback, null, false);
            }

            return '0.00';
        }

        $string = trim((string) $amount);

        if ($string === '') {
            if ($allowNull) {
                return null;
            }

            if ($fallback !== null) {
                return $this->normalizeMoneyValue($fallback, null, false);
            }

            return '0.00';
        }

        if (! is_numeric($string)) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must be a valid numeric value.',
            ]);
        }

        $value = (float) $string;

        if ($value < 0) {
            throw ValidationException::withMessages([
                'amount' => 'The amount cannot be negative.',
            ]);
        }

        if ($value > 100000000) {
            throw ValidationException::withMessages([
                'amount' => 'The amount exceeds the supported maximum.',
            ]);
        }

        return number_format($value, 2, '.', '');
    }
}
