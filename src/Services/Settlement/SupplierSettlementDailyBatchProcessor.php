<?php

namespace Feeder\Core\Services\Settlement;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\User;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates and generates supplier settlement batches for eligible markets.
 *
 * The reference business date is the market-local date of $at (or now). The
 * same run also discovers other business dates that still have unbatched
 * payable items. Dates with no non-voided daily batch receive a DAILY batch.
 * Dates whose daily batch is no longer OPEN receive a CATCH_UP batch so the
 * locked daily snapshot is left unchanged. Payables, supplier/currency
 * grouping, and duplicate prevention stay in the generation service.
 *
 * Does not lock batches, prepare bank exports, record payments, or close batches.
 */
class SupplierSettlementDailyBatchProcessor
{
    public const SKIP_REASON_NOTHING_ELIGIBLE = 'No eligible unbatched supplier payables for this business date.';

    public function __construct(
        private readonly SupplierSettlementBatchService $batches,
        private readonly SupplierSettlementGenerationService $generation,
        private readonly MarketBusinessDateResolver $businessDates,
        private readonly SupplierSettlementPayableEligibilityEvaluator $eligibility,
    ) {}

    /**
     * @param  list<int>|null  $marketIds
     * @return array{
     *     success_market_count: int,
     *     failed_market_count: int,
     *     skipped_market_count: int,
     *     markets: list<array<string, mixed>>
     * }
     */
    public function process(
        User $actor,
        ?CarbonInterface $at = null,
        ?array $marketIds = null,
        string $generationSource = SupplierSettlementBatch::GENERATION_SOURCE_SCHEDULED,
    ): array {
        $moment = $at === null ? null : CarbonImmutable::instance($at);
        $markets = $this->eligibleMarkets($marketIds);

        $results = [];
        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($markets as $market) {
            try {
                $outstanding = $this->eligibility->collectOutstandingPayableCandidates($market);
                $byDate = $this->groupOutstandingByDate($outstanding['candidates']);
            } catch (Throwable $exception) {
                report($exception);
                $this->tally($this->record($results, $this->unexpectedFailure(
                    $market,
                    $this->safeBusinessDate($market, $moment),
                    $exception,
                )), $successCount, $failedCount, $skippedCount);

                continue;
            }

            $referenceDate = $this->businessDates->businessDateString($market, $moment);
            $marketResult = $this->processMarket(
                $market,
                $actor,
                $moment,
                $generationSource,
                count($byDate[$referenceDate] ?? []),
            );
            $this->tally($this->record($results, $marketResult), $successCount, $failedCount, $skippedCount);

            $dates = array_keys($byDate);
            sort($dates);

            foreach ($dates as $date) {
                if (! $this->shouldProcessOutstandingDate($date, $referenceDate, $marketResult)) {
                    continue;
                }

                $this->tally($this->record($results, $this->processOutstandingDate(
                    $market,
                    $actor,
                    $date,
                    $generationSource,
                )), $successCount, $failedCount, $skippedCount);
            }
        }

        $summary = [
            'success_market_count' => $successCount,
            'failed_market_count' => $failedCount,
            'skipped_market_count' => $skippedCount,
            'markets' => $results,
        ];

        Log::info('supplier_settlement.daily_batch_processor.completed', [
            'success_market_count' => $successCount,
            'failed_market_count' => $failedCount,
            'skipped_market_count' => $skippedCount,
            'market_count' => count($results),
            'generation_source' => $generationSource,
        ]);

        return $summary;
    }

