<?php

namespace Feeder\Core\Services\Finance;

use Feeder\Core\Enums\ResellerDebtObligationType;
use Feeder\Core\Enums\ResellerNonDeliveryFineCondition;
use Feeder\Core\Models\CourierRemittanceApplication;
use Feeder\Core\Models\FinancialDailySummary;
use Feeder\Core\Models\FinancialPaymentCycle;
use Feeder\Core\Models\FinancialSummaryContribution;
use Feeder\Core\Models\IntroducerBonusMonthlyBatch;
use Feeder\Core\Models\IntroducerBonusPayment;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ResellerDebtObligation;
use Feeder\Core\Models\ResellerEarningObligation;
use Feeder\Core\Models\ResellerPayment;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Models\SupplierPayment;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Services\Settlement\ResellerSettlementFinancialCalculator;
use Feeder\Core\Support\DecimalAmount;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Writes admin financial daily summaries at business events.
 *
 * Income uses the settlement eligible-order amount (COD customer payable or a
 * single approved bank transfer). Courier charges use remittance file amounts.
 * Supplier/reseller settlement totals use generated invoice payables. Company
 * profit = total income − courier charges − supplier settlement − reseller
 * settlement, only when every component is present for the day; otherwise the
 * profit status stays incomplete so the UI does not invent a figure.
 *
 * Generating batches updates settlement totals and does not mark invoices paid.
 * Confirmed payments are separate contributions dated by confirmed_at / paid_on.
 * Closing a cycle requires both sides fully paid.
 */
class FinancialDailySummaryUpdater
{
    public function __construct(
        private readonly ResellerSettlementFinancialCalculator $financialCalculator,
        private readonly MoneyNormalizer $money = new MoneyNormalizer(),
        private readonly MarketBusinessDateResolver $businessDates = new MarketBusinessDateResolver(),
    ) {}

    public function tablesReady(): bool
    {
        return Schema::hasTable('financial_payment_cycles')
            && Schema::hasTable('financial_daily_summaries')
            && Schema::hasTable('financial_summary_contributions');
    }

