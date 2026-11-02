<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Enums\ResellerNonDeliveryFineCondition;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerMarketNonDeliveryBaseFineOverride;
use Feeder\Core\Models\User;
use Feeder\Core\Services\MarketService;
use Feeder\Core\Services\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Market-scoped non-delivery fine totals and per-reseller BASE overrides.
 *
 * Amounts are native to each market currency. Never FX-converted.
 * LKR (lk) has approved seeded defaults; other markets expose a configuration gap until set.
 */
class ResellerNonDeliveryFineSettingsService
{
    public const KEY_BASE = 'non_delivery_fine_base';

    public const KEY_AFTER_HOURS = 'non_delivery_fine_after_hours';

    public const KEY_PROCESSING_OVERRIDE = 'non_delivery_fine_processing_override';

    public const KEY_COMBINED = 'non_delivery_fine_combined';

    /**
     * Approved LKR defaults. Other markets intentionally omitted.
     *
     * @var array<string, array{
     *     base: string,
     *     after_hours: string,
     *     processing_override: string,
     *     combined: string
     * }>
     */
    public const MARKET_DEFAULTS = [
        'lk' => [
            'base' => '15.00',
            'after_hours' => '100.00',
            'processing_override' => '100.00',
            'combined' => '200.00',
        ],
    ];

    /**
     * @var array<string, string>
     */
    public const CONDITION_SETTING_KEYS = [
        ResellerNonDeliveryFineCondition::BASE->value => self::KEY_BASE,
        ResellerNonDeliveryFineCondition::AFTER_HOURS->value => self::KEY_AFTER_HOURS,
        ResellerNonDeliveryFineCondition::PROCESSING_OVERRIDE->value => self::KEY_PROCESSING_OVERRIDE,
        ResellerNonDeliveryFineCondition::COMBINED->value => self::KEY_COMBINED,
    ];

    public function __construct(
        protected SettingsService $settingsService,
        protected MarketService $marketService,
    ) {}

    /**
     * @return array{
     *     base: string|null,
     *     after_hours: string|null,
     *     processing_override: string|null,
     *     combined: string|null,
     *     configured: bool,
     *     missing_keys: list<string>
     * }
     */
    public function getSchedule(Market|string $market): array
    {
        $market = $this->resolveMarket($market);

        $base = $this->readAmount(self::KEY_BASE, $market);
        $afterHours = $this->readAmount(self::KEY_AFTER_HOURS, $market);
        $processing = $this->readAmount(self::KEY_PROCESSING_OVERRIDE, $market);
        $combined = $this->readAmount(self::KEY_COMBINED, $market);

        $missing = [];
        if ($base === null) {
            $missing[] = self::KEY_BASE;
        }
        if ($afterHours === null) {
            $missing[] = self::KEY_AFTER_HOURS;
        }
        if ($processing === null) {
            $missing[] = self::KEY_PROCESSING_OVERRIDE;
        }
        if ($combined === null) {
            $missing[] = self::KEY_COMBINED;
        }

        return [
            'base' => $base,
            'after_hours' => $afterHours,
            'processing_override' => $processing,
            'combined' => $combined,
            'configured' => $missing === [],
            'missing_keys' => $missing,
        ];
    }

    public function hasCompleteSchedule(Market|string $market): bool
    {
        return $this->getSchedule($market)['configured'];
    }

    public function amountForCondition(
        Market|string $market,
        ResellerNonDeliveryFineCondition $condition,
    ): ?string {
        $schedule = $this->getSchedule($market);

        return match ($condition) {
            ResellerNonDeliveryFineCondition::BASE => $schedule['base'],
            ResellerNonDeliveryFineCondition::AFTER_HOURS => $schedule['after_hours'],
            ResellerNonDeliveryFineCondition::PROCESSING_OVERRIDE => $schedule['processing_override'],
            ResellerNonDeliveryFineCondition::COMBINED => $schedule['combined'],
        };
    }

    /**
     * @param  array{
     *     base?: mixed,
     *     after_hours?: mixed,
     *     processing_override?: mixed,
     *     combined?: mixed
     * }  $amounts
     * @return array{
     *     base: string,
     *     after_hours: string,
     *     processing_override: string,
     *     combined: string
     * }
     */
    public function setSchedule(Market|string $market, array $amounts): array
    {
        $market = $this->resolveMarket($market);
        $this->assertMarketHasActiveCurrency($market);

        $base = $this->normalizeRequiredMoney($amounts['base'] ?? null, 'base');
        $afterHours = $this->normalizeRequiredMoney($amounts['after_hours'] ?? null, 'after_hours');
        $processing = $this->normalizeRequiredMoney($amounts['processing_override'] ?? null, 'processing_override');
        $combined = $this->normalizeRequiredMoney($amounts['combined'] ?? null, 'combined');

        $this->settingsService->setForMarket(
            self::KEY_BASE,
            (int) $market->id,
            $base,
            'financial',
            "Non-delivery base fine for the {$market->name} market.",
        );
        $this->settingsService->setForMarket(
            self::KEY_AFTER_HOURS,
            (int) $market->id,
            $afterHours,
            'financial',
            "Non-delivery + out-of-office-hours fine total for the {$market->name} market.",
        );
        $this->settingsService->setForMarket(
            self::KEY_PROCESSING_OVERRIDE,
            (int) $market->id,
            $processing,
            'financial',
            "Non-delivery + processing-order override fine total for the {$market->name} market.",
        );
        $this->settingsService->setForMarket(
            self::KEY_COMBINED,
            (int) $market->id,
            $combined,
            'financial',
            "Non-delivery combined (after-hours + processing override) fine total for the {$market->name} market.",
        );

        return [
            'base' => $base,
            'after_hours' => $afterHours,
            'processing_override' => $processing,
            'combined' => $combined,
        ];
    }

