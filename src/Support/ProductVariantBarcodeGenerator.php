<?php

namespace Feeder\Core\Support;

use Feeder\Core\Models\ProductVariant;
use Illuminate\Support\Str;

class ProductVariantBarcodeGenerator
{
    private const PREFIX = 'FEEDER-';

    private const RANDOM_LENGTH = 12;

    private const MAX_ATTEMPTS = 25;

    /**
     * @param  list<string>  $reservedBarcodes
     */
    public static function generateUnique(?int $excludeVariantId = null, array $reservedBarcodes = []): string
    {
        $reservedBarcodes = array_values(array_filter(array_map(
            static fn (mixed $barcode): string => trim((string) $barcode),
            $reservedBarcodes
        )));

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $barcode = self::PREFIX.strtoupper(Str::random(self::RANDOM_LENGTH));

            if (in_array($barcode, $reservedBarcodes, true)) {
                continue;
            }

            if (self::isAvailable($barcode, $excludeVariantId)) {
                return $barcode;
            }
        }

        return self::PREFIX.strtoupper(Str::random(16)).dechex(time());
    }

    public static function isAvailable(string $barcode, ?int $excludeVariantId = null): bool
    {
        $barcode = trim($barcode);

        if ($barcode === '') {
            return false;
        }

        $query = ProductVariant::query()->where('barcode', $barcode);

        if ($excludeVariantId !== null) {
            $query->whereKeyNot($excludeVariantId);
        }

        return ! $query->exists();
    }
}
