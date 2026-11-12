<?php

namespace Feeder\Core\Support;

use Illuminate\Support\Carbon;

/**
 * Wall-clock timezone used by Feeder admin datetime-local inputs.
 *
 * Application storage/comparison remains UTC (config app.timezone). Admin forms
 * collect Sri Lanka local times; parse them here before persisting.
 */
final class BusinessTimezone
{
    public const ID = 'Asia/Colombo';

    /**
     * Parse a naive admin datetime (datetime-local / "Y-m-d H:i[:s]") as business
     * local time and return a UTC Carbon instance for storage.
     */
    public static function parseWallClockToUtc(string $value): Carbon
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1) {
            $value = str_replace('T', ' ', $value).':00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            $value = str_replace('T', ' ', $value);
        }

        return Carbon::parse($value, self::ID)->utc();
    }

    public static function formatForDateTimeLocal(?Carbon $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        return $moment->copy()->timezone(self::ID)->format('Y-m-d\TH:i');
    }

    public static function formatForDisplay(?Carbon $moment, string $format = 'Y-m-d H:i'): ?string
    {
        if ($moment === null) {
            return null;
        }

        return $moment->copy()->timezone(self::ID)->format($format);
    }
}
