<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Models\ResellerDebtObligation;
use Feeder\Core\Models\ResellerPayment;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Models\ResellerSettlementInvoiceOffset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only buckets that must stay separate in Plus and Minus reports.
 *
 * Outstanding invoice balances, debt still awaiting allocation, internal
 * invoice offsets, and cash payments are never added together and never
 * combined across currencies.
 */
class ResellerSettlementCarryForwardReportQueryService
{
    /**
     * @param  array{
     *     reseller_id?: int|null,
     *     market_id?: int|null,
     *     currency_id?: int|null,
     *     currency_code?: string|null
     * }  $filters
     * @return list<array{
     *     currency: string,
     *     outstanding_invoice_balance: string,
     *     debt_awaiting_allocation: string,
     *     internal_invoice_offset_total: string,
     *     cash_payment_total: string
     * }>
     */
    public function balanceBuckets(array $filters = []): array
    {
        $currencies = [];

        foreach ($this->invoiceOutstanding($filters) as $currency => $amount) {
            $currencies[$currency]['outstanding_invoice_balance'] = $amount;
        }

        foreach ($this->debtAwaitingAllocation($filters) as $currency => $amount) {
            $currencies[$currency]['debt_awaiting_allocation'] = $amount;
        }

        foreach ($this->internalOffsets($filters) as $currency => $amount) {
            $currencies[$currency]['internal_invoice_offset_total'] = $amount;
        }

        foreach ($this->cashPayments($filters) as $currency => $amount) {
            $currencies[$currency]['cash_payment_total'] = $amount;
        }

        ksort($currencies);

        $rows = [];
        foreach ($currencies as $currency => $bucket) {
            $rows[] = [
                'currency' => $currency,
                'outstanding_invoice_balance' => $bucket['outstanding_invoice_balance'] ?? '0.00',
                'debt_awaiting_allocation' => $bucket['debt_awaiting_allocation'] ?? '0.00',
                'internal_invoice_offset_total' => $bucket['internal_invoice_offset_total'] ?? '0.00',
                'cash_payment_total' => $bucket['cash_payment_total'] ?? '0.00',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function invoiceOutstanding(array $filters): array
    {
        if (! Schema::hasTable('reseller_settlement_invoices')
            || ! Schema::hasTable('reseller_settlement_batches')
        ) {
            return [];
        }

        $query = ResellerSettlementInvoice::query()
            ->join(
                'reseller_settlement_batches',
                'reseller_settlement_batches.id',
                '=',
                'reseller_settlement_invoices.batch_id',
            )
            ->where(
                'reseller_settlement_invoices.instruction_status',
                ResellerSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
            )
            ->whereNull('reseller_settlement_invoices.voided_at')
            ->whereNull('reseller_settlement_batches.voided_at');

        $this->applyInvoiceFilters($query, $filters);

        $rows = $query
            ->select([
                'reseller_settlement_invoices.currency_code_snapshot',
                DB::raw('SUM(reseller_settlement_invoices.outstanding_balance) as outstanding_total'),
            ])
            ->groupBy('reseller_settlement_invoices.currency_code_snapshot')
            ->get();

        return $this->mapCurrencyAmounts($rows, 'outstanding_total');
    }

    /**
     * Open fine balances, including amounts carried from earlier effective dates.
     * Date windows are intentionally ignored so a period filter cannot hide
     * debt that is still awaiting allocation, and original fines are not re-added.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function debtAwaitingAllocation(array $filters): array
    {
        if (! Schema::hasTable('reseller_debt_obligations')) {
            return [];
        }

        $query = ResellerDebtObligation::query()->where('outstanding_amount', '>', 0);

        if (! empty($filters['reseller_id'])) {
            $query->where('reseller_id', (int) $filters['reseller_id']);
        }
        if (! empty($filters['market_id'])) {
            $query->where('market_id', (int) $filters['market_id']);
        }
        if (! empty($filters['currency_id'])) {
            $query->where('currency_id', (int) $filters['currency_id']);
        }
        if (! empty($filters['currency_code'])) {
            $query->where('currency_code_snapshot', strtoupper(trim((string) $filters['currency_code'])));
        }

        $rows = $query
            ->select([
                'currency_code_snapshot',
                DB::raw('SUM(outstanding_amount) as outstanding_total'),
            ])
            ->groupBy('currency_code_snapshot')
            ->get();

        return $this->mapCurrencyAmounts($rows, 'outstanding_total');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function internalOffsets(array $filters): array
    {
        if (! Schema::hasTable('reseller_settlement_invoice_offsets')) {
            return [];
        }

        $query = ResellerSettlementInvoiceOffset::query();

        if (! empty($filters['reseller_id'])) {
            $query->where('reseller_id', (int) $filters['reseller_id']);
        }
        if (! empty($filters['market_id'])) {
            $query->where('market_id', (int) $filters['market_id']);
        }
        if (! empty($filters['currency_id'])) {
            $query->where('currency_id', (int) $filters['currency_id']);
        }
        if (! empty($filters['currency_code'])) {
            $query->where('currency_code_snapshot', strtoupper(trim((string) $filters['currency_code'])));
        }

        $rows = $query
            ->select([
                'currency_code_snapshot',
                DB::raw('SUM(allocated_amount) as offset_total'),
            ])
            ->groupBy('currency_code_snapshot')
            ->get();

        return $this->mapCurrencyAmounts($rows, 'offset_total');
    }

    /**
     * Recorded cash payments only. Internal offsets are not included.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function cashPayments(array $filters): array
    {
        if (! Schema::hasTable('reseller_payments')
            || ! Schema::hasTable('reseller_settlement_invoices')
            || ! Schema::hasTable('reseller_settlement_batches')
        ) {
            return [];
        }

        $query = ResellerPayment::query()
            ->join(
                'reseller_settlement_invoices',
                'reseller_settlement_invoices.id',
                '=',
                'reseller_payments.invoice_id',
            )
            ->join(
                'reseller_settlement_batches',
                'reseller_settlement_batches.id',
                '=',
                'reseller_settlement_invoices.batch_id',
            )
            ->where('reseller_payments.entry_type', ResellerPayment::ENTRY_TYPE_PAYMENT);

        if (! empty($filters['reseller_id'])) {
            $query->where('reseller_payments.reseller_id', (int) $filters['reseller_id']);
        }
        if (! empty($filters['market_id'])) {
            $query->where('reseller_settlement_batches.market_id', (int) $filters['market_id']);
        }
        if (! empty($filters['currency_id'])) {
            $query->where('reseller_payments.currency_id', (int) $filters['currency_id']);
        }
        if (! empty($filters['currency_code'])) {
            $query->where(
                'reseller_payments.currency_code_snapshot',
                strtoupper(trim((string) $filters['currency_code'])),
            );
        }

        $rows = $query
            ->select([
                'reseller_payments.currency_code_snapshot',
                DB::raw('SUM(reseller_payments.amount) as cash_total'),
            ])
            ->groupBy('reseller_payments.currency_code_snapshot')
            ->get();

        return $this->mapCurrencyAmounts($rows, 'cash_total');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ResellerSettlementInvoice>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyInvoiceFilters($query, array $filters): void
    {
        if (! empty($filters['reseller_id'])) {
            $query->where('reseller_settlement_invoices.reseller_id', (int) $filters['reseller_id']);
        }
        if (! empty($filters['market_id'])) {
            $query->where('reseller_settlement_batches.market_id', (int) $filters['market_id']);
        }
        if (! empty($filters['currency_id'])) {
            $query->where('reseller_settlement_invoices.currency_id', (int) $filters['currency_id']);
        }
        if (! empty($filters['currency_code'])) {
            $query->where(
                'reseller_settlement_invoices.currency_code_snapshot',
                strtoupper(trim((string) $filters['currency_code'])),
            );
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, string>
     */
    private function mapCurrencyAmounts($rows, string $column): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            $currency = strtoupper((string) $row->currency_code_snapshot);
            if ($currency === '') {
                continue;
            }

            $mapped[$currency] = number_format((float) $row->{$column}, 2, '.', '');
        }

        return $mapped;
    }
}
