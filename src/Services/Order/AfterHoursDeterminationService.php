<?php

namespace Feeder\Core\Services\Order;

use Carbon\CarbonImmutable;
use Feeder\Core\Models\Market;
use Feeder\Core\Services\SettingsService;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative after-hours determination for order creation.
 *
 * Operating window (inclusive): 07:00 through 21:00 in the market timezone.
 * Penalty amounts are market financial settings (settings table), never hard-coded.
 *
 * Markets currently have no timezone column. Timezone is resolved from the market
 * code / country geography map below until a schema field is introduced.
 */
class AfterHoursDeterminationService
{
    public const PENALTY_SETTING_KEY = 'after_hours_penalty';

    public const OPERATING_START = '07:00';

    public const OPERATING_END = '21:00';

    /**
     * Seeded market defaults in each market's native currency.
     *
     * @var array<string, string>
     */
    public const MARKET_DEFAULTS = [
        'lk' => '100.00',
        'my' => '20.00',
        'th' => '40.00',
    ];

    /**
     * Explicit IANA timezones per market code.
     *
     * @var array<string, string>
     */
    public const MARKET_TIMEZONES = [
        'lk' => 'Asia/Colombo',
        'my' => 'Asia/Kuala_Lumpur',
        'th' => 'Asia/Bangkok',
    ];

    public function __construct(
        private readonly SettingsService $settingsService,
    ) {
    }

    /**
     * @return array{after_hours: bool, after_hours_penalty_amount: float, timezone: string, local_time: string}
     */
    public function determine(Market $market, ?CarbonImmutable $at = null): array
    {
        $timezone = $this->resolveTimezone($market);
        $local = ($at ?? CarbonImmutable::now('UTC'))->timezone($timezone);
        $afterHours = $this->isAfterHours($local);
        $penalty = $afterHours ? $this->resolvePenaltyAmount($market) : 0.0;

        return [
            'after_hours' => $afterHours,
            'after_hours_penalty_amount' => $penalty,
            'timezone' => $timezone,
            'local_time' => $local->format('H:i'),
        ];
    }

    public function isAfterHours(CarbonImmutable $localTime): bool
    {
        $minutes = ((int) $localTime->format('H') * 60) + (int) $localTime->format('i');
        $start = $this->minutesFromClock(self::OPERATING_START);
        $end = $this->minutesFromClock(self::OPERATING_END);

        return $minutes < $start || $minutes > $end;
    }

    public function resolveTimezone(Market $market): string
    {
        $code = strtolower((string) $market->code);

        if (! isset(self::MARKET_TIMEZONES[$code])) {
            throw ValidationException::withMessages([
                'market_id' => ["No timezone mapping is configured for market [{$market->code}]."],
            ]);
        }

        return self::MARKET_TIMEZONES[$code];
    }

    public function resolvePenaltyAmount(Market $market): float
    {
        $value = $this->settingsService->getForMarket(self::PENALTY_SETTING_KEY, (int) $market->id);

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'after_hours_penalty_amount' => "After-hours penalty is not configured for the {$market->name} market.",
            ]);
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'after_hours_penalty_amount' => "After-hours penalty is invalid for the {$market->name} market.",
            ]);
        }

        $amount = round((float) $value, 2);

        if ($amount < 0) {
            throw ValidationException::withMessages([
                'after_hours_penalty_amount' => 'After-hours penalty cannot be negative.',
            ]);
        }

        return $amount;
    }

    public function setPenaltyAmount(Market $market, mixed $amount): string
    {
        $normalized = number_format((float) $amount, 2, '.', '');

        $this->settingsService->setForMarket(
            self::PENALTY_SETTING_KEY,
            (int) $market->id,
            $normalized,
            'financial',
            "After-hours order penalty for the {$market->name} market."
        );

        return $normalized;
    }

    public function hasPenaltyAmount(Market $market): bool
    {
        return $this->settingsService->existsForMarket(self::PENALTY_SETTING_KEY, (int) $market->id);
    }

    private function minutesFromClock(string $hhmm): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $hhmm));

        return ($hour * 60) + $minute;
    }
}