    /**
     * Seed approved defaults for markets that define them and are not yet configured.
     */
    public function seedApprovedDefaults(): void
    {
        foreach (self::MARKET_DEFAULTS as $marketCode => $defaults) {
            $market = Market::query()->where('code', $marketCode)->first();

            if ($market === null || $this->hasCompleteSchedule($market)) {
                continue;
            }

            $this->setSchedule($market, $defaults);
        }
    }

    public function getResellerBaseOverride(User $reseller, Market|string $market): ?string
    {
        $market = $this->resolveMarket($market);

        $override = ResellerMarketNonDeliveryBaseFineOverride::query()
            ->where('user_id', $reseller->id)
            ->where('market_id', $market->id)
            ->first();

        if ($override === null || $override->amount === null || $override->amount === '') {
            return null;
        }

        return $this->normalizeMoneyValue((string) $override->amount, allowNull: true);
    }

    public function setResellerBaseOverride(User $reseller, Market|string $market, mixed $amount): User
    {
        $market = $this->resolveMarket($market);
        $this->assertResellerHasMarketAccess($reseller, $market);

        $normalized = $this->normalizeMoneyValue($amount, allowNull: true);

        if ($normalized === null) {
            return $this->clearResellerBaseOverride($reseller, $market);
        }

        ResellerMarketNonDeliveryBaseFineOverride::query()->updateOrCreate(
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

    public function clearResellerBaseOverride(User $reseller, Market|string $market): User
    {
        $market = $this->resolveMarket($market);

        ResellerMarketNonDeliveryBaseFineOverride::query()
            ->where('user_id', $reseller->id)
            ->where('market_id', $market->id)
            ->delete();

        return $reseller->fresh();
    }

    /**
     * Resolve the BASE fine amount for a reseller (override or market default).
     *
     * @throws ValidationException when the market base default is missing
     */
    public function resolveBaseFine(User $reseller, Market|string $market): string
    {
        $market = $this->resolveMarket($market);
        $override = $this->getResellerBaseOverride($reseller, $market);

        if ($override !== null) {
            return $override;
        }

        $base = $this->getSchedule($market)['base'];

        if ($base === null) {
            throw ValidationException::withMessages([
                'non_delivery_fine_base' => "Base non-delivery fine is not configured for the {$market->name} market.",
            ]);
        }

        return $base;
    }

    /**
     * @return Collection<int, array{
     *     market: Market,
     *     schedule: array{
     *         base: string|null,
     *         after_hours: string|null,
     *         processing_override: string|null,
     *         combined: string|null,
     *         configured: bool,
     *         missing_keys: list<string>
     *     },
     *     base_override: string|null,
     *     effective_base: string|null,
     *     uses_market_default: bool
     * }>
     */
    public function buildResellerProfileContext(User $reseller): Collection
    {
        $reseller->loadMissing('company.allowedMarkets.country', 'company.allowedMarkets.currency');

        return $reseller->company?->allowedMarkets
            ?->sortBy('name')
            ->map(function (Market $market) use ($reseller): array {
                $schedule = $this->getSchedule($market);
                $override = $this->getResellerBaseOverride($reseller, $market);

                return [
                    'market' => $market,
                    'schedule' => $schedule,
                    'base_override' => $override,
                    'effective_base' => $override ?? $schedule['base'],
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

    protected function readAmount(string $key, Market $market): ?string
    {
        $value = $this->settingsService->getForMarket($key, (int) $market->id);

        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeMoneyValue((string) $value);
    }

    protected function normalizeRequiredMoney(mixed $amount, string $field): string
    {
        $normalized = $this->normalizeMoneyValue($amount, allowNull: true);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => 'The fine amount is required.',
            ]);
        }

        return $normalized;
    }

    protected function normalizeMoneyValue(mixed $amount, bool $allowNull = false): ?string
    {
        if ($amount === null || $amount === '') {
            return $allowNull ? null : '0.00';
        }

        $string = trim((string) $amount);

        if ($string === '') {
            return $allowNull ? null : '0.00';
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
