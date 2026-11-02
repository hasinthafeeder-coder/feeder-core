<?php

namespace Feeder\Core\Services\Settlement;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\User;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Support\Facades\DB;

/**
 * Creates or retrieves market/date-scoped settlement batches.
 *
 * Enforces one non-voided DAILY batch per market/local date and serializes
 * concurrent create requests via a market row lock.
 *
 * Settlement writer lock order: market → batch (compatible with generation/lock).
 */
class SupplierSettlementBatchService
{
    public function __construct(
        private readonly MarketBusinessDateResolver $businessDates,
        private readonly SupplierSettlementNumberGenerator $numbers,
    ) {}

    public function createOrGetDailyBatch(
        Market $market,
        User $actor,
        ?CarbonInterface $at = null,
        string $generationSource = SupplierSettlementBatch::GENERATION_SOURCE_MANUAL,
    ): SupplierSettlementBatch {
        $this->assertGenerationSource($generationSource);

        return DB::transaction(function () use ($market, $actor, $at, $generationSource) {
            $lockedMarket = Market::query()->whereKey($market->id)->lockForUpdate()->firstOrFail();
            $settlementDate = $this->businessDates->businessDateString($lockedMarket, $at);
            $timezone = $this->businessDates->timezoneFor($lockedMarket);

            $existing = SupplierSettlementBatch::query()
                ->where('market_id', $lockedMarket->id)
                ->whereDate('settlement_date', $settlementDate)
                ->where('kind', SupplierSettlementBatch::KIND_DAILY)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return $this->insertBatch(
                $lockedMarket,
                $settlementDate,
                $timezone,
                SupplierSettlementBatch::KIND_DAILY,
                1,
                $actor,
                $generationSource,
            );
        });
    }

    public function createCatchUpBatch(
        Market $market,
        User $actor,
        ?CarbonInterface $settlementDate = null,
        string $generationSource = SupplierSettlementBatch::GENERATION_SOURCE_MANUAL,
    ): SupplierSettlementBatch {
        $this->assertGenerationSource($generationSource);

        return DB::transaction(function () use ($market, $actor, $settlementDate, $generationSource) {
            $lockedMarket = Market::query()->whereKey($market->id)->lockForUpdate()->firstOrFail();
            $date = $settlementDate === null
                ? $this->businessDates->businessDateString($lockedMarket)
                : CarbonImmutable::instance($settlementDate)->toDateString();
            $timezone = $this->businessDates->timezoneFor($lockedMarket);

            $maxSequence = (int) SupplierSettlementBatch::query()
                ->where('market_id', $lockedMarket->id)
                ->whereDate('settlement_date', $date)
                ->where('kind', SupplierSettlementBatch::KIND_CATCH_UP)
                ->lockForUpdate()
                ->max('sequence');

            $sequence = $maxSequence + 1;

            return $this->insertBatch(
                $lockedMarket,
                $date,
                $timezone,
                SupplierSettlementBatch::KIND_CATCH_UP,
                $sequence,
                $actor,
                $generationSource,
            );
        });
    }

    private function insertBatch(
        Market $market,
        string $settlementDate,
        string $timezone,
        string $kind,
        int $sequence,
        User $actor,
        string $generationSource,
    ): SupplierSettlementBatch {
        $marketCode = strtoupper((string) $market->code);

        return SupplierSettlementBatch::query()->create([
            'batch_number' => $this->numbers->batchNumber($marketCode, $settlementDate, $kind, $sequence),
            'market_id' => $market->id,
            'market_code_snapshot' => $marketCode,
            'timezone_snapshot' => $timezone,
            'settlement_date' => $settlementDate,
            'kind' => $kind,
            'sequence' => $sequence,
            'status' => SupplierSettlementBatch::STATUS_OPEN,
            'generation_source' => $generationSource,
            'generated_by' => $actor->id,
            'generated_at' => now(),
        ]);
    }

    private function assertGenerationSource(string $generationSource): void
    {
        $allowed = [
            SupplierSettlementBatch::GENERATION_SOURCE_MANUAL,
            SupplierSettlementBatch::GENERATION_SOURCE_SCHEDULED,
        ];

        if (! in_array($generationSource, $allowed, true)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVALID_GENERATION_SOURCE',
                'Unsupported settlement generation source.',
                ['generation_source' => $generationSource],
            );
        }
    }
}
