<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Referral\ReferralService;
use Illuminate\Validation\ValidationException;

class IntroducerBonusService
{
    public const DEFAULT_KEY = 'default_introducer_bonus';

    /**
     * Seeded market defaults in each market's native currency.
     *
     * @var array<string, string>
     */
    public const MARKET_DEFAULTS = [
        'lk' => '50.00',
        'my' => '5.00',
        'th' => '20.00',
    ];

    public function __construct(
        protected SettingsService $settingsService,
        protected ReferralService $referralService,
    ) {
    }

    public function resolveIntroducerBonus(Market|string $market): string
    {
        return $this->getIntroducerBonus($market);
    }

    public function getIntroducerBonus(Market|string $market): string
    {
        $market = $this->resolveMarket($market);
        $this->assertMarketHasActiveCurrency($market);

        $value = $this->settingsService->getForMarket(self::DEFAULT_KEY, $market->id);

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'default_introducer_bonus' => "Default introducer bonus is not configured for the {$market->name} market.",
            ]);
        }

        return $this->normalizeMoneyValue((string) $value);
    }

    /**
     * @deprecated Use resolveIntroducerBonus(Market) for market-aware resolution.
     */
    public function getDefaultBonus(): string
    {
        return $this->getIntroducerBonus('lk');
    }

    /**
     * @deprecated Use resolveIntroducerBonus(Market) for market-aware resolution.
     */
    public function getDefaultIntroducerBonus(): string
    {
        return $this->getDefaultBonus();
    }

    public function setIntroducerBonus(Market|string $market, mixed $amount): string
    {
        $market = $this->resolveMarket($market);
        $this->assertMarketHasActiveCurrency($market);

        $normalized = $this->normalizeMoneyValue($amount);

        $this->settingsService->setForMarket(
            self::DEFAULT_KEY,
            $market->id,
            $normalized,
            'financial',
            "Default introducer bonus for eligible sales in the {$market->name} market."
        );

        return $normalized;
    }

    public function hasIntroducerBonus(Market|string $market): bool
    {
        $market = $this->resolveMarket($market);

        return $this->settingsService->existsForMarket(self::DEFAULT_KEY, $market->id);
    }

    /**
     * @deprecated Use setIntroducerBonus(Market, amount) for market-aware configuration.
     */
    public function setDefaultBonus(mixed $amount): string
    {
        return $this->setIntroducerBonus('lk', $amount);
    }

    /**
     * @deprecated Use setIntroducerBonus(Market, amount) for market-aware configuration.
     */
    public function setDefaultIntroducerBonus(mixed $amount): string
    {
        return $this->setDefaultBonus($amount);
    }

    public function resolveDirectIntroducer(User $user): ?User
    {
        return $this->referralService->getDirectIntroducer($user);
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

    protected function normalizeMoneyValue(mixed $amount, ?string $fallback = null): string
    {
        if ($amount === null || $amount === '') {
            if ($fallback !== null) {
                return $this->normalizeMoneyValue($fallback);
            }

            return '0.00';
        }

        $string = trim((string) $amount);

        if ($string === '') {
            if ($fallback !== null) {
                return $this->normalizeMoneyValue($fallback);
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
