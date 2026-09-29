<?php

namespace Feeder\Core\Support;

use Feeder\Core\Models\Market;
use InvalidArgumentException;

/**
 * Format helpers and structural validation for Feeder commercial identifiers.
 */
final class CommercialIdentifier
{
    public static function formatProductBarcode(int $marketNumber, int $sequence): string
    {
        self::assertMarketNumber($marketNumber);
        self::assertSequence($sequence, 'product barcode');

        return sprintf('%d%05d', $marketNumber, $sequence);
    }

    public static function formatOrderNumber(
        int $marketNumber,
        int $yearTwoDigits,
        int $dayOfYear,
        int $sequence,
    ): string {
        self::assertMarketNumber($marketNumber);
        self::assertSequence($sequence, 'order number');

        if ($yearTwoDigits < 0 || $yearTwoDigits > 99) {
            throw new InvalidArgumentException('Order year must be two digits (00-99).');
        }

        if ($dayOfYear < 1 || $dayOfYear > 366) {
            throw new InvalidArgumentException('Order day-of-year must be between 001 and 366.');
        }

        return sprintf(
            '%d%02d%03d%05d',
            $marketNumber,
            $yearTwoDigits,
            $dayOfYear,
            $sequence
        );
    }

    public static function isValidProductBarcodeForMarket(string $barcode, int $marketNumber): bool
    {
        self::assertMarketNumber($marketNumber);

        if (! preg_match('/^\d{6}$/', $barcode)) {
            return false;
        }

        return str_starts_with($barcode, (string) $marketNumber);
    }

    public static function isValidOrderNumberForMarket(string $orderNumber, int $marketNumber): bool
    {
        self::assertMarketNumber($marketNumber);

        if (! preg_match('/^\d{11}$/', $orderNumber)) {
            return false;
        }

        if (! str_starts_with($orderNumber, (string) $marketNumber)) {
            return false;
        }

        $dayOfYear = (int) substr($orderNumber, 3, 3);

        return $dayOfYear >= 1 && $dayOfYear <= 366;
    }

    public static function isLegacyOrderNumber(string $value): bool
    {
        return (bool) preg_match('/^ORD-\d{4}-\d+$/', trim($value));
    }

    public static function productBarcodePatternForMarket(Market $market): string
    {
        $number = (int) $market->market_number;

        self::assertMarketNumber($number);

        return '^'.$number.'[0-9]{5}$';
    }

    public static function orderNumberPatternForMarket(Market $market): string
    {
        $number = (int) $market->market_number;

        self::assertMarketNumber($number);

        return '^'.$number.'[0-9]{10}$';
    }

    private static function assertMarketNumber(int $marketNumber): void
    {
        if ($marketNumber < 1 || $marketNumber > 9) {
            throw new InvalidArgumentException('Market number must be a single digit from 1 to 9.');
        }
    }

    private static function assertSequence(int $sequence, string $label): void
    {
        if ($sequence < 1 || $sequence > 99999) {
            throw new InvalidArgumentException("The {$label} sequence must be between 1 and 99999.");
        }
    }
}