    /**
     * @param  list<int>|null  $marketIds
     * @return \Illuminate\Support\Collection<int, Market>
     */
    private function eligibleMarkets(?array $marketIds)
    {
        $query = Market::query()->orderBy('id');

        if ($marketIds !== null && $marketIds !== []) {
            $query->whereIn('id', $marketIds);
        }

        if ($this->marketsHaveIsActiveColumn()) {
            $query->where('is_active', true);
        }

        return $query->get()->filter(function (Market $market): bool {
            try {
                $this->businessDates->timezoneFor($market);

                return true;
            } catch (Throwable) {
                Log::warning('supplier_settlement.daily_batch_processor.market_timezone_missing', [
                    'market_id' => $market->id,
                    'market_code' => $market->code,
                ]);

                return false;
            }
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function processMarket(
        Market $market,
        User $actor,
        ?CarbonImmutable $at,
        string $generationSource,
        int $outstandingItemCount,
    ): array {
        $timezone = $this->businessDates->timezoneFor($market);
        $settlementDate = $this->businessDates->businessDateString($market, $at);

        $existingDaily = $this->nonVoidedDailyBatch($market, $settlementDate);
        if ($outstandingItemCount === 0
            && ! ($existingDaily instanceof SupplierSettlementBatch)
            && ! $this->voidedDailyOccupies($market, $settlementDate)) {
            $payload = $this->skippedPayload(
                $market,
                $timezone,
                $settlementDate,
                null,
                self::SKIP_REASON_NOTHING_ELIGIBLE,
            );

            Log::info('supplier_settlement.daily_batch_processor.market_skipped', $payload);

            return $payload;
        }

        try {
            $batch = $this->batches->createOrGetDailyBatch(
                $market,
                $actor,
                $at,
                $generationSource,
            );

            if ($batch->status !== SupplierSettlementBatch::STATUS_OPEN) {
                $payload = $this->skippedPayload(
                    $market,
                    $timezone,
                    $settlementDate,
                    $batch,
                    'Batch exists but is not OPEN; generation skipped.',
                );

                Log::info('supplier_settlement.daily_batch_processor.market_skipped', $payload);

                return $payload;
            }

            $generation = $this->generation->generate($batch);
            $payload = $this->successPayload($market, $timezone, $settlementDate, $generation);

            Log::info('supplier_settlement.daily_batch_processor.market_success', $payload);

            return $payload;
        } catch (SupplierSettlementException $exception) {
            return $this->logFailure($this->failedPayload($market, $timezone, $settlementDate, $exception->errorCode, $exception->getMessage(), $exception->details));
        } catch (Throwable $exception) {
            report($exception);

            return $this->logFailure($this->failedPayload(
                $market,
                $timezone,
                $settlementDate,
                'SETTLEMENT_DAILY_UNEXPECTED',
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function processOutstandingDate(
        Market $market,
        User $actor,
        string $settlementDate,
        string $generationSource,
    ): array {
        $timezone = $this->businessDates->timezoneFor($market);
        $at = CarbonImmutable::parse($settlementDate.' 12:00:00', $timezone);

        try {
            $resolvedDate = $this->businessDates->businessDateString($market, $at);
            if ($resolvedDate !== $settlementDate) {
                return $this->logFailure($this->failedPayload(
                    $market,
                    $timezone,
                    $settlementDate,
                    'SETTLEMENT_BUSINESS_DATE_MISMATCH',
                    'Outstanding receipt business date could not be resolved for batch generation.',
                ));
            }

            $daily = $this->nonVoidedDailyBatch($market, $settlementDate);
            if ($daily === null || $daily->status === SupplierSettlementBatch::STATUS_OPEN) {
                $batch = $this->batches->createOrGetDailyBatch($market, $actor, $at, $generationSource);
                if ($batch->status === SupplierSettlementBatch::STATUS_OPEN) {
                    $payload = $this->successPayload(
                        $market,
                        $timezone,
                        $settlementDate,
                        $this->generation->generate($batch),
                    );

                    Log::info('supplier_settlement.daily_batch_processor.outstanding_success', $payload);

                    return $payload;
                }
            }

            $openCatchUp = SupplierSettlementBatch::query()
                ->where('market_id', $market->id)
                ->whereDate('settlement_date', $settlementDate)
                ->where('kind', SupplierSettlementBatch::KIND_CATCH_UP)
                ->where('status', SupplierSettlementBatch::STATUS_OPEN)
                ->whereNull('voided_at')
                ->orderBy('sequence')
                ->first();

            $batch = $openCatchUp ?? $this->batches->createCatchUpBatch(
                $market,
                $actor,
                CarbonImmutable::parse($settlementDate.' 00:00:00', $timezone),
                $generationSource,
            );

            if ($batch->status !== SupplierSettlementBatch::STATUS_OPEN || $batch->voided_at !== null) {
                $payload = $this->skippedPayload(
                    $market,
                    $timezone,
                    $settlementDate,
                    $batch,
                    'Outstanding items remain, but no OPEN catch-up batch could be generated.',
                );

                Log::info('supplier_settlement.daily_batch_processor.outstanding_skipped', $payload);

                return $payload;
            }

            $payload = $this->successPayload(
                $market,
                $timezone,
                $settlementDate,
                $this->generation->generate($batch),
            );

            Log::info('supplier_settlement.daily_batch_processor.outstanding_catch_up_success', $payload);

            return $payload;
        } catch (SupplierSettlementException $exception) {
            return $this->logFailure($this->failedPayload(
                $market,
                $timezone,
                $settlementDate,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
            ));
        } catch (Throwable $exception) {
            report($exception);

            return $this->logFailure($this->failedPayload(
                $market,
                $timezone,
                $settlementDate,
                'SETTLEMENT_DAILY_UNEXPECTED',
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $marketResult
     */
    private function shouldProcessOutstandingDate(string $date, string $referenceDate, array $marketResult): bool
    {
        if ($date !== $referenceDate) {
            return true;
        }

        return ($marketResult['status'] ?? '') === 'skipped'
            && ($marketResult['reason'] ?? '') !== self::SKIP_REASON_NOTHING_ELIGIBLE;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupOutstandingByDate(array $candidates): array
    {
        $grouped = [];

        foreach ($candidates as $candidate) {
            $date = (string) ($candidate['receipt_business_date'] ?? '');
            if ($date === '') {
                continue;
            }

            $grouped[$date][] = $candidate;
        }

        ksort($grouped);

        return $grouped;
    }

    private function nonVoidedDailyBatch(Market $market, string $settlementDate): ?SupplierSettlementBatch
    {
        return SupplierSettlementBatch::query()
            ->where('market_id', $market->id)
            ->whereDate('settlement_date', $settlementDate)
            ->where('kind', SupplierSettlementBatch::KIND_DAILY)
            ->whereNull('voided_at')
            ->first();
    }

    private function voidedDailyOccupies(Market $market, string $settlementDate): bool
    {
        return SupplierSettlementBatch::query()
            ->where('market_id', $market->id)
            ->whereDate('settlement_date', $settlementDate)
            ->where('kind', SupplierSettlementBatch::KIND_DAILY)
            ->whereNotNull('voided_at')
            ->exists();
    }

    /**
     * @param  array{
     *     batch: SupplierSettlementBatch,
     *     invoice_count: int,
     *     payable_line_count: int,
     *     payable_total: string,
     *     payable_totals_by_currency: array<string, string>,
     *     skipped_candidate_count: int,
     *     generation_exceptions: list<array<string, mixed>>
     * }  $generation
     * @return array<string, mixed>
     */
    private function successPayload(Market $market, string $timezone, string $settlementDate, array $generation): array
    {
        return [
            'status' => 'success',
            'market_id' => (int) $market->id,
            'market_code' => (string) $market->code,
            'timezone' => $timezone,
            'settlement_date' => $settlementDate,
            'batch_id' => (int) $generation['batch']->id,
            'batch_number' => (string) $generation['batch']->batch_number,
            'batch_status' => (string) $generation['batch']->status,
            'batch_kind' => (string) $generation['batch']->kind,
            'invoice_count' => (int) $generation['invoice_count'],
            'payable_line_count' => (int) $generation['payable_line_count'],
            'payable_total' => (string) $generation['payable_total'],
            'payable_totals_by_currency' => $generation['payable_totals_by_currency'],
            'skipped_candidate_count' => (int) $generation['skipped_candidate_count'],
            'generation_exceptions' => $generation['generation_exceptions'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skippedPayload(
        Market $market,
        string $timezone,
        string $settlementDate,
        ?SupplierSettlementBatch $batch,
        string $reason,
    ): array {
        return [
            'status' => 'skipped',
            'market_id' => (int) $market->id,
            'market_code' => (string) $market->code,
            'timezone' => $timezone,
            'settlement_date' => $settlementDate,
            'batch_id' => $batch === null ? null : (int) $batch->id,
            'batch_number' => $batch === null ? null : (string) $batch->batch_number,
            'batch_status' => $batch === null ? null : (string) $batch->status,
            'reason' => $reason,
            'invoice_count' => 0,
            'payable_line_count' => 0,
            'payable_total' => '0.00',
            'payable_totals_by_currency' => [],
            'skipped_candidate_count' => 0,
            'generation_exceptions' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function failedPayload(
        Market $market,
        string $timezone,
        string $settlementDate,
        string $errorCode,
        string $errorMessage,
        array $details = [],
    ): array {
        return [
            'status' => 'failed',
            'market_id' => (int) $market->id,
            'market_code' => (string) $market->code,
            'timezone' => $timezone,
            'settlement_date' => $settlementDate,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'error_details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unexpectedFailure(Market $market, string $settlementDate, Throwable $exception): array
    {
        $timezone = 'UTC';

        try {
            $timezone = $this->businessDates->timezoneFor($market);
        } catch (Throwable) {
            $timezone = 'UTC';
        }

        return $this->logFailure($this->failedPayload(
            $market,
            $timezone,
            $settlementDate,
            'SETTLEMENT_DAILY_UNEXPECTED',
            $exception->getMessage(),
        ));
    }

    private function safeBusinessDate(Market $market, ?CarbonImmutable $at): string
    {
        try {
            return $this->businessDates->businessDateString($market, $at);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function record(array &$results, array $result): array
    {
        $results[] = $result;

        return $result;
    }

    private function tally(array $result, int &$successCount, int &$failedCount, int &$skippedCount): void
    {
        if (($result['status'] ?? '') === 'success') {
            $successCount++;

            return;
        }

        if (($result['status'] ?? '') === 'skipped') {
            $skippedCount++;

            return;
        }

        $failedCount++;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function logFailure(array $payload): array
    {
        Log::error('supplier_settlement.daily_batch_processor.market_failed', $payload);

        return $payload;
    }

    private function marketsHaveIsActiveColumn(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('markets', 'is_active');
        } catch (Throwable) {
            return false;
        }
    }
}
