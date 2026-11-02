<?php

namespace Feeder\Core\Services\Settlement;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Feeder\Core\Exceptions\ResellerSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\User;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or retrieves market/date-scoped reseller settlement batches.
 *
 * Enforces one non-voided DAILY batch per market, local date, and report type,
 * and serializes concurrent create requests via a market row lock.
 *
 * Writer lock order: market → batch.
 */
class ResellerSettlementBatchService
{
    public function __construct(
        private readonly MarketBusinessDateResolver $businessDates,
        private readonly ResellerSettlementNumberGenerator $numbers,
    ) {}

    public function createOrGetDailyBatch(
        Market $market,
        User $actor,
        ?CarbonInterface $at = null,
        string $generationSource = ResellerSettlementBatch::GENERATION_SOURCE_MANUAL,
        string $reportType = ResellerSettlementBatch::REPORT_TYPE_COMBINED,
    ): ResellerSettlementBatch {
        $this->assertGenerationSource($generationSource);
        $this->assertReportType($reportType);

        return DB::transaction(function () use ($market, $actor, $at, $generationSource, $reportType) {
            $lockedMarket = Market::query()->whereKey($market->id)->lockForUpdate()->firstOrFail();
            $settlementDate = $this->businessDates->businessDateString($lockedMarket, $at);
            $timezone = $this->businessDates->timezoneFor($lockedMarket);

            $existing = $this->nonVoidedDailyQuery($lockedMarket, $settlementDate, $reportType)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                return $this->insertBatch(
                    $lockedMarket,
                    $settlementDate,
                    $timezone,
                    ResellerSettlementBatch::KIND_DAILY,
                    1,
                    $actor,
                    $generationSource,
                    $reportType,
                );
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                // Another creator committed the daily row after our existence check,
                // or the unique key fired under the market lock. Return that batch
                // instead of leaving the caller with a raw duplicate-key failure.
                $winner = $this->nonVoidedDailyQuery($lockedMarket, $settlementDate, $reportType)
                    ->lockForUpdate()
                    ->first();

                if ($winner !== null) {
                    return $winner;
                }

                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_DAILY_BATCH_CONFLICT',
                    'Could not create or load the daily reseller settlement batch for this market and date.',
                    [
                        'market_id' => $lockedMarket->id,
                        'settlement_date' => $settlementDate,
                        'report_type' => $reportType,
                    ],
                    409,
                );
            }
        });
    }

    public function createCatchUpBatch(
        Market $market,
        User $actor,
        ?CarbonInterface $settlementDate = null,
        string $generationSource = ResellerSettlementBatch::GENERATION_SOURCE_MANUAL,
        string $reportType = ResellerSettlementBatch::REPORT_TYPE_COMBINED,
    ): ResellerSettlementBatch {
        $this->assertGenerationSource($generationSource);
        $this->assertReportType($reportType);

        return DB::transaction(function () use ($market, $actor, $settlementDate, $generationSource, $reportType) {
            $lockedMarket = Market::query()->whereKey($market->id)->lockForUpdate()->firstOrFail();
            $date = $settlementDate === null
                ? $this->businessDates->businessDateString($lockedMarket)
                : CarbonImmutable::instance($settlementDate)->toDateString();
            $timezone = $this->businessDates->timezoneFor($lockedMarket);

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $maxSequence = (int) ResellerSettlementBatch::query()
                    ->where('market_id', $lockedMarket->id)
                    ->whereDate('settlement_date', $date)
                    ->where('kind', ResellerSettlementBatch::KIND_CATCH_UP)
                    ->where('report_type', $reportType)
                    ->lockForUpdate()
                    ->max('sequence');

                try {
                    return $this->insertBatch(
                        $lockedMarket,
                        $date,
                        $timezone,
                        ResellerSettlementBatch::KIND_CATCH_UP,
                        $maxSequence + 1,
                        $actor,
                        $generationSource,
                        $reportType,
                    );
                } catch (QueryException $exception) {
                    if (! $this->isUniqueViolation($exception)) {
                        throw $exception;
                    }
                }
            }

            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_CATCH_UP_BATCH_CONFLICT',
                'Could not create a unique catch-up reseller settlement batch.',
                [
                    'market_id' => $lockedMarket->id,
                    'settlement_date' => $date,
                    'report_type' => $reportType,
                ],
                409,
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
        string $reportType,
    ): ResellerSettlementBatch {
        $marketCode = strtoupper((string) $market->code);

        return ResellerSettlementBatch::query()->create([
            'batch_number' => $this->numbers->batchNumber($marketCode, $settlementDate, $kind, $sequence, $reportType),
            'market_id' => $market->id,
            'market_code_snapshot' => $marketCode,
            'timezone_snapshot' => $timezone,
            'settlement_date' => $settlementDate,
            'kind' => $kind,
            'report_type' => $reportType,
            'sequence' => $sequence,
            'status' => ResellerSettlementBatch::STATUS_OPEN,
            'generation_source' => $generationSource,
            'generated_by' => $actor->id,
            'generated_at' => now(),
        ]);
    }

    private function nonVoidedDailyQuery(Market $market, string $settlementDate, string $reportType)
    {
        return ResellerSettlementBatch::query()
            ->where('market_id', $market->id)
            ->whereDate('settlement_date', $settlementDate)
            ->where('kind', ResellerSettlementBatch::KIND_DAILY)
            ->where('report_type', $reportType)
            ->whereNull('voided_at');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $driverCode === 1062
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    private function assertGenerationSource(string $generationSource): void
    {
        $allowed = [
            ResellerSettlementBatch::GENERATION_SOURCE_MANUAL,
            ResellerSettlementBatch::GENERATION_SOURCE_SCHEDULED,
        ];

        if (! in_array($generationSource, $allowed, true)) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVALID_GENERATION_SOURCE',
                'Unsupported reseller settlement generation source.',
                ['generation_source' => $generationSource],
            );
        }
    }

    private function assertReportType(string $reportType): void
    {
        $allowed = [
            ResellerSettlementBatch::REPORT_TYPE_COMBINED,
            ResellerSettlementBatch::REPORT_TYPE_PLUS,
            ResellerSettlementBatch::REPORT_TYPE_MINUS,
        ];

        if (! in_array($reportType, $allowed, true)) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVALID_REPORT_TYPE',
                'Unsupported reseller settlement report type.',
                ['report_type' => $reportType],
            );
        }
    }
}
