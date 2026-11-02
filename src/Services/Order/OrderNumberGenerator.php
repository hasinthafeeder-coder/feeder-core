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
            $row = $this->lockSequence($market->id, $dateString);

            $yy = (int) $businessDate->format('y');
            $ddd = ((int) $businessDate->format('z')) + 1;
            $prefix = sprintf('%d%02d%03d', $marketNumber, $yy, $ddd);
            $next = max((int) $row->last_value, $this->highestStoredSuffix($prefix)) + 1;

            $number = null;
            while ($number === null) {
                if ($next > 99999) {
                    throw ValidationException::withMessages([
                        'order_number' => ['Daily order number sequence exhausted for this market (maximum 99999).'],
                    ]);
                }

                $candidate = CommercialIdentifier::formatOrderNumber($marketNumber, $yy, $ddd, $next);
                $taken = DB::table('orders')
                    ->where('order_number', $candidate)
                    ->lockForUpdate()
                    ->value('order_number');

                if ($taken === null) {
                    $number = $candidate;
                    break;
                }

                $next++;
            }

            DB::table('order_number_sequences')
                ->where('id', $row->id)
                ->update([
                    'last_value' => $next,
                    'updated_at' => now(),
                ]);

            return $number;
        });
    }

    /**
     * Serialize allocators on the unique market/date row before reading orders.
     *
     * insertOrIgnore plus a second lock covers a missing row: the unique key
     * makes the losing insert a no-op, and that transaction then waits for the
     * committed row. The suffix read below therefore cannot run unlocked.
     */
    private function lockSequence(int $marketId, string $businessDate): object
    {
        $row = DB::table('order_number_sequences')
            ->where('market_id', $marketId)
            ->where('business_date', $businessDate)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            DB::table('order_number_sequences')->insertOrIgnore([
                'market_id' => $marketId,
                'business_date' => $businessDate,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('order_number_sequences')
                ->where('market_id', $marketId)
                ->where('business_date', $businessDate)
                ->lockForUpdate()
                ->first();
        }

        if ($row === null) {
            throw new RuntimeException(
                'Unable to initialize order number sequence for market '.$marketId.' on '.$businessDate
            );
        }

        return $row;
    }

    /**
     * Highest five-digit suffix already stored for this market, year, and day.
     *
     * Called only while the sequence row lock is held.
     */
    private function highestStoredSuffix(string $prefix): int
    {
        $highest = DB::table('orders')
            ->where('order_number', 'like', $prefix.'_____')
            ->orderByDesc('order_number')
            ->lockForUpdate()
            ->value('order_number');

        if (! is_string($highest) || ! str_starts_with($highest, $prefix) || strlen($highest) !== 11) {
            return 0;
        }

        $suffix = (int) substr($highest, strlen($prefix));

        return ($suffix >= 1 && $suffix <= 99999) ? $suffix : 0;
    }
}
