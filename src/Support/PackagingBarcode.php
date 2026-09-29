<?php

namespace Feeder\Core\Support;

/**
 * Fixed warehouse barcodes used by the Packaging Orders workspace.
 *
 * Product barcodes remain product_variants.barcode (6-digit market sequences).
 * Feeder Order ID barcodes remain orders.order_number (11-digit market sequences).
 * This constant is the single Complete Package trigger value for UI/backend contract.
 */
final class PackagingBarcode
{
    /**
     * Fixed static barcode scanned to complete the open packaging workspace.
     * Short alphabetic keyword so Code 128 stays narrow and scanner-friendly.
     * Must not collide with numeric product / order commercial identifiers.
     */
    public const COMPLETE_PACKAGE = 'FCP';

    /**
     * Previous Complete Package value. Accepted on scan so already-printed stickers keep working.
     *
     * @deprecated Prefer COMPLETE_PACKAGE ('FCP').
     */
    public const LEGACY_COMPLETE_PACKAGE = 'FEEDER-COMPLETE-PACKAGE';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function acceptedValues(): array
    {
        return [
            self::COMPLETE_PACKAGE,
            self::LEGACY_COMPLETE_PACKAGE,
        ];
    }

    public static function matches(string $value): bool
    {
        $normalized = strtoupper(trim($value));

        foreach (self::acceptedValues() as $accepted) {
            if ($normalized === strtoupper($accepted)) {
                return true;
            }
        }

        return false;
    }
}