    public function recordCourierRemittanceApplication(CourierRemittanceApplication $application): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($application): void {
            /** @var CourierRemittanceApplication $locked */
            $locked = CourierRemittanceApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            $order = Order::query()
                ->with(['paymentSubmissions', 'market.currency', 'currency'])
                ->lockForUpdate()
                ->find((int) $locked->order_id);

            if ($order === null) {
                return;
            }

            $currency = $this->currencyCode(
                $locked->currency_code
                ?? $order->currency?->iso_code
                ?? $order->market?->currency?->iso_code
            );
            $marketId = (int) $order->market_id;
            // Prefer the persisted CRA business_date. When older applies left it null
            // (native invoices without a date column), date the contribution on the
            // market-local applied_at day so income/courier are not silently omitted.
            $businessDate = $this->dateString($locked->business_date)
                ?? $this->fallbackRemittanceBusinessDate($order, $locked);

            if ($currency === null || $businessDate === null || $marketId <= 0) {
                return;
            }

            $summary = $this->summaryFor($marketId, $currency, $businessDate);
            $income = $this->resolveIncomeAmount($order, $locked);

            $this->upsertContribution(
                $summary,
                FinancialSummaryContribution::METRIC_INCOME,
                FinancialSummaryContribution::SOURCE_COURIER_REMITTANCE,
                (int) $locked->id,
                (int) $order->id,
                $income['amount'],
                $income['present'],
                [
                    'basis' => $income['basis'],
                    'gross_remittance_amount' => $locked->gross_remittance_amount,
                    'net_remittance_amount' => $locked->net_remittance_amount,
                    'courier_receipt_import_id' => (int) $locked->courier_receipt_import_id,
                ],
            );

            $charge = $this->nullableMoney($locked->courier_charge_amount);
            $this->upsertContribution(
                $summary,
                FinancialSummaryContribution::METRIC_COURIER,
                FinancialSummaryContribution::SOURCE_COURIER_REMITTANCE,
                (int) $locked->id,
                (int) $order->id,
                $charge,
                $charge !== null,
                [
                    'gross_remittance_amount' => $locked->gross_remittance_amount,
                    'net_remittance_amount' => $locked->net_remittance_amount,
                ],
            );

            $this->rebuildSummary($summary);
        });
    }

    public function recordEarningObligation(ResellerEarningObligation $obligation): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($obligation): void {
            /** @var ResellerEarningObligation $locked */
            $locked = ResellerEarningObligation::query()
                ->whereKey($obligation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currency = $this->currencyCode($locked->currency_code_snapshot);
            $businessDate = $this->dateString($locked->business_date);
            $marketId = (int) $locked->market_id;

            if ($currency === null || $businessDate === null || $marketId <= 0) {
                return;
            }

            $summary = $this->summaryFor($marketId, $currency, $businessDate);
            $service = $this->nullableMoney($locked->service_charge_snapshot);

            $this->upsertContribution(
                $summary,
                FinancialSummaryContribution::METRIC_SERVICE_CHARGE,
                FinancialSummaryContribution::SOURCE_EARNING,
                (int) $locked->id,
                (int) $locked->order_id,
                $service,
                $service !== null,
                [
                    'cod_amount_snapshot' => $locked->cod_amount_snapshot,
                ],
            );

            $this->rebuildSummary($summary);
        });
    }

    public function recordDebtObligation(ResellerDebtObligation $obligation): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($obligation): void {
            /** @var ResellerDebtObligation $locked */
            $locked = ResellerDebtObligation::query()
                ->whereKey($obligation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $type = $locked->obligation_type instanceof ResellerDebtObligationType
                ? $locked->obligation_type
                : ResellerDebtObligationType::tryFrom((string) $locked->obligation_type);

            if ($type !== ResellerDebtObligationType::RETURN_FINE) {
                return;
            }

            $currency = $this->currencyCode($locked->currency_code_snapshot);
            $businessDate = $this->dateString($locked->effective_date);
            $marketId = (int) ($locked->market_id ?? 0);

            if ($marketId <= 0 && $locked->order_id) {
                $marketId = (int) Order::query()->whereKey($locked->order_id)->value('market_id');
            }

            if ($currency === null || $businessDate === null || $marketId <= 0) {
                return;
            }

            $condition = $locked->fine_condition instanceof ResellerNonDeliveryFineCondition
                ? $locked->fine_condition->value
                : (string) ($locked->fine_condition ?? '');

            $metric = $condition === ResellerNonDeliveryFineCondition::BASE->value
                ? FinancialSummaryContribution::METRIC_RETURN_CHARGE
                : FinancialSummaryContribution::METRIC_FINE;

            // Latest cards use original_amount. Outstanding is for next-payment only
            // and must not be summed with original for the same obligation.
            $amount = $this->nullableMoney($locked->original_amount);
            $summary = $this->summaryFor($marketId, $currency, $businessDate);

            $this->upsertContribution(
                $summary,
                $metric,
                FinancialSummaryContribution::SOURCE_DEBT,
                (int) $locked->id,
                $locked->order_id !== null ? (int) $locked->order_id : null,
                $amount,
                $amount !== null,
                [
                    'fine_condition' => $condition !== '' ? $condition : null,
                    'outstanding_amount' => $locked->outstanding_amount,
                ],
            );

            $this->rebuildSummary($summary);
        });
    }

    public function recordSupplierBatchGenerated(SupplierSettlementBatch $batch): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($batch): void {
            /** @var SupplierSettlementBatch $locked */
            $locked = SupplierSettlementBatch::query()
                ->with(['invoices'])
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->voided_at !== null) {
                $this->voidBatchMetricContributions(
                    FinancialSummaryContribution::SOURCE_SUPPLIER_INVOICE,
                    FinancialSummaryContribution::METRIC_SUPPLIER,
                    (int) $locked->id,
                );

                return;
            }

            $settlementDate = $this->dateString($locked->settlement_date);
            $marketId = (int) $locked->market_id;

            if ($settlementDate === null || $marketId <= 0) {
                return;
            }

            // Rebuilds replace invoice rows; clear prior batch contributions first.
            $this->voidBatchMetricContributions(
                FinancialSummaryContribution::SOURCE_SUPPLIER_INVOICE,
                FinancialSummaryContribution::METRIC_SUPPLIER,
                (int) $locked->id,
            );

            $byCurrency = [];

            foreach ($locked->invoices as $invoice) {
                if ($invoice->instruction_status === SupplierSettlementInvoice::INSTRUCTION_STATUS_VOIDED) {
                    continue;
                }

                $currency = $this->currencyCode($invoice->currency_code_snapshot);
                if ($currency === null) {
                    continue;
                }

                $summary = $this->summaryFor($marketId, $currency, $settlementDate);
                $amount = $this->nullableMoney($invoice->payable_total);

                $this->upsertContribution(
                    $summary,
                    FinancialSummaryContribution::METRIC_SUPPLIER,
                    FinancialSummaryContribution::SOURCE_SUPPLIER_INVOICE,
                    (int) $invoice->id,
                    null,
                    $amount,
                    $amount !== null,
                    [
                        'batch_id' => (int) $locked->id,
                        'payment_status' => $invoice->payment_status,
                    ],
                );

                $byCurrency[$currency] = $summary;
                $this->markCycleBatch($summary->cycle, 'supplier', (int) $locked->id, $settlementDate);
            }

            foreach ($byCurrency as $summary) {
                $summary->forceFill([
                    'supplier_batch_generated' => true,
                    'supplier_fully_paid' => false,
                ])->save();
                $this->rebuildSummary($summary->fresh());
                $this->refreshOutstandingSnapshot($summary->fresh()->cycle, 'supplier');
            }
        });
    }

    public function recordResellerBatchGenerated(ResellerSettlementBatch $batch): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($batch): void {
            /** @var ResellerSettlementBatch $locked */
            $locked = ResellerSettlementBatch::query()
                ->with(['invoices'])
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->voided_at !== null) {
                $this->voidBatchMetricContributions(
                    FinancialSummaryContribution::SOURCE_RESELLER_INVOICE,
                    FinancialSummaryContribution::METRIC_RESELLER,
                    (int) $locked->id,
                );

                return;
            }

            $settlementDate = $this->dateString($locked->settlement_date);
            $marketId = (int) $locked->market_id;

            if ($settlementDate === null || $marketId <= 0) {
                return;
            }

            $this->voidBatchMetricContributions(
                FinancialSummaryContribution::SOURCE_RESELLER_INVOICE,
                FinancialSummaryContribution::METRIC_RESELLER,
                (int) $locked->id,
            );

            $byCurrency = [];

            foreach ($locked->invoices as $invoice) {
                if ($invoice->instruction_status === ResellerSettlementInvoice::INSTRUCTION_STATUS_VOIDED) {
                    continue;
                }

                $currency = $this->currencyCode($invoice->currency_code_snapshot);
                if ($currency === null) {
                    continue;
                }

                $summary = $this->summaryFor($marketId, $currency, $settlementDate);
                $amount = $this->nullableMoney($invoice->net_payable);

                $this->upsertContribution(
                    $summary,
                    FinancialSummaryContribution::METRIC_RESELLER,
                    FinancialSummaryContribution::SOURCE_RESELLER_INVOICE,
                    (int) $invoice->id,
                    null,
                    $amount,
                    $amount !== null,
                    [
                        'batch_id' => (int) $locked->id,
                        'outstanding_balance' => $invoice->outstanding_balance,
                        'debt_deduction_total' => $invoice->debt_deduction_total,
                    ],
                );

                $byCurrency[$currency] = $summary;
                $this->markCycleBatch($summary->cycle, 'reseller', (int) $locked->id, $settlementDate);
            }

            foreach ($byCurrency as $summary) {
                $summary->forceFill([
                    'reseller_batch_generated' => true,
                    'reseller_fully_paid' => false,
                ])->save();
                $this->rebuildSummary($summary->fresh());
                $this->refreshOutstandingSnapshot($summary->fresh()->cycle, 'reseller');
            }
        });
    }

    /**
     * Record a supplier payment or reversal ledger row into daily summaries.
     *
     * PAYMENT amounts are positive; REVERSAL amounts are stored negative so the
     * day total is net of reversals. Dated by DATE(confirmed_at). Generation
     * liability totals are never changed here.
     */
    public function recordSupplierPayment(SupplierPayment $payment): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($payment): void {
            /** @var SupplierPayment $locked */
            $locked = SupplierPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $invoice = SupplierSettlementInvoice::query()
                ->whereKey((int) $locked->invoice_id)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                return;
            }

            $batch = SupplierSettlementBatch::query()
                ->whereKey((int) $invoice->batch_id)
                ->lockForUpdate()
                ->first();

            if ($batch === null || $batch->voided_at !== null) {
                return;
            }

            $currency = $this->currencyCode($locked->currency_code_snapshot ?? $invoice->currency_code_snapshot);
            $businessDate = $this->dateString($locked->confirmed_at);
            $marketId = (int) $batch->market_id;

            if ($currency === null || $businessDate === null || $marketId <= 0) {
                return;
            }

            $cycle = $this->cycleForBatch('supplier', (int) $batch->id, $marketId, $currency);
            $summary = $this->summaryForCycle($cycle, $marketId, $currency, $businessDate);

            $rawAmount = $this->signedMoney($locked->amount);
            $isReversal = $locked->entry_type === SupplierPayment::ENTRY_TYPE_REVERSAL;
            $amount = $rawAmount === null
                ? null
                : ($isReversal
                    ? bcmul($rawAmount, '-1', DecimalAmount::SCALE)
                    : $rawAmount);

            $this->upsertContribution(
                $summary,
                FinancialSummaryContribution::METRIC_SUPPLIER_PAYMENT,
                FinancialSummaryContribution::SOURCE_SUPPLIER_PAYMENT,
                (int) $locked->id,
                null,
                $amount,
                $amount !== null,
                [
                    'batch_id' => (int) $batch->id,
                    'invoice_id' => (int) $invoice->id,
                    'entry_type' => (string) $locked->entry_type,
                    'reverses_payment_id' => $locked->reverses_payment_id,
                ],
            );

            $this->rebuildSummary($summary->fresh());
            $this->refreshOutstandingSnapshot($cycle->fresh(), 'supplier');
        });
    }

    /**
     * Record a reseller payment or reversal ledger row into daily summaries.
     */
    public function recordResellerPayment(ResellerPayment $payment): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($payment): void {
            /** @var ResellerPayment $locked */
            $locked = ResellerPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $invoice = ResellerSettlementInvoice::query()
                ->whereKey((int) $locked->invoice_id)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                return;
            }

            $batch = ResellerSettlementBatch::query()
                ->whereKey((int) $invoice->batch_id)
                ->lockForUpdate()
                ->first();

            if ($batch === null || $batch->voided_at !== null) {
                return;
            }

            $currency = $this->currencyCode($locked->currency_code_snapshot ?? $invoice->currency_code_snapshot);
            $businessDate = $this->dateString($locked->confirmed_at);
            $marketId = (int) $batch->market_id;

            if ($currency === null || $businessDate === null || $marketId <= 0) {
                return;
            }

            $cycle = $this->cycleForBatch('reseller', (int) $batch->id, $marketId, $currency);
            $summary = $this->summaryForCycle($cycle, $marketId, $currency, $businessDate);

            $rawAmount = $this->signedMoney($locked->amount);
            $isReversal = $locked->entry_type === ResellerPayment::ENTRY_TYPE_REVERSAL;
            $amount = $rawAmount === null
                ? null
                : ($isReversal
                    ? bcmul($rawAmount, '-1', DecimalAmount::SCALE)
                    : $rawAmount);

            $this->upsertContribution(
                $summary,
                FinancialSummaryContribution::METRIC_RESELLER_PAYMENT,
                FinancialSummaryContribution::SOURCE_RESELLER_PAYMENT,
                (int) $locked->id,
                null,
                $amount,
                $amount !== null,
                [
                    'batch_id' => (int) $batch->id,
                    'invoice_id' => (int) $invoice->id,
                    'entry_type' => (string) $locked->entry_type,
                    'reverses_payment_id' => $locked->reverses_payment_id,
                ],
            );

            $this->rebuildSummary($summary->fresh());
            $this->refreshOutstandingSnapshot($cycle->fresh(), 'reseller');
        });
    }

    /**
     * Persist introducer bonus reserve on the last calendar day of the batch month.
     */
    public function recordIntroducerBonusBatchGenerated(IntroducerBonusMonthlyBatch $batch): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($batch): void {
            /** @var IntroducerBonusMonthlyBatch $locked */
            $locked = IntroducerBonusMonthlyBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currency = $this->currencyCode($locked->currency_code_snapshot);
            $marketId = (int) $locked->market_id;
            $year = (int) $locked->period_year;
            $month = (int) $locked->period_month;
            $businessDate = $this->monthEndDate($year, $month);

            if ($currency === null || $businessDate === null || $marketId <= 0) {
                return;
            }

            $summary = $this->summaryFor($marketId, $currency, $businessDate);
            $amount = $this->nullableMoney($locked->payable_total);

            $this->upsertContribution(
                $summary,
                FinancialSummaryContribution::METRIC_INTRODUCER_RESERVE,
                FinancialSummaryContribution::SOURCE_INTRODUCER_BATCH,
                (int) $locked->id,
                null,
                $amount,
                $amount !== null,
                [
                    'period_year' => $year,
                    'period_month' => $month,
                    'status' => (string) $locked->status,
                ],
            );

            $this->rebuildSummary($summary->fresh());
        });
    }

    /**
     * Persist introducer bonus payment cash on DATE(paid_on).
     */
    public function recordIntroducerBonusPayment(IntroducerBonusPayment $payment): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($payment): void {
            /** @var IntroducerBonusPayment $locked */
            $locked = IntroducerBonusPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $batch = IntroducerBonusMonthlyBatch::query()
                ->whereKey((int) $locked->batch_id)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                return;
            }

            $currency = $this->currencyCode($locked->currency_code_snapshot ?? $batch->currency_code_snapshot);
            $businessDate = $this->dateString($locked->paid_on);
            $marketId = (int) $batch->market_id;

            if ($currency === null || $businessDate === null || $marketId <= 0) {
                return;
            }

            $summary = $this->summaryFor($marketId, $currency, $businessDate);
            $amount = $this->nullableMoney($locked->amount);

            $this->upsertContribution(
                $summary,
                FinancialSummaryContribution::METRIC_INTRODUCER_PAID,
                FinancialSummaryContribution::SOURCE_INTRODUCER_PAYMENT,
                (int) $locked->id,
                null,
                $amount,
                $amount !== null,
                [
                    'batch_id' => (int) $batch->id,
                    'period_year' => (int) $batch->period_year,
                    'period_month' => (int) $batch->period_month,
                ],
            );

            $this->rebuildSummary($summary->fresh());
        });
    }

    public function refreshSupplierPaymentState(SupplierSettlementBatch $batch): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($batch): void {
            $locked = SupplierSettlementBatch::query()
                ->with('invoices')
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->voided_at !== null) {
                return;
            }

            $fullyPaid = $this->supplierBatchFullyPaid($locked);
            $cycles = FinancialPaymentCycle::query()
                ->where('supplier_batch_id', (int) $locked->id)
                ->lockForUpdate()
                ->get();

            foreach ($cycles as $cycle) {
                $cycle->forceFill([
                    'supplier_fully_paid_at' => $fullyPaid ? ($cycle->supplier_fully_paid_at ?? now()) : null,
                ])->save();

                FinancialDailySummary::query()
                    ->where('payment_cycle_id', $cycle->id)
                    ->update(['supplier_fully_paid' => $fullyPaid]);

                $this->refreshOutstandingSnapshot($cycle->fresh(), 'supplier');
                $this->maybeCloseCycle($cycle->fresh());
            }

            // CRA/backfill may have opened a cycle without linking these batches.
            $this->reconcileOpenCyclesAgainstFullyPaidSettlements();
        });
    }

    public function refreshResellerPaymentState(ResellerSettlementBatch $batch): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        DB::transaction(function () use ($batch): void {
            $locked = ResellerSettlementBatch::query()
                ->with('invoices')
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->voided_at !== null) {
                return;
            }

            $fullyPaid = $this->resellerBatchFullyPaid($locked);
            $cycles = FinancialPaymentCycle::query()
                ->where('reseller_batch_id', (int) $locked->id)
                ->lockForUpdate()
                ->get();

            foreach ($cycles as $cycle) {
                $cycle->forceFill([
                    'reseller_fully_paid_at' => $fullyPaid ? ($cycle->reseller_fully_paid_at ?? now()) : null,
                ])->save();

                FinancialDailySummary::query()
                    ->where('payment_cycle_id', $cycle->id)
                    ->update(['reseller_fully_paid' => $fullyPaid]);

                $this->refreshOutstandingSnapshot($cycle->fresh(), 'reseller');
                $this->maybeCloseCycle($cycle->fresh());
            }

            $this->reconcileOpenCyclesAgainstFullyPaidSettlements();
        });
    }

    /**
     * Close OPEN payment cycles whose market/currency already have fully paid
     * terminal supplier and reseller batches, even when the cycle was never linked
     * to those batches (CRA backfill after settlement payment).
     *
     * Does not mark invoices paid, recreate invoices, or invent settlement amounts.
     * LOCKED alone is not enough; every non-voided invoice must be settled, and a
     * newer meaningful unpaid batch blocks rollover. Fresh empty open cycles created
     * after a prior close are left alone. Returns how many cycles closed.
     */
    public function reconcileOpenCyclesAgainstFullyPaidSettlements(): int
    {
        if (! $this->tablesReady()) {
            return 0;
        }

        $closed = 0;

        DB::transaction(function () use (&$closed): void {
            $cycles = FinancialPaymentCycle::query()
                ->where('status', FinancialPaymentCycle::STATUS_OPEN)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            foreach ($cycles as $cycle) {
                if ($this->closeOpenCycleIfBothSidesFullyPaid($cycle)) {
                    $closed++;
                }
            }
        });

        return $closed;
    }

    /**
     * @return array{amount: ?string, present: bool, basis: string}
     */
    private function resolveIncomeAmount(Order $order, CourierRemittanceApplication $application): array
    {
        $eligible = $this->financialCalculator->resolveEligibleOrderAmount($order);

        // Bank-transfer orders: use the single approved submission amount and do
        // not also add remittance gross (courier COD is usually zero).
        if ($eligible['source'] === ResellerSettlementFinancialCalculator::ELIGIBLE_AMOUNT_SOURCE_BANK_TRANSFER
            && $eligible['calculable']
            && $eligible['amount'] !== null) {
            return [
                'amount' => $eligible['amount'],
                'present' => true,
                'basis' => ResellerSettlementFinancialCalculator::ELIGIBLE_AMOUNT_SOURCE_BANK_TRANSFER,
            ];
        }

        // COD / default: sale amount from the confirmed courier payment update.
        $gross = $this->nullableMoney($application->gross_remittance_amount);
        if ($gross !== null) {
            return [
                'amount' => $gross,
                'present' => true,
                'basis' => 'GROSS_REMITTANCE',
            ];
        }

        // Legacy rows that only stored net: keep income incomplete rather than
        // treating net remittance as sale amount.
        return [
            'amount' => null,
            'present' => false,
            'basis' => 'MISSING',
        ];
    }

    private function summaryFor(int $marketId, string $currency, string $businessDate): FinancialDailySummary
    {
        return $this->summaryForCycle($this->openCycle($marketId, $currency), $marketId, $currency, $businessDate);
    }

    private function summaryForCycle(
        FinancialPaymentCycle $cycle,
        int $marketId,
        string $currency,
        string $businessDate,
    ): FinancialDailySummary {
        $summary = FinancialDailySummary::query()
            ->where('payment_cycle_id', $cycle->id)
            ->whereDate('business_date', $businessDate)
            ->lockForUpdate()
            ->first();

        if ($summary !== null) {
            return $summary->loadMissing('cycle');
        }

        return FinancialDailySummary::query()->create([
            'payment_cycle_id' => $cycle->id,
            'market_id' => $marketId,
            'currency_code' => $currency,
            'business_date' => $businessDate,
            'company_profit_status' => FinancialDailySummary::PROFIT_STATUS_INCOMPLETE,
            'supplier_batch_generated' => $cycle->supplier_generated_at !== null,
            'reseller_batch_generated' => $cycle->reseller_generated_at !== null,
            'supplier_fully_paid' => $cycle->supplier_fully_paid_at !== null,
            'reseller_fully_paid' => $cycle->reseller_fully_paid_at !== null,
        ])->load('cycle');
    }

    /**
     * Prefer the cycle that already owns this settlement batch (open or closed).
     * Fall back to the current open cycle when the batch was never linked.
     */
    private function cycleForBatch(string $side, int $batchId, int $marketId, string $currency): FinancialPaymentCycle
    {
        $column = $side === 'supplier' ? 'supplier_batch_id' : 'reseller_batch_id';

        $linked = FinancialPaymentCycle::query()
            ->where($column, $batchId)
            ->where('market_id', $marketId)
            ->where('currency_code', $currency)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($linked !== null) {
            return $linked;
        }

        return $this->openCycle($marketId, $currency);
    }

    /**
     * Mutable outstanding snapshot on the settlement_date day of the linked cycle.
     * Not a historical end-of-day series for every calendar day.
     */
    private function refreshOutstandingSnapshot(FinancialPaymentCycle $cycle, string $side): void
    {
        $batchId = $side === 'supplier'
            ? ($cycle->supplier_batch_id !== null ? (int) $cycle->supplier_batch_id : null)
            : ($cycle->reseller_batch_id !== null ? (int) $cycle->reseller_batch_id : null);

        $settlementDate = $this->dateString($cycle->settlement_date);
        $currency = $this->currencyCode($cycle->currency_code);
        $marketId = (int) $cycle->market_id;

        if ($batchId === null || $settlementDate === null || $currency === null || $marketId <= 0) {
            return;
        }

        $column = $side === 'supplier' ? 'supplier_outstanding_balance' : 'reseller_outstanding_balance';

        $summary = $this->summaryForCycle($cycle, $marketId, $currency, $settlementDate);
        $outstanding = $side === 'supplier'
            ? $this->supplierBatchOutstanding($batchId, $currency)
            : $this->resellerBatchOutstanding($batchId, $currency);

        $summary->forceFill([$column => $outstanding])->save();
    }

    private function supplierBatchOutstanding(int $batchId, string $currency): string
    {
        $invoices = SupplierSettlementInvoice::query()
            ->where('batch_id', $batchId)
            ->where('instruction_status', '!=', SupplierSettlementInvoice::INSTRUCTION_STATUS_VOIDED)
            ->whereRaw('UPPER(TRIM(currency_code_snapshot)) = ?', [$currency])
            ->get(['payable_total', 'payment_status']);

        $total = '0.00';

        foreach ($invoices as $invoice) {
            if ($invoice->payment_status === SupplierSettlementInvoice::PAYMENT_STATUS_PAID) {
                continue;
            }

            $amount = $this->nullableMoney($invoice->payable_total);
            if ($amount === null) {
                continue;
            }

            $total = bcadd($total, $amount, DecimalAmount::SCALE);
        }

        return $total;
    }

    private function resellerBatchOutstanding(int $batchId, string $currency): string
    {
        $invoices = ResellerSettlementInvoice::query()
            ->where('batch_id', $batchId)
            ->where('instruction_status', '!=', ResellerSettlementInvoice::INSTRUCTION_STATUS_VOIDED)
            ->whereRaw('UPPER(TRIM(currency_code_snapshot)) = ?', [$currency])
            ->get(['outstanding_balance']);

        $total = '0.00';

        foreach ($invoices as $invoice) {
            $amount = $this->nullableMoney($invoice->outstanding_balance);
            if ($amount === null || bccomp($amount, '0', DecimalAmount::SCALE) !== 1) {
                continue;
            }

            $total = bcadd($total, $amount, DecimalAmount::SCALE);
        }

        return $total;
    }

    private function openCycle(int $marketId, string $currency): FinancialPaymentCycle
    {
        Market::query()->whereKey($marketId)->lockForUpdate()->firstOrFail();

        $existing = FinancialPaymentCycle::query()
            ->where('market_id', $marketId)
            ->where('currency_code', $currency)
            ->where('status', FinancialPaymentCycle::STATUS_OPEN)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return FinancialPaymentCycle::query()->create([
            'market_id' => $marketId,
            'currency_code' => $currency,
            'status' => FinancialPaymentCycle::STATUS_OPEN,
            'open_slot' => 1,
            'opened_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function upsertContribution(
        FinancialDailySummary $summary,
        string $metric,
        string $sourceType,
        int $sourceId,
        ?int $orderId,
        ?string $amount,
        bool $present,
        array $meta = [],
    ): void {
        $existing = FinancialSummaryContribution::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('metric', $metric)
            ->lockForUpdate()
            ->first();

        $payload = [
            'daily_summary_id' => $summary->id,
            'payment_cycle_id' => $summary->payment_cycle_id,
            'metric' => $metric,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'order_id' => $orderId,
            'amount' => $present ? $amount : null,
            'amount_present' => $present,
            'meta' => $meta === [] ? null : $meta,
            'voided_at' => null,
        ];

        if ($existing === null) {
            FinancialSummaryContribution::query()->create($payload);

            return;
        }

        $existing->forceFill($payload)->save();
    }

    private function voidContribution(string $sourceType, int $sourceId, string $metric): void
    {
        $row = FinancialSummaryContribution::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('metric', $metric)
            ->whereNull('voided_at')
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return;
        }

        $row->forceFill(['voided_at' => now()])->save();
        $summary = FinancialDailySummary::query()->find($row->daily_summary_id);
        if ($summary !== null) {
            $this->rebuildSummary($summary);
        }
    }

    private function voidBatchMetricContributions(string $sourceType, string $metric, int $batchId): void
    {
        $rows = FinancialSummaryContribution::query()
            ->where('source_type', $sourceType)
            ->where('metric', $metric)
            ->whereNull('voided_at')
            ->where('meta->batch_id', $batchId)
            ->lockForUpdate()
            ->get();

        $summaryIds = [];

        foreach ($rows as $row) {
            $row->forceFill(['voided_at' => now()])->save();
            $summaryIds[(int) $row->daily_summary_id] = true;
        }

        // Also void by live invoice ids when meta was not populated on older rows.
        $invoiceIds = $sourceType === FinancialSummaryContribution::SOURCE_SUPPLIER_INVOICE
            ? SupplierSettlementInvoice::query()->where('batch_id', $batchId)->pluck('id')
            : ResellerSettlementInvoice::query()->where('batch_id', $batchId)->pluck('id');

        foreach ($invoiceIds as $invoiceId) {
            $row = FinancialSummaryContribution::query()
                ->where('source_type', $sourceType)
                ->where('source_id', (int) $invoiceId)
                ->where('metric', $metric)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                continue;
            }

            $row->forceFill(['voided_at' => now()])->save();
            $summaryIds[(int) $row->daily_summary_id] = true;
        }

        foreach (array_keys($summaryIds) as $summaryId) {
            $summary = FinancialDailySummary::query()->find($summaryId);
            if ($summary !== null) {
                $this->rebuildSummary($summary);
            }
        }
    }

    private function rebuildSummary(FinancialDailySummary $summary): void
    {
        $metrics = [
            FinancialSummaryContribution::METRIC_INCOME => ['total_income', 'total_income_row_count', 'total_income_omitted_count'],
            FinancialSummaryContribution::METRIC_COURIER => ['courier_charges', 'courier_charges_row_count', 'courier_charges_omitted_count'],
            FinancialSummaryContribution::METRIC_SERVICE_CHARGE => ['service_charge', 'service_charge_row_count', 'service_charge_omitted_count'],
            FinancialSummaryContribution::METRIC_FINE => ['fine_charges', 'fine_charges_row_count', 'fine_charges_omitted_count'],
            FinancialSummaryContribution::METRIC_RETURN_CHARGE => ['return_charges', 'return_charges_row_count', 'return_charges_omitted_count'],
            FinancialSummaryContribution::METRIC_SUPPLIER => ['supplier_settlement_total', 'supplier_settlement_row_count', null],
            FinancialSummaryContribution::METRIC_RESELLER => ['reseller_settlement_total', 'reseller_settlement_row_count', null],
            FinancialSummaryContribution::METRIC_SUPPLIER_PAYMENT => ['supplier_payments_confirmed', 'supplier_payments_confirmed_row_count', 'supplier_payments_confirmed_omitted_count'],
            FinancialSummaryContribution::METRIC_RESELLER_PAYMENT => ['reseller_payments_confirmed', 'reseller_payments_confirmed_row_count', 'reseller_payments_confirmed_omitted_count'],
            FinancialSummaryContribution::METRIC_INTRODUCER_RESERVE => ['introducer_bonus_reserved', 'introducer_bonus_reserved_row_count', 'introducer_bonus_reserved_omitted_count'],
            FinancialSummaryContribution::METRIC_INTRODUCER_PAID => ['introducer_bonus_paid', 'introducer_bonus_paid_row_count', 'introducer_bonus_paid_omitted_count'],
        ];

        $updates = [];

        foreach ($metrics as $metric => $columns) {
            $rows = FinancialSummaryContribution::query()
                ->where('daily_summary_id', $summary->id)
                ->where('metric', $metric)
                ->whereNull('voided_at')
                ->get(['amount', 'amount_present']);

            $rowCount = $rows->count();
            $present = $rows->where('amount_present', true);
            $omitted = $rowCount - $present->count();
            $total = null;

            if ($present->count() > 0) {
                $total = '0.00';
                foreach ($present as $row) {
                    $total = bcadd($total, (string) $row->amount, DecimalAmount::SCALE);
                }
            }

            $updates[$columns[0]] = $total;
            $updates[$columns[1]] = $rowCount;
            if ($columns[2] !== null) {
                $updates[$columns[2]] = $omitted;
            }
        }

        $profit = $this->computeProfit($updates);
        $updates['company_profit'] = $profit['amount'];
        $updates['company_profit_status'] = $profit['status'];

        $summary->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $amounts
     * @return array{amount: ?string, status: string}
     */
    private function computeProfit(array $amounts): array
    {
        $income = $amounts['total_income'] ?? null;
        $courier = $amounts['courier_charges'] ?? null;
        $supplier = $amounts['supplier_settlement_total'] ?? null;
        $reseller = $amounts['reseller_settlement_total'] ?? null;

        // Income is eligible sale amount (gross COD / bank transfer), not net
        // remittance. Courier charge is therefore a separate cash outflow and
        // must be deducted once. Reseller net_payable already deducted supplier
        // cost and courier/service snapshots from the reseller share; those are
        // not the same as the supplier settlement payable or remittance charge,
        // so subtracting settlement totals here is not a second supplier/courier
        // deduction against the same ledger line.
        if ($income === null || $courier === null || $supplier === null || $reseller === null) {
            return [
                'amount' => null,
                'status' => FinancialDailySummary::PROFIT_STATUS_INCOMPLETE,
            ];
        }

        $amount = bcsub(
            bcsub(
                bcsub((string) $income, (string) $courier, DecimalAmount::SCALE),
                (string) $supplier,
                DecimalAmount::SCALE,
            ),
            (string) $reseller,
            DecimalAmount::SCALE,
        );

        return [
            'amount' => $amount,
            'status' => FinancialDailySummary::PROFIT_STATUS_AVAILABLE,
        ];
    }

    private function markCycleBatch(
        FinancialPaymentCycle $cycle,
        string $side,
        int $batchId,
        string $settlementDate,
    ): void {
        $payload = [
            'settlement_date' => $settlementDate,
        ];

        if ($side === 'supplier') {
            $payload['supplier_batch_id'] = $batchId;
            $payload['supplier_generated_at'] = $cycle->supplier_generated_at ?? now();
            $payload['supplier_fully_paid_at'] = null;
        } else {
            $payload['reseller_batch_id'] = $batchId;
            $payload['reseller_generated_at'] = $cycle->reseller_generated_at ?? now();
            $payload['reseller_fully_paid_at'] = null;
        }

        $cycle->forceFill($payload)->save();
    }

    private function maybeCloseCycle(FinancialPaymentCycle $cycle): void
    {
        if (! $cycle->isOpen()) {
            return;
        }

        if ($cycle->supplier_batch_id === null || $cycle->reseller_batch_id === null) {
            return;
        }

        if ($cycle->supplier_fully_paid_at === null || $cycle->reseller_fully_paid_at === null) {
            return;
        }

        if ($cycle->supplier_generated_at === null || $cycle->reseller_generated_at === null) {
            return;
        }

        $cycle->forceFill([
            'status' => FinancialPaymentCycle::STATUS_CLOSED,
            'open_slot' => null,
            'closed_at' => now(),
        ])->save();

        // Next payment starts empty; later remittances open a fresh cycle.
        FinancialPaymentCycle::query()->create([
            'market_id' => (int) $cycle->market_id,
            'currency_code' => (string) $cycle->currency_code,
            'status' => FinancialPaymentCycle::STATUS_OPEN,
            'open_slot' => 1,
            'opened_at' => now(),
        ]);
    }

    /**
     * Attach fully paid terminal batches to an orphan OPEN cycle and close it.
     */
    private function closeOpenCycleIfBothSidesFullyPaid(FinancialPaymentCycle $cycle): bool
    {
        if (! $cycle->isOpen()) {
            return false;
        }

        // A brand-new empty open cycle after rollover must not be closed again against
        // the same already-paid terminal batches.
        if (! FinancialDailySummary::query()->where('payment_cycle_id', $cycle->id)->exists()) {
            return false;
        }

        $marketId = (int) $cycle->market_id;
        $currency = $this->currencyCode($cycle->currency_code);

        if ($marketId <= 0 || $currency === null) {
            return false;
        }

        if ($cycle->supplier_batch_id !== null) {
            $linkedSupplier = SupplierSettlementBatch::query()
                ->with('invoices')
                ->find((int) $cycle->supplier_batch_id);
            if ($linkedSupplier === null
                || $linkedSupplier->voided_at !== null
                || ! $this->supplierBatchFullyPaid($linkedSupplier)) {
                return false;
            }
        }

        if ($cycle->reseller_batch_id !== null) {
            $linkedReseller = ResellerSettlementBatch::query()
                ->with('invoices')
                ->find((int) $cycle->reseller_batch_id);
            if ($linkedReseller === null
                || $linkedReseller->voided_at !== null
                || ! $this->resellerBatchFullyPaid($linkedReseller)) {
                return false;
            }
        }

        $supplier = $this->latestFullyPaidTerminalBatchForCycle('supplier', $marketId, $currency);
        $reseller = $this->latestFullyPaidTerminalBatchForCycle('reseller', $marketId, $currency);

        if ($supplier === null || $reseller === null) {
            return false;
        }

        if ($this->hasNewerMeaningfulUnsettledBatch('supplier', $marketId, $currency, $supplier['settlement_date'])
            || $this->hasNewerMeaningfulUnsettledBatch('reseller', $marketId, $currency, $reseller['settlement_date'])) {
            return false;
        }

        $settlementDate = max($supplier['settlement_date'], $reseller['settlement_date']);

        $cycle->forceFill([
            'supplier_batch_id' => $supplier['id'],
            'reseller_batch_id' => $reseller['id'],
            'supplier_generated_at' => $cycle->supplier_generated_at ?? $supplier['anchored_at'],
            'reseller_generated_at' => $cycle->reseller_generated_at ?? $reseller['anchored_at'],
            'supplier_fully_paid_at' => $cycle->supplier_fully_paid_at ?? now(),
            'reseller_fully_paid_at' => $cycle->reseller_fully_paid_at ?? now(),
            'settlement_date' => $settlementDate,
        ])->save();

        FinancialDailySummary::query()
            ->where('payment_cycle_id', $cycle->id)
            ->update([
                'supplier_batch_generated' => true,
                'reseller_batch_generated' => true,
                'supplier_fully_paid' => true,
                'reseller_fully_paid' => true,
            ]);

        $this->maybeCloseCycle($cycle->fresh());

        return FinancialPaymentCycle::query()
            ->whereKey($cycle->id)
            ->where('status', FinancialPaymentCycle::STATUS_CLOSED)
            ->exists();
    }

    /**
     * @return array{id: int, settlement_date: string, anchored_at: \Illuminate\Support\Carbon}|null
     */
    private function latestFullyPaidTerminalBatchForCycle(string $side, int $marketId, string $currency): ?array
    {
        $batchTable = $side === 'supplier' ? 'supplier_settlement_batches' : 'reseller_settlement_batches';
        $invoiceTable = $side === 'supplier' ? 'supplier_settlement_invoices' : 'reseller_settlement_invoices';
        $voidedStatus = $side === 'supplier'
            ? SupplierSettlementInvoice::INSTRUCTION_STATUS_VOIDED
            : ResellerSettlementInvoice::INSTRUCTION_STATUS_VOIDED;

        if (! Schema::hasTable($batchTable) || ! Schema::hasTable($invoiceTable)) {
            return null;
        }

        $batches = DB::table($batchTable)
            ->where('market_id', $marketId)
            ->whereNotNull('settlement_date')
            ->whereNull('voided_at')
            ->orderByDesc('settlement_date')
            ->orderByDesc('id')
            ->get(['id', 'settlement_date', 'status', 'closed_at']);

        foreach ($batches as $batch) {
            $settlementDate = $this->dateString($batch->settlement_date ?? null);
            if ($settlementDate === null) {
                continue;
            }

            $invoices = DB::table($invoiceTable)
                ->where('batch_id', (int) $batch->id)
                ->where('instruction_status', '!=', $voidedStatus)
                ->whereRaw('UPPER(TRIM(currency_code_snapshot)) = ?', [$currency])
                ->get();

            if ($invoices->isEmpty()) {
                continue;
            }

            // Newest meaningful batch for this currency must be fully paid. LOCKED
            // without payment fails supplierBatchFullyPaid / resellerBatchFullyPaid.
            if ($side === 'supplier') {
                $model = SupplierSettlementBatch::query()->with('invoices')->find((int) $batch->id);
                if ($model === null || ! $this->supplierBatchFullyPaid($model)) {
                    return null;
                }
            } else {
                $model = ResellerSettlementBatch::query()->with('invoices')->find((int) $batch->id);
                if ($model === null || ! $this->resellerBatchFullyPaid($model)) {
                    return null;
                }
            }

            return [
                'id' => (int) $batch->id,
                'settlement_date' => $settlementDate,
                'anchored_at' => now(),
            ];
        }

        return null;
    }

    private function hasNewerMeaningfulUnsettledBatch(
        string $side,
        int $marketId,
        string $currency,
        string $afterDate,
    ): bool {
        $batchTable = $side === 'supplier' ? 'supplier_settlement_batches' : 'reseller_settlement_batches';
        $invoiceTable = $side === 'supplier' ? 'supplier_settlement_invoices' : 'reseller_settlement_invoices';
        $voidedStatus = $side === 'supplier'
            ? SupplierSettlementInvoice::INSTRUCTION_STATUS_VOIDED
            : ResellerSettlementInvoice::INSTRUCTION_STATUS_VOIDED;

        $batches = DB::table($batchTable)
            ->where('market_id', $marketId)
            ->whereNull('voided_at')
            ->whereDate('settlement_date', '>', $afterDate)
            ->orderByDesc('settlement_date')
            ->orderByDesc('id')
            ->get(['id']);

        foreach ($batches as $batch) {
            $invoiceCount = DB::table($invoiceTable)
                ->where('batch_id', (int) $batch->id)
                ->where('instruction_status', '!=', $voidedStatus)
                ->whereRaw('UPPER(TRIM(currency_code_snapshot)) = ?', [$currency])
                ->count();

            if ($invoiceCount === 0) {
                continue;
            }

            if ($side === 'supplier') {
                $model = SupplierSettlementBatch::query()->with('invoices')->find((int) $batch->id);
                if ($model !== null && ! $this->supplierBatchFullyPaid($model)) {
                    return true;
                }
            } else {
                $model = ResellerSettlementBatch::query()->with('invoices')->find((int) $batch->id);
                if ($model !== null && ! $this->resellerBatchFullyPaid($model)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function supplierBatchFullyPaid(SupplierSettlementBatch $batch): bool
    {
        $invoices = $batch->invoices
            ->filter(fn (SupplierSettlementInvoice $invoice): bool => $invoice->instruction_status !== SupplierSettlementInvoice::INSTRUCTION_STATUS_VOIDED);

        if ($invoices->isEmpty()) {
            return false;
        }

        foreach ($invoices as $invoice) {
            if ($invoice->payment_status !== SupplierSettlementInvoice::PAYMENT_STATUS_PAID) {
                return false;
            }
        }

        return true;
    }

    private function resellerBatchFullyPaid(ResellerSettlementBatch $batch): bool
    {
        $invoices = $batch->invoices
            ->filter(fn (ResellerSettlementInvoice $invoice): bool => $invoice->instruction_status !== ResellerSettlementInvoice::INSTRUCTION_STATUS_VOIDED);

        if ($invoices->isEmpty()) {
            return false;
        }

        foreach ($invoices as $invoice) {
            $outstanding = $this->nullableMoney($invoice->outstanding_balance);
            if ($outstanding === null || bccomp($outstanding, '0', DecimalAmount::SCALE) === 1) {
                return false;
            }
        }

        return true;
    }

    private function nullableMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $this->money->tryNormalizeNonNegative($value)
                ?? (is_numeric($value) ? number_format((float) $value, 2, '.', '') : null);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Absolute monetary magnitude for payment ledger rows (sign applied by caller).
     */
    private function signedMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $normalized = $this->money->tryNormalizeAmount($value, true);
            if ($normalized === null) {
                return null;
            }

            return bccomp($normalized, '0', DecimalAmount::SCALE) < 0
                ? bcmul($normalized, '-1', DecimalAmount::SCALE)
                : $normalized;
        } catch (RuntimeException) {
            return is_numeric($value)
                ? number_format(abs((float) $value), 2, '.', '')
                : null;
        }
    }

    private function currencyCode(mixed $code): ?string
    {
        $normalized = is_string($code) || is_numeric($code) ? strtoupper(trim((string) $code)) : '';

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Market-local applied day when courier_remittance_applications.business_date is null.
     * Does not mutate the insert-only CRA row.
     */
    private function fallbackRemittanceBusinessDate(Order $order, CourierRemittanceApplication $application): ?string
    {
        $market = $order->relationLoaded('market') && $order->market instanceof Market
            ? $order->market
            : Market::query()->find((int) $order->market_id);

        if ($market === null) {
            return null;
        }

        $at = $application->applied_at;

        return $this->businessDates->businessDateString(
            $market,
            $at instanceof \DateTimeInterface ? $at : null,
        );
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $date = substr(trim((string) $value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    private function monthEndDate(int $year, int $month): ?string
    {
        if ($year < 1 || $month < 1 || $month > 12) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, (int) cal_days_in_month(CAL_GREGORIAN, $month, $year));
    }
}
