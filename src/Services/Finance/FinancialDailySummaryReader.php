<?php

namespace Feeder\Core\Services\Finance;

use Feeder\Core\Models\FinancialDailySummary;
use Feeder\Core\Models\FinancialPaymentCycle;
use Feeder\Core\Support\DecimalAmount;
use Illuminate\Support\Facades\Schema;

/**
 * Fast read path for admin financial overview cards and daily rows.
 *
 * Prefers persisted cycle/day summaries. Returns null buckets when the summary
 * tables are absent or empty so callers can fall back to live aggregation.
 */
class FinancialDailySummaryReader
{
    private ?bool $hasRows = null;

    public function tablesReady(): bool
    {
        return Schema::hasTable('financial_payment_cycles')
            && Schema::hasTable('financial_daily_summaries');
    }

    public function hasPersistedRows(): bool
    {
        if ($this->hasRows !== null) {
            return $this->hasRows;
        }

        if (! $this->tablesReady()) {
            return $this->hasRows = false;
        }

        return $this->hasRows = FinancialDailySummary::query()->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cardFigure(
        string $metric,
        string $mode,
        string $currency,
        ?string $beforeDate,
        ?string $onDate = null,
    ): ?array {
        if (! $this->hasPersistedRows()) {
            return null;
        }

        $currency = strtoupper(trim($currency));
        $column = $this->metricColumn($metric);

        if ($column === null) {
            return null;
        }

        if ($mode === 'next') {
            $bucket = $this->aggregateOpenCycle($column, $currency, $metric);

            return ($bucket['recorded'] ?? false) ? $bucket : null;
        }

        if ($onDate !== null) {
            $bucket = $this->aggregateExactDay($column, $currency, $onDate, $metric);

            return ($bucket['recorded'] ?? false) ? $bucket : null;
        }

        $bucket = $this->aggregateLatestDay($column, $currency, $beforeDate, $metric);

        return ($bucket['recorded'] ?? false) ? $bucket : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function profitFigure(string $mode, string $currency, ?string $beforeDate): ?array
    {
        if (! $this->hasPersistedRows()) {
            return null;
        }

        $currency = strtoupper(trim($currency));

        if ($mode === 'next') {
            $rows = $this->openCycleRows($currency);
            if ($rows->isEmpty()) {
                return null;
            }

            return $this->rollProfit($rows, null);
        }

        $date = $this->latestSummaryDate($currency, $beforeDate);
        if ($date === null) {
            return null;
        }

        $rows = $this->summaryRowsForLatestCardDate($currency, $date, $beforeDate);

        return $this->rollProfit($rows, $date);
    }

    /**
     * @param  list<string>  $dates
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function dailyFigures(string $metric, array $dates, string $mode = 'latest'): array
    {
        if (! $this->hasPersistedRows() || $dates === []) {
            return [];
        }

        $column = $this->metricColumn($metric);
        if ($column === null) {
            return [];
        }

        $query = FinancialDailySummary::query()
            ->whereIn('business_date', $dates);

        if ($mode === 'next') {
            $query->whereIn('payment_cycle_id', function ($sub): void {
                $sub->select('id')
                    ->from('financial_payment_cycles')
                    ->where('status', FinancialPaymentCycle::STATUS_OPEN);
            });
        }

        $byDate = [];

        foreach ($query->get() as $row) {
            $date = $row->business_date?->format('Y-m-d');
            $currency = strtoupper(trim((string) $row->currency_code));
            if ($date === null || $currency === '') {
                continue;
            }

            $byDate[$date][$currency] = $this->bucketFromSummary($row, $column, $metric, $date);
        }

        return $byDate;
    }

    private function aggregateOpenCycle(string $column, string $currency, string $metric): array
    {
        $rows = $this->openCycleRows($currency);

        if ($rows->isEmpty()) {
            return $this->blank(null);
        }

        return $this->rollMetric($rows, $column, $metric, null);
    }

    private function aggregateExactDay(string $column, string $currency, string $onDate, string $metric): array
    {
        $rows = FinancialDailySummary::query()
            ->whereRaw('UPPER(TRIM(currency_code)) = ?', [$currency])
            ->whereDate('business_date', $onDate)
            ->get();

        return $this->rollMetric($rows, $column, $metric, $onDate);
    }

    private function aggregateLatestDay(string $column, string $currency, ?string $beforeDate, string $metric): array
    {
        $date = $this->latestSummaryDate($currency, $beforeDate, $metric);

        if ($date === null) {
            return $this->blank(null);
        }

        $rows = $this->summaryRowsForLatestCardDate($currency, $date, $beforeDate);

        return $this->rollMetric($rows, $column, $metric, $date);
    }

    /**
     * Latest business date that actually recorded the requested metric.
     *
     * Settlement-only or service-charge-only summary rows must not steal the
     * card date from an older remittance day that still holds income/courier.
     *
     * Historical days use any cycle with business_date strictly before the
     * market-local "today" limit. CLOSED-cycle rows on that same limit day are
     * also eligible so a Next Payment that completes today remains visible in
     * Latest Details. Still-OPEN rows on the limit day stay in Next Payment.
     */
    private function latestSummaryDate(string $currency, ?string $beforeDate, ?string $metric = null): ?string
    {
        $historical = $this->maxMetricSummaryDate(
            $currency,
            $metric,
            strictBeforeDate: $beforeDate,
            closedOnly: false,
        );

        if ($beforeDate === null) {
            return $historical;
        }

        $closedThroughToday = $this->maxMetricSummaryDate(
            $currency,
            $metric,
            onOrBeforeDate: $beforeDate,
            closedOnly: true,
        );

        $candidates = array_values(array_filter(
            [$historical, $closedThroughToday],
            static fn (?string $date): bool => is_string($date) && $date !== '',
        ));

        return $candidates === [] ? null : max($candidates);
    }

    /**
     * @return \Illuminate\Support\Collection<int, FinancialDailySummary>
     */
    private function summaryRowsForLatestCardDate(string $currency, string $date, ?string $beforeDate)
    {
        $query = FinancialDailySummary::query()
            ->whereRaw('UPPER(TRIM(currency_code)) = ?', [$currency])
            ->whereDate('business_date', $date);

        // The current business day belongs to Latest only after the payment cycle
        // closes. Open-cycle amounts on that day remain Next Payment.
        if ($beforeDate !== null && $date === $beforeDate) {
            $query->whereIn('payment_cycle_id', function ($sub): void {
                $sub->select('id')
                    ->from('financial_payment_cycles')
                    ->where('status', FinancialPaymentCycle::STATUS_CLOSED);
            });
        }

        return $query->get();
    }

    private function maxMetricSummaryDate(
        string $currency,
        ?string $metric = null,
        ?string $strictBeforeDate = null,
        ?string $onOrBeforeDate = null,
        bool $closedOnly = false,
    ): ?string {
        $query = FinancialDailySummary::query()
            ->whereRaw('UPPER(TRIM(currency_code)) = ?', [$currency])
            ->whereNotNull('business_date');

        if ($strictBeforeDate !== null) {
            $query->whereDate('business_date', '<', $strictBeforeDate);
        }

        if ($onOrBeforeDate !== null) {
            $query->whereDate('business_date', '<=', $onOrBeforeDate);
        }

        if ($closedOnly) {
            $query->whereIn('payment_cycle_id', function ($sub): void {
                $sub->select('id')
                    ->from('financial_payment_cycles')
                    ->where('status', FinancialPaymentCycle::STATUS_CLOSED);
            });
        }

        if ($metric !== null) {
            $column = $this->metricColumn($metric);
            $countCol = $this->rowCountColumn($metric);
            $omitCol = $this->omittedColumn($metric);

            if ($column !== null) {
                $query->where(function ($scope) use ($column, $countCol, $omitCol): void {
                    $scope->whereNotNull($column);

                    if ($countCol !== null) {
                        $scope->orWhere($countCol, '>', 0);
                    }

                    if ($omitCol !== null) {
                        $scope->orWhere($omitCol, '>', 0);
                    }
                });
            }
        }

        $value = $query->max('business_date');

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $date = substr(trim((string) $value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, FinancialDailySummary>
     */
    private function openCycleRows(string $currency)
    {
        return FinancialDailySummary::query()
            ->whereRaw('UPPER(TRIM(currency_code)) = ?', [$currency])
            ->whereIn('payment_cycle_id', function ($sub): void {
                $sub->select('id')
                    ->from('financial_payment_cycles')
                    ->where('status', FinancialPaymentCycle::STATUS_OPEN);
            })
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FinancialDailySummary>  $rows
     * @return array<string, mixed>
     */
    private function rollMetric($rows, string $column, string $metric, ?string $date): array
    {
        $rowCount = 0;
        $omitted = 0;
        $total = null;
        $hasRows = false;

        foreach ($rows as $row) {
            $countCol = $this->rowCountColumn($metric);
            $omitCol = $this->omittedColumn($metric);
            $count = $countCol !== null ? (int) ($row->{$countCol} ?? 0) : (( $row->{$column} !== null) ? 1 : 0);
            $omit = $omitCol !== null ? (int) ($row->{$omitCol} ?? 0) : 0;

            if ($count > 0 || $row->{$column} !== null) {
                $hasRows = true;
            }

            $rowCount += $count;
            $omitted += $omit;

            if ($row->{$column} !== null) {
                $total = bcadd($total ?? '0.00', (string) $row->{$column}, DecimalAmount::SCALE);
            }
        }

        if (! $hasRows) {
            return $this->blank($date);
        }

        $bucket = $this->blank($date);
        $bucket['recorded'] = true;
        $bucket['row_count'] = $rowCount;
        $bucket['omitted_count'] = $omitted;
        $bucket['amount'] = $total;

        if ($total === null && $omitted > 0) {
            $bucket['amount'] = null;
        }

        return $bucket;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FinancialDailySummary>  $rows
     * @return array<string, mixed>
     */
    private function rollProfit($rows, ?string $date): array
    {
        $available = true;
        $total = '0.00';
        $any = false;

        foreach ($rows as $row) {
            if ($row->company_profit_status !== FinancialDailySummary::PROFIT_STATUS_AVAILABLE
                || $row->company_profit === null) {
                $available = false;

                continue;
            }

            $any = true;
            $total = bcadd($total, (string) $row->company_profit, DecimalAmount::SCALE);
        }

        if (! $any || ! $available) {
            return [
                'recorded' => $any,
                'amount' => null,
                'date' => $date,
                'reason' => $any
                    ? 'Company profit is incomplete until income, courier charges, and both settlement totals are present for the same scope.'
                    : null,
                'unavailable' => false,
                'status' => FinancialDailySummary::PROFIT_STATUS_INCOMPLETE,
            ];
        }

        return [
            'recorded' => true,
            'amount' => $total,
            'date' => $date,
            'reason' => null,
            'unavailable' => false,
            'status' => FinancialDailySummary::PROFIT_STATUS_AVAILABLE,
        ];
    }

    private function bucketFromSummary(FinancialDailySummary $row, string $column, string $metric, string $date): array
    {
        $bucket = $this->blank($date);
        $countCol = $this->rowCountColumn($metric);
        $omitCol = $this->omittedColumn($metric);
        $count = $countCol !== null ? (int) ($row->{$countCol} ?? 0) : 0;

        if ($count === 0 && $row->{$column} === null) {
            return $bucket;
        }

        $bucket['recorded'] = true;
        $bucket['row_count'] = $count;
        $bucket['omitted_count'] = $omitCol !== null ? (int) ($row->{$omitCol} ?? 0) : 0;
        $bucket['amount'] = $row->{$column} !== null ? (string) $row->{$column} : null;

        return $bucket;
    }

    private function metricColumn(string $metric): ?string
    {
        return match ($metric) {
            'income' => 'total_income',
            'courier' => 'courier_charges',
            'service_charge' => 'service_charge',
            'fine' => 'fine_charges',
            'return_charge' => 'return_charges',
            'supplier' => 'supplier_settlement_total',
            'reseller' => 'reseller_settlement_total',
            'supplier_payment' => 'supplier_payments_confirmed',
            'reseller_payment' => 'reseller_payments_confirmed',
            'supplier_outstanding' => 'supplier_outstanding_balance',
            'reseller_outstanding' => 'reseller_outstanding_balance',
            'introducer_reserve' => 'introducer_bonus_reserved',
            'introducer_paid' => 'introducer_bonus_paid',
            default => null,
        };
    }

    private function rowCountColumn(string $metric): ?string
    {
        return match ($metric) {
            'income' => 'total_income_row_count',
            'courier' => 'courier_charges_row_count',
            'service_charge' => 'service_charge_row_count',
            'fine' => 'fine_charges_row_count',
            'return_charge' => 'return_charges_row_count',
            'supplier' => 'supplier_settlement_row_count',
            'reseller' => 'reseller_settlement_row_count',
            'supplier_payment' => 'supplier_payments_confirmed_row_count',
            'reseller_payment' => 'reseller_payments_confirmed_row_count',
            'introducer_reserve' => 'introducer_bonus_reserved_row_count',
            'introducer_paid' => 'introducer_bonus_paid_row_count',
            default => null,
        };
    }

    private function omittedColumn(string $metric): ?string
    {
        return match ($metric) {
            'income' => 'total_income_omitted_count',
            'courier' => 'courier_charges_omitted_count',
            'service_charge' => 'service_charge_omitted_count',
            'fine' => 'fine_charges_omitted_count',
            'return_charge' => 'return_charges_omitted_count',
            'supplier_payment' => 'supplier_payments_confirmed_omitted_count',
            'reseller_payment' => 'reseller_payments_confirmed_omitted_count',
            'introducer_reserve' => 'introducer_bonus_reserved_omitted_count',
            'introducer_paid' => 'introducer_bonus_paid_omitted_count',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(?string $date): array
    {
        return [
            'recorded' => false,
            'amount' => null,
            'row_count' => 0,
            'omitted_count' => 0,
            'unreadable' => false,
            'unavailable' => false,
            'reason' => null,
            'date' => $date,
            'date_label' => null,
        ];
    }
}
