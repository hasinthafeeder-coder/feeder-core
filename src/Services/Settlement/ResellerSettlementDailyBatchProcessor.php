<?php

namespace Feeder\Core\Services\Settlement;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Feeder\Core\Exceptions\ResellerSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\User;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Creates Plus Report and Minus Report reseller batches independently.
 *
 * The reference business date is the market-local date of $at (or now). The
 * same run also discovers other business dates that still have unbatched
 * records. Plus uses persisted earning obligations. Minus uses outstanding
 * debt obligations and does not reduce those balances.
 *
 * A failure in one report type does not mark the other report type successful.
 * Does not lock batches or record payments.
 */
class ResellerSettlementDailyBatchProcessor
{
    public const SKIP_REASON_NOTHING_ELIGIBLE = 'No eligible unbatched records for this business date.';

    public function __construct(
        private readonly ResellerSettlementBatchService $batches,
        private readonly ResellerSettlementGenerationService $generation,
        private readonly ResellerSettlementDeductionReportGenerationService $deductions,
        private readonly ResellerSettlementEligibilityCollector $earnings,
        private readonly ResellerSettlementDeductionEligibilityCollector $debts,
        private readonly MarketBusinessDateResolver $businessDates,
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
        string $generationSource = ResellerSettlementBatch::GENERATION_SOURCE_SCHEDULED,
        ?string $reportType = null,
    ): array {
        $this->assertReportFilter($reportType);
        $moment = $at === null ? null : CarbonImmutable::instance($at);
        $types = $reportType === null
            ? [ResellerSettlementBatch::REPORT_TYPE_PLUS, ResellerSettlementBatch::REPORT_TYPE_MINUS]
            : [$reportType];

        $results = [];
        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($types as $type) {
            try {
                $this->processReportType(
                    $type,
                    $actor,
                    $moment,
                    $marketIds,
                    $generationSource,
                    $results,
                    $successCount,
                    $failedCount,
                    $skippedCount,
                );
            } catch (Throwable $exception) {
                report($exception);
                $this->tally($this->appendResult($results, $this->logFailure([
                    'status' => 'failed',
                    'report_type' => $type,
                    'market_id' => null,
                    'market_code' => '',
                    'timezone' => (string) config('feeder.settlement.schedule_timezone', 'Asia/Colombo'),
                    'settlement_date' => '',
                    'error_code' => 'RESELLER_SETTLEMENT_REPORT_UNEXPECTED',
                    'error_message' => $exception->getMessage(),
                ])), $successCount, $failedCount, $skippedCount);
            }
        }

        Log::info('reseller_settlement.daily_batch_processor.completed', [
            'success_market_count' => $successCount,
            'failed_market_count' => $failedCount,
            'skipped_market_count' => $skippedCount,
            'market_count' => count($results),
            'generation_source' => $generationSource,
            'report_type' => $reportType ?? 'PLUS+MINUS',
        ]);

        return [
            'success_market_count' => $successCount,
            'failed_market_count' => $failedCount,
            'skipped_market_count' => $skippedCount,
            'markets' => $results,
        ];
    }

