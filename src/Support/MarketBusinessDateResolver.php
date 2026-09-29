<?php

namespace Feeder\Core\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Feeder\Core\Models\Market;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves the market-local calendar date used for commercial order identifiers.
 */
final class MarketBusinessDateResolver
{
    /**
     * @var array<string, string>
     */
    private const DEFAULT_TIMEZONES = [
        'lk' => 'Asia/Colombo',
        'my' => 'Asia/Kuala_Lumpur',
    ];

    public function timezoneFor(Market $market): string
    {
        $code = strtolower(trim((string) $market->code));
        $configured = config('feeder.markets.timezones', []);

        if (is_array($configured) && isset($configured[$code]) && is_string($configured[$code]) && $configured[$code] !== '') {
            return $configured[$code];
        }

        if (isset(self::DEFAULT_TIMEZONES[$code])) {
            return self::DEFAULT_TIMEZONES[$code];
        }

        throw new RuntimeException("No business timezone is configured for market [{$market->code}].");
    }

    public function resolve(Market $market, ?CarbonInterface $at = null): CarbonImmutable
    {
        $timezone = $this->timezoneFor($market);
        $moment = $at === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($at)->utc();

        return $moment->timezone($timezone)->startOfDay();
    }

    public function businessDateString(Market $market, ?CarbonInterface $at = null): string
    {
        return $this->resolve($market, $at)->toDateString();
    }

    public function assertHasMarketNumber(Market $market): int
    {
        $number = $market->market_number;

        if ($number === null || (int) $number < 1 || (int) $number > 9) {
            throw new InvalidArgumentException(
                "Market [{$market->code}] is missing a valid market_number required for identifier generation."
            );
        }

        return (int) $number;
    }
}
