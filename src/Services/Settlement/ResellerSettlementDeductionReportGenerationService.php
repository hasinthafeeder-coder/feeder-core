<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Exceptions\ResellerSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerDebtObligation;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementDeductionLine;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Persists Minus Report membership for eligible outstanding reseller debts.
 *
 * Does not create debt obligations, reduce outstanding balances, allocate debt
 * against earnings, or record a payment.
 *
 * Writer lock order: market → batch → debt obligation.
 */
class ResellerSettlementDeductionReportGenerationService
{
    public function __construct(
        private readonly ResellerSettlementDeductionEligibilityCollector $eligibility,
        private readonly MoneyNormalizer $money,
    ) {}

    /**
     * @return array{
     *     batch: ResellerSettlementBatch,
     *     invoice_count: int,
     *     earning_line_count: int,
     *     review_item_count: int,
     *     deduction_line_count: int,
     *     payable_totals_by_currency: array<string, string>,
     *     skipped_already_reported_count: int
     * }
     */
    public function generate(ResellerSettlementBatch $batch): array
    {
        return DB::transaction(function () use ($batch) {
            $marketId = (int) ($batch->market_id
                ?? ResellerSettlementBatch::query()->whereKey($batch->id)->value('market_id'));

            $market = Market::query()->whereKey($marketId)->lockForUpdate()->firstOrFail();

            /** @var ResellerSettlementBatch $locked */
            $locked = ResellerSettlementBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) ($locked->report_type ?: '') !== ResellerSettlementBatch::REPORT_TYPE_MINUS) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_BATCH_REPORT_TYPE',
                    'Only Minus Report batches can record deduction lines.',
                    ['batch_id' => $locked->id, 'report_type' => $locked->report_type],
                );
            }

            if ($locked->voided_at !== null) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_BATCH_VOIDED',
                    'A voided reseller settlement batch cannot be generated.',
                    ['batch_id' => $locked->id],
                );
            }

            if ($locked->status !== ResellerSettlementBatch::STATUS_OPEN) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_BATCH_NOT_OPEN',
                    'Only OPEN reseller settlement batches can be generated or rebuilt.',
                    ['batch_id' => $locked->id, 'status' => $locked->status],
                );
            }

            ResellerSettlementDeductionLine::query()
                ->where('batch_id', $locked->id)
                ->delete();

            $collected = $this->eligibility->collectForBatch($locked, $market);
            $totals = [];
            $count = 0;

            foreach ($collected['candidates'] as $candidate) {
                $persisted = $this->persistLine($locked, $candidate);
                if ($persisted === null) {
                    continue;
                }

                $count++;
                $currency = (string) $persisted->currency_code_snapshot;
                $totals[$currency] = $this->money->add(
                    $totals[$currency] ?? '0.00',
                    number_format((float) $persisted->outstanding_amount_snapshot, 2, '.', ''),
                );
            }

            $locked->refresh();

            return [
                'batch' => $locked,
                'invoice_count' => 0,
                'earning_line_count' => 0,
                'review_item_count' => 0,
                'deduction_line_count' => $count,
                'payable_totals_by_currency' => $totals,
                'skipped_already_reported_count' => $collected['skipped_already_reported_count'],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function persistLine(ResellerSettlementBatch $batch, array $candidate): ?ResellerSettlementDeductionLine
    {
        $debtId = (int) $candidate['debt_obligation_id'];

        $lockedDebt = ResellerDebtObligation::query()
            ->whereKey($debtId)
            ->lockForUpdate()
            ->first();

        if ($lockedDebt === null || (float) $lockedDebt->outstanding_amount <= 0) {
            return null;
        }

        $existing = ResellerSettlementDeductionLine::query()
            ->where('debt_obligation_id', $debtId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_DEDUCTION_ALREADY_REPORTED',
                'Debt obligation is already included in a Minus Report batch.',
                [
                    'debt_obligation_id' => $debtId,
                    'batch_id' => $batch->id,
                    'deduction_line_id' => $existing->id,
                ],
                409,
            );
        }

        try {
            return ResellerSettlementDeductionLine::query()->create([
                'batch_id' => $batch->id,
                'debt_obligation_id' => $debtId,
                'debt_obligation_number_snapshot' => (string) $lockedDebt->obligation_number,
                'obligation_type_snapshot' => (string) (
                    $lockedDebt->obligation_type?->value ?? $lockedDebt->getRawOriginal('obligation_type')
                ),
                'return_reason_snapshot' => $lockedDebt->return_reason !== null
                    ? (string) ($lockedDebt->return_reason->value ?? $lockedDebt->getRawOriginal('return_reason'))
                    : null,
                'fine_condition_snapshot' => $lockedDebt->fine_condition !== null
                    ? (string) ($lockedDebt->fine_condition->value ?? $lockedDebt->getRawOriginal('fine_condition'))
                    : null,
                'reseller_id' => (int) $lockedDebt->reseller_id,
                'reseller_name_snapshot' => (string) $candidate['reseller_name'],
                'order_id' => $lockedDebt->order_id,
                'order_number_snapshot' => (string) ($candidate['order_number'] ?? ''),
                'currency_id' => (int) $lockedDebt->currency_id,
                'currency_code_snapshot' => strtoupper((string) $lockedDebt->currency_code_snapshot),
                'original_amount_snapshot' => number_format((float) $lockedDebt->original_amount, 2, '.', ''),
                'outstanding_amount_snapshot' => number_format((float) $lockedDebt->outstanding_amount, 2, '.', ''),
                'effective_date_snapshot' => $lockedDebt->effective_date?->format('Y-m-d')
                    ?? (string) $lockedDebt->getRawOriginal('effective_date'),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_DEDUCTION_ALREADY_REPORTED',
                    'Debt obligation is already included in a Minus Report batch.',
                    [
                        'debt_obligation_id' => $debtId,
                        'batch_id' => $batch->id,
                    ],
                    409,
                );
            }

            throw $exception;
        }
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
}