    /**
     * @param  list<int>|null  $marketIds
     * @param  list<array<string, mixed>>  $results
     */
    private function processReportType(
        string $reportType,
        User $actor,
        ?CarbonImmutable $at,
        ?array $marketIds,
        string $generationSource,
        array &$results,
        int &$successCount,
        int &$failedCount,
        int &$skippedCount,
    ): void {
        foreach ($this->eligibleMarkets($marketIds) as $market) {
            $referenceDate = $this->safeBusinessDate($market, $at);

            try {
                $outstanding = $reportType === ResellerSettlementBatch::REPORT_TYPE_MINUS
                    ? $this->debts->collectOutstanding($market, $referenceDate !== '' ? $referenceDate : null)
                    : $this->earnings->collectOutstanding($market);
                $candidates = $reportType === ResellerSettlementBatch::REPORT_TYPE_MINUS
                    ? $outstanding['candidates']
                    : array_merge($outstanding['payable_candidates'], $outstanding['review_candidates']);
                $byDate = $this->groupByDate(
                    $candidates,
                    $reportType === ResellerSettlementBatch::REPORT_TYPE_MINUS ? 'effective_date' : 'business_date',
                );
            } catch (Throwable $exception) {
                report($exception);
                $this->tally($this->appendResult($results, $this->unexpectedFailure(
                    $market,
                    $reportType,
                    $referenceDate,
                    $exception,
                )), $successCount, $failedCount, $skippedCount);

                continue;
            }

            if ($referenceDate !== '' && ! isset($byDate[$referenceDate])) {
                $this->tally($this->appendResult($results, $this->skippedPayload(
                    $market,
                    $reportType,
                    $this->businessDates->timezoneFor($market),
                    $referenceDate,
                    null,
                    self::SKIP_REASON_NOTHING_ELIGIBLE,
                )), $successCount, $failedCount, $skippedCount);
            }

            $dates = array_keys($byDate);
            sort($dates);

            foreach ($dates as $date) {
                $this->tally($this->appendResult($results, $this->processOutstandingDate(
                    $market,
                    $actor,
                    $date,
                    $generationSource,
                    $reportType,
                )), $successCount, $failedCount, $skippedCount);
            }
        }
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
                Log::warning('reseller_settlement.daily_batch_processor.market_timezone_missing', [
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
    private function processOutstandingDate(
        Market $market,
        User $actor,
        string $settlementDate,
        string $generationSource,
        string $reportType,
    ): array {
        $timezone = $this->businessDates->timezoneFor($market);
        $at = CarbonImmutable::parse($settlementDate.' 12:00:00', $timezone);

        try {
            $resolvedDate = $this->businessDates->businessDateString($market, $at);
            if ($resolvedDate !== $settlementDate) {
                return $this->logFailure($this->failedPayload(
                    $market,
                    $reportType,
                    $timezone,
                    $settlementDate,
                    'RESELLER_SETTLEMENT_BUSINESS_DATE_MISMATCH',
                    'Outstanding business date could not be resolved for batch generation.',
                ));
            }

            $daily = $this->nonVoidedDailyBatch($market, $settlementDate, $reportType);
            if ($daily === null || $daily->status === ResellerSettlementBatch::STATUS_OPEN) {
                $batch = $this->batches->createOrGetDailyBatch(
                    $market,
                    $actor,
                    $at,
                    $generationSource,
                    $reportType,
                );

                if ($batch->status === ResellerSettlementBatch::STATUS_OPEN) {
                    $payload = $this->successPayload(
                        $market,
                        $reportType,
                        $timezone,
                        $settlementDate,
                        $this->generateBatch($batch, $reportType),
                    );

                    Log::info('reseller_settlement.daily_batch_processor.market_success', $payload);

                    return $payload;
                }
            }

            $openCatchUp = ResellerSettlementBatch::query()
                ->where('market_id', $market->id)
                ->whereDate('settlement_date', $settlementDate)
                ->where('kind', ResellerSettlementBatch::KIND_CATCH_UP)
                ->where('report_type', $reportType)
                ->where('status', ResellerSettlementBatch::STATUS_OPEN)
                ->whereNull('voided_at')
                ->orderBy('sequence')
                ->first();

            $batch = $openCatchUp ?? $this->batches->createCatchUpBatch(
                $market,
                $actor,
                CarbonImmutable::parse($settlementDate.' 00:00:00', $timezone),
                $generationSource,
                $reportType,
            );

            if ($batch->status !== ResellerSettlementBatch::STATUS_OPEN || $batch->voided_at !== null) {
                $payload = $this->skippedPayload(
                    $market,
                    $reportType,
                    $timezone,
                    $settlementDate,
                    $batch,
                    'Outstanding records remain, but no OPEN catch-up batch could be generated.',
                );

                Log::info('reseller_settlement.daily_batch_processor.market_skipped', $payload);

                return $payload;
            }

            $payload = $this->successPayload(
                $market,
                $reportType,
                $timezone,
                $settlementDate,
                $this->generateBatch($batch, $reportType),
            );

            Log::info('reseller_settlement.daily_batch_processor.outstanding_catch_up_success', $payload);

            return $payload;
        } catch (ResellerSettlementException $exception) {
            return $this->logFailure($this->failedPayload(
                $market,
                $reportType,
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
                $reportType,
                $timezone,
                $settlementDate,
                'RESELLER_SETTLEMENT_DAILY_UNEXPECTED',
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function generateBatch(ResellerSettlementBatch $batch, string $reportType): array
    {
        if ($reportType === ResellerSettlementBatch::REPORT_TYPE_MINUS) {
            return $this->deductions->generate($batch);
        }

        $generated = $this->generation->generate($batch);
        $generated['deduction_line_count'] = 0;

        return $generated;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByDate(array $candidates, string $dateKey): array
    {
        $grouped = [];

        foreach ($candidates as $candidate) {
            $date = (string) ($candidate[$dateKey] ?? '');
            if ($date === '') {
                continue;
            }

            $grouped[$date][] = $candidate;
        }

        ksort($grouped);

        return $grouped;
    }

    private function nonVoidedDailyBatch(Market $market, string $settlementDate, string $reportType): ?ResellerSettlementBatch
    {
        return ResellerSettlementBatch::query()
            ->where('market_id', $market->id)
            ->whereDate('settlement_date', $settlementDate)
            ->where('kind', ResellerSettlementBatch::KIND_DAILY)
            ->where('report_type', $reportType)
            ->whereNull('voided_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $generation
     * @return array<string, mixed>
     */
    private function successPayload(
        Market $market,
        string $reportType,
        string $timezone,
        string $settlementDate,
        array $generation,
    ): array {
        /** @var ResellerSettlementBatch $batch */
        $batch = $generation['batch'];

        return [
            'status' => 'success',
            'report_type' => $reportType,
            'market_id' => (int) $market->id,
            'market_code' => (string) $market->code,
            'timezone' => $timezone,
            'settlement_date' => $settlementDate,
            'batch_id' => (int) $batch->id,
            'batch_number' => (string) $batch->batch_number,
            'batch_status' => (string) $batch->status,
            'batch_kind' => (string) $batch->kind,
            'invoice_count' => (int) ($generation['invoice_count'] ?? 0),
            'earning_line_count' => (int) ($generation['earning_line_count'] ?? 0),
            'review_item_count' => (int) ($generation['review_item_count'] ?? 0),
            'deduction_line_count' => (int) ($generation['deduction_line_count'] ?? 0),
            'net_payable_total' => (string) ($generation['net_payable_total'] ?? '0.00'),
            'payable_totals_by_currency' => $generation['payable_totals_by_currency'] ?? [],
            'skipped_already_allocated_count' => (int) ($generation['skipped_already_allocated_count'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skippedPayload(
        Market $market,
        string $reportType,
        string $timezone,
        string $settlementDate,
        ?ResellerSettlementBatch $batch,
        string $reason,
    ): array {
        $payload = [
            'status' => 'skipped',
            'report_type' => $reportType,
            'market_id' => (int) $market->id,
            'market_code' => (string) $market->code,
            'timezone' => $timezone,
            'settlement_date' => $settlementDate,
            'batch_id' => $batch === null ? null : (int) $batch->id,
            'batch_number' => $batch === null ? null : (string) $batch->batch_number,
            'batch_status' => $batch === null ? null : (string) $batch->status,
            'reason' => $reason,
            'invoice_count' => 0,
            'earning_line_count' => 0,
            'review_item_count' => 0,
            'deduction_line_count' => 0,
            'payable_totals_by_currency' => [],
        ];

        Log::info('reseller_settlement.daily_batch_processor.market_skipped', $payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function failedPayload(
        Market $market,
        string $reportType,
        string $timezone,
        string $settlementDate,
        string $errorCode,
        string $errorMessage,
        array $details = [],
    ): array {
        return [
            'status' => 'failed',
            'report_type' => $reportType,
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
    private function unexpectedFailure(
        Market $market,
        string $reportType,
        string $settlementDate,
        Throwable $exception,
    ): array {
        $timezone = 'UTC';

        try {
            $timezone = $this->businessDates->timezoneFor($market);
        } catch (Throwable) {
            $timezone = 'UTC';
        }

        return $this->logFailure($this->failedPayload(
            $market,
            $reportType,
            $timezone,
            $settlementDate,
            'RESELLER_SETTLEMENT_DAILY_UNEXPECTED',
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
    private function appendResult(array &$results, array $result): array
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
        Log::error('reseller_settlement.daily_batch_processor.market_failed', $payload);

        return $payload;
    }

    private function assertReportFilter(?string $reportType): void
    {
        if ($reportType === null) {
            return;
        }

        $allowed = [
            ResellerSettlementBatch::REPORT_TYPE_PLUS,
            ResellerSettlementBatch::REPORT_TYPE_MINUS,
        ];

        if (! in_array($reportType, $allowed, true)) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVALID_REPORT_TYPE',
                'Reseller settlement generation must be PLUS, MINUS, or both.',
                ['report_type' => $reportType],
            );
        }
    }

    private function marketsHaveIsActiveColumn(): bool
    {
        try {
            return Schema::hasColumn('markets', 'is_active');
        } catch (Throwable) {
            return false;
        }
    }
}
