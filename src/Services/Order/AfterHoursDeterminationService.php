<?php

namespace Feeder\Core\Services\Order;

use Carbon\CarbonImmutable;
use Feeder\Core\Models\Market;
use Feeder\Core\Services\SettingsService;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative after-hours determination for order confirmation.
 *
 * Default operating window (inclusive): 07:00 through 21:00 in the market timezone.
 * Start/end clocks are per-market financial settings; missing settings fall back to
 * the defaults above. Penalty amounts are market financial settings, never hard-coded.
 *
 * Markets currently have no timezone column. Timezone is resolved from the market
 * code / country geography map below until a schema field is introduced.
 */
class AfterHoursDeterminationService
{
    public const PENALTY_SETTING_KEY = 'after_hours_penalty';

    public const OPERATING_START_SETTING_KEY = 'operating_hours_start';

    public const OPERATING_END_SETTING_KEY = 'operating_hours_end';

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
     * @return array{
     *     after_hours: bool,
     *     after_hours_penalty_amount: float,
     *     timezone: string,
     *     local_time: string,
     *     operating_start: string,
     *     operating_end: string
     * }
     */
    public function determine(Market $market, ?CarbonImmutable $at = null): array
    {
        $timezone = $this->resolveTimezone($market);
        $window = $this->resolveOperatingWindow($market);
        $local = ($at ?? CarbonImmutable::now('UTC'))->timezone($timezone);
        $afterHours = $this->isAfterHours($local, $window['start'], $window['end']);
        $penalty = $afterHours ? $this->resolvePenaltyAmount($market) : 0.0;

        return [
            'after_hours' => $afterHours,
            'after_hours_penalty_amount' => $penalty,
            'timezone' => $timezone,
            'local_time' => $local->format('H:i'),
            'operating_start' => $window['start'],
            'operating_end' => $window['end'],
        ];
    }

    /**
     * @return array{start: string, end: string, timezone: string, configured: bool}
     */
    public function getOperatingWindow(Market $market): array
    {
        $window = $this->resolveOperatingWindow($market);

        return [
            'start' => $window['start'],
            'end' => $window['end'],
            'timezone' => $this->resolveTimezone($market),
            'configured' => $window['configured'],
        ];
    }

    /**
     * Persist inclusive HH:MM operating window for a market.
     *
     * @return array{start: string, end: string}
     */
    public function setOperatingWindow(Market $market, string $start, string $end): array
    {
        $normalizedStart = $this->normalizeClock($start, 'operating_start');
        $normalizedEnd = $this->normalizeClock($end, 'operating_end');

        if ($this->minutesFromClock($normalizedStart) >= $this->minutesFromClock($normalizedEnd)) {
            throw ValidationException::withMessages([
                'operating_end' => ['Operating end must be later than operating start on the same day.'],
            ]);
        }

        $this->settingsService->setForMarket(
            self::OPERATING_START_SETTING_KEY,
            (int) $market->id,
            $normalizedStart,
            'financial',
            "Operating hours start for the {$market->name} market."
        );

        $this->settingsService->setForMarket(
            self::OPERATING_END_SETTING_KEY,
            (int) $market->id,
            $normalizedEnd,
            'financial',
            "Operating hours end for the {$market->name} market."
        );

        return [
            'start' => $normalizedStart,
            'end' => $normalizedEnd,
        ];
    }

    public function hasOperatingWindow(Market $market): bool
    {
        return $this->settingsService->existsForMarket(self::OPERATING_START_SETTING_KEY, (int) $market->id)
            && $this->settingsService->existsForMarket(self::OPERATING_END_SETTING_KEY, (int) $market->id);
    }

    /**
     * Seed default 07:00–21:00 when the market has no configured window.
     */
    public function seedDefaultOperatingWindow(Market $market): void
    {
        if ($this->hasOperatingWindow($market)) {
            return;
        }

        $this->setOperatingWindow($market, self::OPERATING_START, self::OPERATING_END);
    }

    public function isAfterHours(
        CarbonImmutable $localTime,
        ?string $operatingStart = null,
        ?string $operatingEnd = null,
    ): bool {
        $minutes = ((int) $localTime->format('H') * 60) + (int) $localTime->format('i');
        $start = $this->minutesFromClock($operatingStart ?? self::OPERATING_START);
        $end = $this->minutesFromClock($operatingEnd ?? self::OPERATING_END);

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

    /**
     * @return array{start: string, end: string, configured: bool}
     */
    private function resolveOperatingWindow(Market $market): array
    {
        $startRaw = $this->settingsService->getForMarket(self::OPERATING_START_SETTING_KEY, (int) $market->id);
        $endRaw = $this->settingsService->getForMarket(self::OPERATING_END_SETTING_KEY, (int) $market->id);

        $hasStart = is_string($startRaw) && trim($startRaw) !== '';
        $hasEnd = is_string($endRaw) && trim($endRaw) !== '';

        if ($hasStart && $hasEnd) {
            return [
                'start' => $this->normalizeClock((string) $startRaw, 'operating_start'),
                'end' => $this->normalizeClock((string) $endRaw, 'operating_end'),
                'configured' => true,
            ];
        }

        return [
            'start' => self::OPERATING_START,
            'end' => self::OPERATING_END,
            'configured' => false,
        ];
    }

    private function normalizeClock(string $value, string $field): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $trimmed, $matches)) {
            throw ValidationException::withMessages([
                $field => ['Operating hours must use HH:MM (24-hour) format.'],
            ]);
        }

        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }

    private function minutesFromClock(string $hhmm): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $hhmm));

        return ($hour * 60) + $minute;
    }
}
