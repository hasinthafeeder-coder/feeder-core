<?php

namespace Feeder\Core\Support;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Allocates market-scoped sequential product variation barcodes (M + NNNNN).
 */
class ProductVariantBarcodeGenerator
{
    public function __construct(
        private readonly MarketBusinessDateResolver $businessDateResolver,
    ) {
    }

    public function allocateNext(int|Market $market): string
    {
        $marketModel = $market instanceof Market
            ? $market
            : Market::query()->findOrFail($market);

        $marketNumber = $this->businessDateResolver->assertHasMarketNumber($marketModel);

        return DB::transaction(function () use ($marketModel, $marketNumber): string {
            $row = DB::table('product_barcode_sequences')
                ->where('market_id', $marketModel->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('product_barcode_sequences')->insertOrIgnore([
                    'market_id' => $marketModel->id,
                    'last_value' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('product_barcode_sequences')
                    ->where('market_id', $marketModel->id)
                    ->lockForUpdate()
                    ->first();
            }

            if ($row === null) {
                throw new RuntimeException('Unable to initialize product barcode sequence for market '.$marketModel->id);
            }

            $next = (int) $row->last_value + 1;

            if ($next > 99999) {
                throw ValidationException::withMessages([
                    'barcode' => ['Product barcode sequence exhausted for this market (maximum 99999).'],
                ]);
            }

            DB::table('product_barcode_sequences')
                ->where('market_id', $marketModel->id)
                ->update([
                    'last_value' => $next,
                    'updated_at' => now(),
                ]);

            $barcode = CommercialIdentifier::formatProductBarcode($marketNumber, $next);

            if (! ProductVariantBarcodeGenerator::isAvailable($barcode)) {
                throw new RuntimeException("Generated product barcode [{$barcode}] already exists.");
            }

            return $barcode;
        });
    }

    /**
     * @deprecated Use allocateNext(). Kept for transitional call sites that still pass reserved lists.
     *
     * @param  list<string>  $reservedBarcodes
     */
    public static function generateUnique(?int $excludeVariantId = null, array $reservedBarcodes = []): string
    {
        unset($excludeVariantId, $reservedBarcodes);

        throw new RuntimeException(
            'ProductVariantBarcodeGenerator::generateUnique() is retired. Use allocateNext(market) via the container.'
        );
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
