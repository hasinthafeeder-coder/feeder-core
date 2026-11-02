<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Exceptions\ResellerSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Models\ResellerSettlementInvoiceLine;
use Feeder\Core\Models\ResellerSettlementReviewItem;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates or rebuilds OPEN reseller settlement batch financial snapshots.
 *
 * Groups eligible earnings by reseller + currency within the market batch.
 * Allocates oldest outstanding debts atomically. Flags negative gross for review.
 * Does not recalculate historical earnings from mutable product data.
 *
 * Writer lock order: market → batch → earning obligation → debt → previous invoices.
 */
class ResellerSettlementGenerationService
{
    public function __construct(
        private readonly ResellerSettlementEligibilityCollector $eligibility,
        private readonly ResellerSettlementDebtAllocator $debtAllocator,
        private readonly ResellerSettlementInvoiceOffsetAllocator $invoiceOffsets,
        private readonly ResellerSettlementNumberGenerator $numbers,
        private readonly MoneyNormalizer $money,
        private readonly ResellerSettlementTransferFeeSettingsService $transferFeeSettings,
        private readonly FinancialDailySummaryUpdater $financialDailySummaries,
    ) {}

    /**
     * @return array{
     *     batch: ResellerSettlementBatch,
     *     invoice_count: int,
     *     earning_line_count: int,
     *     review_item_count: int,
     *     debt_allocation_count: int,
     *     previous_invoice_offset_total: string,
     *     previous_invoice_offset_count: int,
     *     gross_earnings_total: string,
     *     debt_deduction_total: string,
     *     net_payable_total: string,
     *     payable_totals_by_currency: array<string, string>,
     *     skipped_already_allocated_count: int,
     *     skipped_currency_mismatch_count: int
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

            if ((int) $locked->market_id !== (int) $market->id) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_BATCH_MARKET_MISMATCH',
                    'Reseller settlement batch market changed during generation locking.',
                    ['batch_id' => $locked->id],
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
                    [
                        'batch_id' => $locked->id,
                        'status' => $locked->status,
                    ],
                );
            }

            if ((string) ($locked->report_type ?: ResellerSettlementBatch::REPORT_TYPE_COMBINED)
                === ResellerSettlementBatch::REPORT_TYPE_MINUS
            ) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_BATCH_REPORT_TYPE',
                    'Minus Report batches do not create earning invoices.',
                    ['batch_id' => $locked->id],
                );
            }

            $this->clearOpenBatchFinancials($locked);

            $collected = $this->eligibility->collect($locked, $market, false);
            $invoiceCount = $this->persistInvoices($locked, $market, $collected['payable_candidates']);
            $reviewCount = $this->persistReviewItems($locked, $collected['review_candidates']);

            $locked->refresh();
            $locked->load(['invoices.earningLines', 'invoices.debtAllocations', 'invoices.offsetsIssued', 'reviewItems']);

            $grossTotal = '0.00';
            $debtTotal = '0.00';
            $netTotal = '0.00';
            $previousOffsetTotal = '0.00';
            $payableByCurrency = [];
            $debtAllocationCount = 0;
            $previousOffsetCount = 0;

            foreach ($locked->invoices as $invoice) {
                $grossTotal = $this->money->add($grossTotal, (string) $invoice->gross_earnings_total);
                $debtTotal = $this->money->add($debtTotal, (string) $invoice->debt_deduction_total);
                $netTotal = $this->money->add($netTotal, (string) $invoice->net_payable);
                $previousOffsetTotal = $this->money->add(
                    $previousOffsetTotal,
                    (string) ($invoice->previous_invoice_offset_total ?? '0.00'),
                );
                $debtAllocationCount += $invoice->debtAllocations->count();
                $previousOffsetCount += $invoice->offsetsIssued->count();

                $code = strtoupper((string) $invoice->currency_code_snapshot);
                $payableByCurrency[$code] = $this->money->add(
                    $payableByCurrency[$code] ?? '0.00',
                    (string) $invoice->net_payable,
                );
            }

            if ($reviewCount > 0) {
                Log::warning('reseller_settlement.negative_gross_review_items', [
                    'batch_id' => $locked->id,
                    'batch_number' => $locked->batch_number,
                    'review_item_count' => $reviewCount,
                ]);
            }

            $this->financialDailySummaries->recordResellerBatchGenerated($locked);

            return [
                'batch' => $locked,
                'invoice_count' => $invoiceCount,
                'earning_line_count' => $locked->invoices->sum(
                    static fn ($invoice) => $invoice->earningLines->count()
                ),
                'review_item_count' => $reviewCount,
                'debt_allocation_count' => $debtAllocationCount,
                'previous_invoice_offset_total' => $previousOffsetTotal,
                'previous_invoice_offset_count' => $previousOffsetCount,
                'gross_earnings_total' => $grossTotal,
                'debt_deduction_total' => $debtTotal,
                'net_payable_total' => $netTotal,
                'payable_totals_by_currency' => $payableByCurrency,
                'skipped_already_allocated_count' => $collected['skipped_already_allocated_count'],
                'skipped_currency_mismatch_count' => $collected['skipped_currency_mismatch_count'] ?? 0,
            ];
        });
    }

    private function clearOpenBatchFinancials(ResellerSettlementBatch $batch): void
    {
        $batch->loadMissing(['invoices.payments']);

        foreach ($batch->invoices as $invoice) {
            // Fully offset invoices stay UNPAID with amount_paid = 0 and no
            // effective payment. That is not payment activity and must not block
            // an OPEN rebuild. Any real ledger row or positive amount_paid does.
            if ($invoice->payments->isNotEmpty()
                || (float) $invoice->amount_paid > 0
                || $invoice->effective_payment_id !== null
            ) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_BATCH_HAS_PAYMENTS',
                    'Cannot rebuild an OPEN batch that already has payment activity.',
                    ['batch_id' => $batch->id, 'invoice_id' => $invoice->id],
                );
            }
        }

        $this->invoiceOffsets->assertBatchNotTargetedByOtherBatches($batch);
        $this->invoiceOffsets->reverseOffsetsForBatch($batch);
        $this->debtAllocator->reverseAllocationsForBatch($batch);

        ResellerSettlementInvoiceLine::query()
            ->whereIn('invoice_id', $batch->invoices()->pluck('id'))
            ->delete();

        ResellerSettlementReviewItem::query()
            ->where('batch_id', $batch->id)
            ->delete();

        $batch->invoices()->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function persistInvoices(ResellerSettlementBatch $batch, Market $market, array $candidates): int
    {
        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];
        foreach ($candidates as $candidate) {
            $key = ((int) $candidate['reseller_id']).':'.((int) $candidate['currency_id']);
            $grouped[$key][] = $candidate;
        }

        $count = 0;
        $settlementDate = $batch->settlement_date?->format('Y-m-d')
            ?? (string) $batch->getRawOriginal('settlement_date');

        foreach ($grouped as $group) {
            $first = $group[0];
            $gross = '0.00';
            foreach ($group as $line) {
                $gross = $this->money->add($gross, (string) $line['gross_reseller_profit']);
            }

            $invoiceNumber = $this->numbers->ensureUniqueInvoiceNumber(
                $this->numbers->invoiceNumber(
                    $batch,
                    (int) $first['reseller_id'],
                    (string) $first['currency_code'],
                )
            );

            $transferFee = $this->transferFeeSettings->resolveForInvoice(
                $market,
                (string) $first['currency_code'],
            );

            // Zero-net after debt/fee still creates an invoice for audit.
            // Generation never marks PAID — fee deduction is not a payment.
            $invoice = ResellerSettlementInvoice::query()->create([
                'invoice_number' => $invoiceNumber,
                'batch_id' => $batch->id,
                'reseller_id' => (int) $first['reseller_id'],
                'reseller_company_id' => $first['reseller_company_id'],
                'reseller_name_snapshot' => (string) $first['reseller_name'],
                'currency_id' => (int) $first['currency_id'],
                'currency_code_snapshot' => (string) $first['currency_code'],
                'gross_earnings_total' => $gross,
                'debt_deduction_total' => '0.00',
                'adjustment_total' => '0.00',
                'transfer_fee_amount' => $transferFee['amount'],
                'transfer_fee_currency_code_snapshot' => $transferFee['currency_code'],
                'transfer_fee_status' => $transferFee['status'],
                'previous_invoice_offset_total' => '0.00',
                'net_payable' => $gross,
                'amount_paid' => '0.00',
                'offset_applied_total' => '0.00',
                'outstanding_balance' => $gross,
                'payment_status' => ResellerSettlementInvoice::PAYMENT_STATUS_UNPAID,
                'instruction_status' => ResellerSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
                'effective_payment_id' => null,
                'issued_at' => now(),
            ]);

            foreach ($group as $line) {
                $earningObligationId = (int) $line['earning_obligation_id'];

                // Lock earning row, then re-check exclusivity before insert.
                // The unique index remains the backstop if another writer commits
                // a line between this read and the insert.
                $lockedEarning = DB::table('reseller_earning_obligations')
                    ->where('id', $earningObligationId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedEarning === null) {
                    throw new ResellerSettlementException(
                        'RESELLER_SETTLEMENT_EARNING_MISSING',
                        'Earning obligation disappeared before it could be settled.',
                        [
                            'earning_obligation_id' => $earningObligationId,
                            'batch_id' => $batch->id,
                        ],
                        409,
                    );
                }

                $existingLine = DB::table('reseller_settlement_invoice_lines')
                    ->where('earning_obligation_id', $earningObligationId)
                    ->lockForUpdate()
                    ->first();

                if ($existingLine !== null) {
                    throw new ResellerSettlementException(
                        'RESELLER_SETTLEMENT_EARNING_ALREADY_ALLOCATED',
                        'Earning obligation is already allocated to another settlement invoice.',
                        [
                            'earning_obligation_id' => $earningObligationId,
                            'batch_id' => $batch->id,
                            'invoice_line_id' => $existingLine->id,
                        ],
                        409,
                    );
                }

                try {
                    ResellerSettlementInvoiceLine::query()->create([
                        'invoice_id' => $invoice->id,
                        'earning_obligation_id' => (int) $line['earning_obligation_id'],
                        'settlement_date' => $settlementDate,
                        'order_id' => (int) $line['order_id'],
                        'order_number_snapshot' => (string) $line['order_number'],
                        'obligation_number_snapshot' => (string) $line['obligation_number'],
                        'business_date_snapshot' => (string) $line['business_date'],
                        'cod_amount_snapshot' => (string) $line['cod_amount_snapshot'],
                        'service_charge_snapshot' => (string) $line['service_charge_snapshot'],
                        'courier_charge_snapshot' => (string) $line['courier_charge_snapshot'],
                        'company_commission_snapshot' => (string) $line['company_commission_snapshot'],
                        'gross_reseller_profit' => (string) $line['gross_reseller_profit'],
                        'currency_id' => (int) $line['currency_id'],
                        'currency_code_snapshot' => (string) $line['currency_code'],
                        'line_payment_status' => ResellerSettlementInvoiceLine::LINE_PAYMENT_STATUS_UNPAID,
                    ]);
                } catch (QueryException $exception) {
                    if ($this->isUniqueViolation($exception)) {
                        throw new ResellerSettlementException(
                            'RESELLER_SETTLEMENT_EARNING_ALREADY_ALLOCATED',
                            'Earning obligation is already allocated to another settlement invoice.',
                            [
                                'earning_obligation_id' => (int) $line['earning_obligation_id'],
                                'batch_id' => $batch->id,
                            ],
                            409,
                        );
                    }

                    throw $exception;
                }
            }

            if ((string) ($batch->report_type ?: ResellerSettlementBatch::REPORT_TYPE_COMBINED)
                === ResellerSettlementBatch::REPORT_TYPE_PLUS
            ) {
                // Plus Report invoices keep earnings separate from Minus Report
                // deductions. Outstanding debt is not reduced and is not netted
                // into net_payable. Combined batches keep the historical allocation.
                $allocation = [
                    'debt_deduction_total' => '0.00',
                    'allocation_count' => 0,
                ];
            } else {
                $allocation = $this->debtAllocator->allocateForInvoice($batch, $invoice, $gross);
            }
            $debtDeduction = $allocation['debt_deduction_total'];
            $afterDebt = $this->money->subtract($gross, $debtDeduction);
            if ((float) $afterDebt < 0) {
                $afterDebt = '0.00';
            }

            // One fee per reseller invoice (batch+reseller+currency), never per order line.
            // Rebuild clears and recreates invoices, so the fee is not double-deducted.
            $feeAmount = (string) $transferFee['amount'];
            $afterFee = $this->money->subtract($afterDebt, $feeAmount);
            if ((float) $afterFee < 0) {
                $afterFee = '0.00';
            }

            // Previous-invoice offsets use only what remains after debt and the fee.
            // They are stored separately from debt_deduction_total so the amount is
            // not deducted twice. Generation never creates a ResellerPayment.
            $previousOffset = $this->invoiceOffsets->allocateForInvoice($batch, $invoice, $afterFee);
            $previousOffsetTotal = $previousOffset['offset_total'];
            $netPayable = $this->money->subtract($afterFee, $previousOffsetTotal);
            if ((float) $netPayable < 0) {
                $netPayable = '0.00';
            }

            $outstanding = $this->money->isPositive($netPayable) ? $netPayable : '0.00';

            $invoice->forceFill([
                'debt_deduction_total' => $debtDeduction,
                'adjustment_total' => '0.00',
                'transfer_fee_amount' => $feeAmount,
                'transfer_fee_currency_code_snapshot' => $transferFee['currency_code'],
                'transfer_fee_status' => $transferFee['status'],
                'previous_invoice_offset_total' => $previousOffsetTotal,
                'net_payable' => $netPayable,
                'amount_paid' => '0.00',
                'offset_applied_total' => '0.00',
                'outstanding_balance' => $outstanding,
                'payment_status' => ResellerSettlementInvoice::PAYMENT_STATUS_UNPAID,
            ])->save();

            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function persistReviewItems(ResellerSettlementBatch $batch, array $candidates): int
    {
        $count = 0;

        foreach ($candidates as $candidate) {
            ResellerSettlementReviewItem::query()->create([
                'batch_id' => $batch->id,
                'earning_obligation_id' => (int) $candidate['earning_obligation_id'],
                'reseller_id' => (int) $candidate['reseller_id'],
                'order_id' => (int) $candidate['order_id'],
                'order_number_snapshot' => (string) $candidate['order_number'],
                'obligation_number_snapshot' => (string) $candidate['obligation_number'],
                'currency_id' => (int) $candidate['currency_id'],
                'currency_code_snapshot' => (string) $candidate['currency_code'],
                'gross_reseller_profit' => (string) $candidate['gross_reseller_profit'],
                'review_reason' => ResellerSettlementReviewItem::REASON_NEGATIVE_GROSS_PROFIT,
                'status' => ResellerSettlementReviewItem::STATUS_OPEN,
            ]);
            $count++;
        }

        return $count;
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
