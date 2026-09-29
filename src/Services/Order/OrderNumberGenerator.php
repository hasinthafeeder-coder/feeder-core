<?php

namespace Feeder\Core\Services\Order;

use Carbon\CarbonInterface;
use Feeder\Core\Models\Market;
use Feeder\Core\Support\CommercialIdentifier;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Allocates market-local daily sequential order / invoice numbers (M + YY + DDD + NNNNN).
 */
class OrderNumberGenerator
{
    public function __construct(
        private readonly MarketBusinessDateResolver $businessDateResolver,
    ) {
    }

    public function allocateNext(Market $market, ?CarbonInterface $at = null): string
    {
        $marketNumber = $this->businessDateResolver->assertHasMarketNumber($market);
        $businessDate = $this->businessDateResolver->resolve($market, $at);

        return DB::transaction(function () use ($market, $marketNumber, $businessDate): string {
            $dateString = $businessDate->toDateString();

            $row = DB::table('order_number_sequences')
                ->where('market_id', $market->id)
                ->where('business_date', $dateString)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('order_number_sequences')->insertOrIgnore([
                    'market_id' => $market->id,
                    'business_date' => $dateString,
                    'last_value' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('order_number_sequences')
                    ->where('market_id', $market->id)
                    ->where('business_date', $dateString)
                    ->lockForUpdate()
                    ->first();
            }

            if ($row === null) {
                throw new RuntimeException(
                    'Unable to initialize order number sequence for market '.$market->id.' on '.$dateString
                );
            }

            $next = (int) $row->last_value + 1;

            if ($next > 99999) {
                throw ValidationException::withMessages([
                    'order_number' => ['Daily order number sequence exhausted for this market (maximum 99999).'],
                ]);
            }

            DB::table('order_number_sequences')
                ->where('id', $row->id)
                ->update([
                    'last_value' => $next,
                    'updated_at' => now(),
                ]);

            $yy = (int) $businessDate->format('y');
            $ddd = ((int) $businessDate->format('z')) + 1;

            return CommercialIdentifier::formatOrderNumber($marketNumber, $yy, $ddd, $next);
        });
    }
}
