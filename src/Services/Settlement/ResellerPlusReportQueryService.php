<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\CourierReceiptImportRow;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ResellerEarningObligation;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only Plus Report assembly.
 *
 * Viewing, filtering, and paginating never create earning obligations or mutate debt.
 * Payable totals and debt-offset projections use only persisted earning obligations.
 * Live-calculable orders without an obligation are listed separately and excluded
 * from financial totals.
 */
class ResellerPlusReportQueryService
{
    public function __construct(
        private readonly ResellerEarningEligibilityEvaluator $eligibility,
        private readonly ResellerOutstandingDebtQueryService $outstandingDebt,
        private readonly ResellerSettlementDebtOffsetCalculator $debtOffset,
        private readonly ResellerSettlementCarryForwardReportQueryService $carryForward,
        private readonly MarketBusinessDateResolver $businessDates,
    ) {}

    /**
     * @param  array{
     *     reseller_id?: int|null,
     *     market_id?: int|null,
     *     currency_id?: int|null,
     *     currency_code?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null
     * }  $filters
     * @return array{
     *     earnings: LengthAwarePaginator,
     *     exclusions: list<array<string, mixed>>,
     *     calculated_but_not_recorded: list<array<string, mixed>>,
     *     offset_summaries: list<array<string, mixed>>,
     *     totals_by_currency: list<array<string, mixed>>,
     *     balance_buckets: list<array<string, mixed>>,
     *     completeness: array<string, mixed>,
     *     offset_period: array{date_filtered: bool, label: string, explanation: string}
     * }
     */
    public function buildReport(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $dateFiltered = ! empty($filters['date_from']) || ! empty($filters['date_to']);
        $offsetPeriod = [
            'date_filtered' => $dateFiltered,
            'label' => $dateFiltered
                ? 'Earnings in selected date range versus all outstanding debt'
                : 'Earnings versus all outstanding debt',
            'explanation' => $dateFiltered
                ? 'Eligible earnings are limited to the selected business-date range and exclude earnings already placed on a settlement invoice. '
                    .'Outstanding debt is the reseller\'s full open balance in that market/currency, '
                    .'not a period-specific debt slice. Debt balances are not mutated. '
                    .'Internal invoice offsets and cash payments are reported separately.'
                : 'New payable earnings exclude obligations already placed on a settlement invoice. '
                    .'Debt offset is hypothetical and never reduces outstanding debt balances. '
                    .'Outstanding invoice balances, internal offsets, and cash payments are separate figures.',
        ];

        $persistedRows = $this->buildPersistedEarningRows($filters);
        $persistedOrderIds = array_map(
            static fn (array $row): int => (int) $row['order_id'],
            $persistedRows,
        );
        $liveScan = $this->scanLiveRemittanceCandidates($filters, $persistedOrderIds);

        $exclusions = $liveScan['exclusions'];
        $calculatedButNotRecorded = $liveScan['calculated_but_not_recorded'];

        $offsetSummaries = $this->buildOffsetSummaries($persistedRows, $offsetPeriod);
        $totalsByCurrency = $this->buildTotalsByCurrency($persistedRows, $offsetSummaries);
        $balanceBuckets = $this->carryForward->balanceBuckets([
            'reseller_id' => $filters['reseller_id'] ?? null,
            'market_id' => $filters['market_id'] ?? null,
            'currency_id' => $filters['currency_id'] ?? null,
            'currency_code' => $filters['currency_code'] ?? null,
        ]);
        $completeness = $this->buildCompleteness(
            $filters,
            $persistedRows,
            $exclusions,
            $calculatedButNotRecorded,
        );

        $total = count($persistedRows);
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $slice = array_slice($persistedRows, ($page - 1) * $perPage, $perPage);

        $paginator = new ConcreteLengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );

        return [
            'earnings' => $paginator,
            'exclusions' => $exclusions,
            'calculated_but_not_recorded' => $calculatedButNotRecorded,
            'offset_summaries' => $offsetSummaries,
            'totals_by_currency' => $totalsByCurrency,
            'balance_buckets' => $balanceBuckets,
            'completeness' => $completeness,
            'offset_period' => $offsetPeriod,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function buildPersistedEarningRows(array $filters): array
    {
        $obligations = $this->obligationsQuery($filters)
            ->with([
                'order.items',
                'order.market',
                'order.currency',
                'order.shipment',
                'courierReceiptImport',
                'courierReceiptImportRow',
            ])
            ->orderByDesc('business_date')
            ->orderByDesc('order_id')
            ->get();

        $settledLookup = $this->settledObligationLookup(
            $obligations->map(static fn (ResellerEarningObligation $obligation): int => (int) $obligation->id)->all()
        );

        $rows = [];

        foreach ($obligations as $obligation) {
            /** @var ResellerEarningObligation $obligation */
            $order = $obligation->order;
            $businessDate = $obligation->business_date?->format('Y-m-d')
                ?? (string) $obligation->getRawOriginal('business_date');

            $rows[] = [
                'order_id' => (int) $obligation->order_id,
                'order_number' => (string) ($order?->order_number ?? ('#'.$obligation->order_id)),
                'reseller_id' => (int) $obligation->reseller_id,
                'reseller_company_id' => $obligation->reseller_company_id !== null
                    ? (int) $obligation->reseller_company_id
                    : null,
                'market_id' => (int) $obligation->market_id,
                'currency_id' => (int) $obligation->currency_id,
                'currency_code' => strtoupper((string) $obligation->currency_code_snapshot),
                'business_date' => $businessDate,
                'delivery_confirmed_at' => $obligation->courierReceiptImport?->confirmed_at?->toIso8601String(),
                'cod_amount' => number_format((float) $obligation->cod_amount_snapshot, 2, '.', ''),
                'supplier_cost' => $this->nullableObligationMoney($obligation, 'supplier_cost_snapshot'),
                'service_charge' => number_format((float) $obligation->service_charge_snapshot, 2, '.', ''),
                'courier_charge' => number_format((float) $obligation->courier_charge_snapshot, 2, '.', ''),
                'company_commission' => number_format((float) $obligation->company_commission_snapshot, 2, '.', ''),
                'gross_reseller_profit' => number_format((float) $obligation->gross_reseller_profit, 2, '.', ''),
                'obligation_id' => (int) $obligation->id,
                'obligation_number' => (string) $obligation->obligation_number,
                'obligation_persisted' => true,
                'settled_on_invoice' => isset($settledLookup[(int) $obligation->id]),
                'settlement_label' => $settledLookup[(int) $obligation->id]['label'] ?? null,
                'evidence_valid' => $order !== null
                    ? $this->eligibility->obligationRemittanceEvidenceIsValid($obligation, $order)
                    : false,
            ];
        }

        return $rows;
    }

    /**
     * Live remittance candidates without a persisted obligation contribute only to
     * calculated-but-not-recorded or exclusion lists — never to payable totals.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $persistedOrderIds
     * @return array{
     *     exclusions: list<array<string, mixed>>,
     *     calculated_but_not_recorded: list<array<string, mixed>>
     * }
     */
    private function scanLiveRemittanceCandidates(array $filters, array $persistedOrderIds): array
    {
        if (! Schema::hasTable('courier_receipt_import_rows')) {
            return [
                'exclusions' => [],
                'calculated_but_not_recorded' => [],
            ];
        }

        $persistedLookup = array_fill_keys(array_map('intval', $persistedOrderIds), true);
        $candidates = $this->collectCandidates($filters);

        $exclusions = [];
        $calculatedButNotRecorded = [];

        foreach ($candidates as $candidate) {
            /** @var Order $order */
            $order = $candidate['order'];
            $orderId = (int) $order->id;

            if (isset($persistedLookup[$orderId])) {
                continue;
            }

            $evaluation = $this->eligibility->evaluate($order);

            if ($evaluation['eligible'] && $evaluation['calculable']) {
                $financial = $evaluation['financial'];
                $calculatedButNotRecorded[] = [
                    'order_id' => $orderId,
                    'order_number' => (string) $order->order_number,
                    'reseller_id' => (int) $order->reseller_id,
                    'market_id' => (int) $order->market_id,
                    'currency_id' => $order->currency_id !== null ? (int) $order->currency_id : null,
                    'currency_code' => (string) $evaluation['currency_code'],
                    'business_date' => (string) $evaluation['business_date'],
                    'gross_reseller_profit' => (string) $financial['gross_reseller_profit'],
                    'cod_amount' => (string) $financial['cod_amount'],
                    'supplier_cost' => $financial['supplier_cost'] !== null
                        ? (string) $financial['supplier_cost']
                        : null,
                    'service_charge' => (string) $financial['service_charge'],
                    'courier_charge' => (string) $financial['courier_charge'],
                    'company_commission' => (string) $financial['company_commission'],
                    'explanation' => 'Order is remittance-confirmed and calculable, but no earning '
                        .'obligation was recorded. This amount is excluded from payable totals '
                        .'and debt-offset projections until an obligation is persisted.',
                ];

                continue;
            }

            $exclusions[] = [
                'order_id' => $orderId,
                'order_number' => (string) $order->order_number,
                'reseller_id' => $order->reseller_id !== null ? (int) $order->reseller_id : null,
                'market_id' => $order->market_id !== null ? (int) $order->market_id : null,
                'currency_code' => $evaluation['currency_code']
                    ?? ($order->currency_code_snapshot ? strtoupper((string) $order->currency_code_snapshot) : null),
                'business_date' => $evaluation['business_date'] ?? $candidate['business_date'],
                'exclusion_code' => $evaluation['exclusion_code'],
                'exclusion_reason' => $evaluation['exclusion_reason'],
                'missing_snapshots' => $evaluation['financial']['missing'] ?? [],
                'financial' => $evaluation['financial'],
            ];
        }

        return [
            'exclusions' => $exclusions,
            'calculated_but_not_recorded' => $calculatedButNotRecorded,
        ];
    }

    /**
     * @param  array{
     *     reseller_id?: int|null,
     *     market_id?: int|null,
     *     currency_id?: int|null,
     *     currency_code?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null
     * }  $filters
     * @return list<array{order: Order, business_date: string|null}>
     */
    private function collectCandidates(array $filters): array
    {
        $query = CourierReceiptImportRow::query()
            ->with([
                'import',
                'matchedOrder.items',
                'matchedOrder.market',
                'matchedOrder.currency',
                'matchedOrder.shipment',
                'matchedOrder.reseller.profile',
            ])
            ->where('match_result', CourierReceiptImportRowMatchResult::MATCHED->value)
            ->whereNotNull('matched_order_id')
            ->whereHas('import', static function ($q): void {
                $q->where('status', CourierReceiptImportStatus::CONFIRMED->value)
                    ->whereNotNull('confirmed_at');
            })
            ->whereHas('matchedOrder', function (Builder $q) use ($filters): void {
                $q->where('status', OrderStatus::DELIVERED->value);

                if (! empty($filters['reseller_id'])) {
                    $q->where('reseller_id', (int) $filters['reseller_id']);
                }
                if (! empty($filters['market_id'])) {
                    $q->where('market_id', (int) $filters['market_id']);
                }
                if (! empty($filters['currency_id'])) {
                    $q->where('currency_id', (int) $filters['currency_id']);
                }
                if (! empty($filters['currency_code'])) {
                    $q->where(
                        'currency_code_snapshot',
                        strtoupper(trim((string) $filters['currency_code']))
                    );
                }
            })
            ->orderBy('id');

        /** @var array<int, Order> $ordersById */
        $ordersById = [];

        foreach ($query->cursor() as $row) {
            $order = $row->matchedOrder;
            $import = $row->import;

            if ($order === null || $import === null || $import->confirmed_at === null) {
                continue;
            }

            $ordersById[(int) $order->id] = $order;
        }

        $orderIds = array_keys($ordersById);
        if ($orderIds === []) {
            return [];
        }

        $obligationsByOrderId = ResellerEarningObligation::query()
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy(static fn (ResellerEarningObligation $obligation): int => (int) $obligation->order_id);

        $candidates = [];

        foreach ($ordersById as $orderId => $order) {
            $existing = $obligationsByOrderId->get($orderId);
            $evidence = $this->eligibility->resolveConfirmedDeliveryEvidence($order, null, $existing);

            $businessDate = null;
            if ($evidence['resolution'] === 'resolved' && $evidence['import'] !== null) {
                $market = $order->market instanceof Market
                    ? $order->market
                    : Market::query()->find($order->market_id);

                if ($market !== null) {
                    $businessDate = $this->businessDates->businessDateString(
                        $market,
                        $evidence['import']->confirmed_at,
                    );
                }
            }

            if ($businessDate !== null) {
                if (! empty($filters['date_from']) && $businessDate < (string) $filters['date_from']) {
                    continue;
                }
                if (! empty($filters['date_to']) && $businessDate > (string) $filters['date_to']) {
                    continue;
                }
            }

            // Persisted obligations are handled via obligationsQuery; skip live scan.
            if ($existing !== null) {
                continue;
            }

            $candidates[] = [
                'order' => $order,
                'business_date' => $businessDate,
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            $dateA = (string) ($a['business_date'] ?? '');
            $dateB = (string) ($b['business_date'] ?? '');
            $dateCmp = strcmp($dateB, $dateA);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return $b['order']->id <=> $a['order']->id;
        });

        return $candidates;
    }

    /**
     * @param  list<array<string, mixed>>  $earningRows
     * @param  array{date_filtered: bool, label: string, explanation: string}  $offsetPeriod
     * @return list<array<string, mixed>>
     */
    private function buildOffsetSummaries(array $earningRows, array $offsetPeriod): array
    {
        $grouped = [];

        foreach ($earningRows as $row) {
            if (empty($row['obligation_persisted'])) {
                continue;
            }

            $key = $row['reseller_id'].'|'.$row['market_id'].'|'.$row['currency_code'];
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'reseller_id' => $row['reseller_id'],
                    'market_id' => $row['market_id'],
                    'currency' => $row['currency_code'],
                    'eligible_earnings' => 0.0,
                    'earning_count' => 0,
                    'settled_earnings' => 0.0,
                    'settled_earning_count' => 0,
                ];
            }

            if (! empty($row['settled_on_invoice'])) {
                $grouped[$key]['settled_earnings'] = round(
                    $grouped[$key]['settled_earnings'] + (float) $row['gross_reseller_profit'],
                    2
                );
                $grouped[$key]['settled_earning_count']++;

                continue;
            }

            $grouped[$key]['eligible_earnings'] = round(
                $grouped[$key]['eligible_earnings'] + (float) $row['gross_reseller_profit'],
                2
            );
            $grouped[$key]['earning_count']++;
        }

        $summaries = [];

        foreach ($grouped as $group) {
            $debtRows = $this->outstandingDebt->outstandingByResellerAndCurrency(
                (int) $group['reseller_id'],
                (int) $group['market_id'],
            );

            $outstanding = '0.00';
            foreach ($debtRows as $debtRow) {
                if (
                    (int) $debtRow['reseller_id'] === (int) $group['reseller_id']
                    && (string) $debtRow['currency'] === (string) $group['currency']
                ) {
                    $outstanding = (string) $debtRow['outstanding_total'];
                    break;
                }
            }

            $offset = $this->debtOffset->calculate(
                number_format((float) $group['eligible_earnings'], 2, '.', ''),
                $outstanding,
            );

            $summaries[] = [
                'reseller_id' => $group['reseller_id'],
                'market_id' => $group['market_id'],
                'currency' => $group['currency'],
                'earning_count' => $group['earning_count'],
                'eligible_earnings' => $offset['eligible_earnings'],
                'settled_earnings_excluded' => number_format((float) $group['settled_earnings'], 2, '.', ''),
                'settled_earning_count' => $group['settled_earning_count'],
                'outstanding_debt' => $offset['outstanding_debt'],
                'payable_earnings' => $offset['payable_earnings'],
                'remaining_debt' => $offset['remaining_debt'],
                'offset_period_label' => $offsetPeriod['label'],
                'uses_full_outstanding_debt' => true,
            ];
        }

        usort($summaries, static function (array $a, array $b): int {
            return [$a['reseller_id'], $a['currency']] <=> [$b['reseller_id'], $b['currency']];
        });

        return $summaries;
    }

    /**
     * @param  list<array<string, mixed>>  $earningRows
     * @param  list<array<string, mixed>>  $offsetSummaries
     * @return list<array<string, mixed>>
     */
    private function buildTotalsByCurrency(array $earningRows, array $offsetSummaries): array
    {
        $totals = [];

        foreach ($earningRows as $row) {
            if (empty($row['obligation_persisted'])) {
                continue;
            }

            $currency = (string) $row['currency_code'];
            if (! isset($totals[$currency])) {
                $totals[$currency] = [
                    'currency' => $currency,
                    'gross_earnings_total' => 0.0,
                    'settled_earnings_excluded_total' => 0.0,
                    'earning_count' => 0,
                    'payable_earnings_total' => 0.0,
                    'outstanding_debt_total' => 0.0,
                    'remaining_debt_total' => 0.0,
                ];
            }
            $totals[$currency]['gross_earnings_total'] = round(
                $totals[$currency]['gross_earnings_total'] + (float) $row['gross_reseller_profit'],
                2
            );
            $totals[$currency]['earning_count']++;
            if (! empty($row['settled_on_invoice'])) {
                $totals[$currency]['settled_earnings_excluded_total'] = round(
                    $totals[$currency]['settled_earnings_excluded_total'] + (float) $row['gross_reseller_profit'],
                    2
                );
            }
        }

        foreach ($offsetSummaries as $summary) {
            $currency = (string) $summary['currency'];
            if (! isset($totals[$currency])) {
                continue;
            }
            $totals[$currency]['payable_earnings_total'] = round(
                $totals[$currency]['payable_earnings_total'] + (float) $summary['payable_earnings'],
                2
            );
            $totals[$currency]['outstanding_debt_total'] = round(
                $totals[$currency]['outstanding_debt_total'] + (float) $summary['outstanding_debt'],
                2
            );
            $totals[$currency]['remaining_debt_total'] = round(
                $totals[$currency]['remaining_debt_total'] + (float) $summary['remaining_debt'],
                2
            );
        }

        ksort($totals);

        return array_values(array_map(static function (array $total): array {
            return [
                'currency' => $total['currency'],
                'gross_earnings_total' => number_format($total['gross_earnings_total'], 2, '.', ''),
                'settled_earnings_excluded_total' => number_format($total['settled_earnings_excluded_total'], 2, '.', ''),
                'payable_earnings_total' => number_format($total['payable_earnings_total'], 2, '.', ''),
                'outstanding_debt_total' => number_format($total['outstanding_debt_total'], 2, '.', ''),
                'remaining_debt_total' => number_format($total['remaining_debt_total'], 2, '.', ''),
                'earning_count' => $total['earning_count'],
            ];
        }, $totals));
    }

    /**
     * Coverage-gap counts only. These figures do not establish an amount payable.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>  $persistedRows
     * @param  list<array<string, mixed>>  $exclusions
     * @param  list<array<string, mixed>>  $calculatedButNotRecorded
     * @return array<string, mixed>
     */
    private function buildCompleteness(
        array $filters,
        array $persistedRows,
        array $exclusions,
        array $calculatedButNotRecorded,
    ): array {
        $deliveredWithoutObligation = $this->countDeliveredWithoutObligation($filters);

        $excludedByReason = [];
        foreach ($exclusions as $exclusion) {
            $code = (string) ($exclusion['exclusion_code'] ?? 'UNKNOWN');
            if (! isset($excludedByReason[$code])) {
                $excludedByReason[$code] = [
                    'exclusion_code' => $code,
                    'exclusion_reason' => (string) ($exclusion['exclusion_reason'] ?? ''),
                    'count' => 0,
                ];
            }
            $excludedByReason[$code]['count']++;
            if ($excludedByReason[$code]['exclusion_reason'] === '' && ! empty($exclusion['exclusion_reason'])) {
                $excludedByReason[$code]['exclusion_reason'] = (string) $exclusion['exclusion_reason'];
            }
        }

        ksort($excludedByReason);

        $invalidEvidenceCount = 0;
        foreach ($persistedRows as $row) {
            if (empty($row['evidence_valid'])) {
                $invalidEvidenceCount++;
            }
        }

        $missingEvidenceExcluded = (int) ($excludedByReason['DELIVERY_CONFIRMATION_MISSING']['count'] ?? 0);

        return [
            'disclaimer' => 'These counts indicate coverage gaps and completeness issues. '
                .'They do not themselves establish an amount payable, a confirmed debt, '
                .'or a financial loss.',
            'delivered_without_earning_obligation' => $deliveredWithoutObligation,
            'remittance_confirmed_excluded_total' => count($exclusions),
            'remittance_confirmed_excluded_by_reason' => array_values($excludedByReason),
            'obligations_with_invalid_evidence' => $invalidEvidenceCount,
            'calculated_but_not_recorded' => count($calculatedButNotRecorded),
            'missing_remittance_evidence_among_exclusions' => $missingEvidenceExcluded,
        ];
    }

    /**
     * DELIVERED orders in filter scope with no earning obligation.
     * Date filters use order delivered_at (calendar date) when remittance business
     * date cannot be assumed — historical orders without remittance evidence are
     * counted here as coverage gaps, not as zero earnings.
     *
     * @param  array<string, mixed>  $filters
     */
    private function countDeliveredWithoutObligation(array $filters): int
    {
        $query = Order::query()
            ->where('status', OrderStatus::DELIVERED->value)
            ->whereNotNull('reseller_id')
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('reseller_earning_obligations')
                    ->whereColumn('reseller_earning_obligations.order_id', 'orders.id');
            });

        if (! empty($filters['reseller_id'])) {
            $query->where('reseller_id', (int) $filters['reseller_id']);
        }
        if (! empty($filters['market_id'])) {
            $query->where('market_id', (int) $filters['market_id']);
        }
        if (! empty($filters['currency_id'])) {
            $query->where('currency_id', (int) $filters['currency_id']);
        }
        if (! empty($filters['currency_code']) && Schema::hasColumn('orders', 'currency_code_snapshot')) {
            $query->where(
                'currency_code_snapshot',
                strtoupper(trim((string) $filters['currency_code']))
            );
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('delivered_at', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('delivered_at', '<=', (string) $filters['date_to']);
        }

        return (int) $query->count();
    }

    /**
     * Null-safe money from a persisted obligation column (e.g. pre-Phase-1 supplier cost).
     */
    private function nullableObligationMoney(ResellerEarningObligation $obligation, string $column): ?string
    {
        $raw = array_key_exists($column, $obligation->getAttributes())
            ? $obligation->getAttributes()[$column]
            : $obligation->getRawOriginal($column);

        if ($raw === null || $raw === '') {
            return null;
        }

        return number_format((float) $raw, 2, '.', '');
    }

    /**
     * Persisted obligations query helper (read-only).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ResellerEarningObligation>
     */
    public function obligationsQuery(array $filters = []): Builder
    {
        $query = ResellerEarningObligation::query();

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
        if (! empty($filters['date_from'])) {
            $query->whereDate('business_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('business_date', '<=', (string) $filters['date_to']);
        }

        return $query;
    }

    /**
     * Earnings already copied onto an active settlement invoice are not new payable earnings.
     * The label states whether that invoice was settled by cash, an internal offset, both, or neither.
     *
     * @param  list<int>  $obligationIds
     * @return array<int, array{label: string}>
     */
    private function settledObligationLookup(array $obligationIds): array
    {
        if ($obligationIds === []
            || ! Schema::hasTable('reseller_settlement_invoice_lines')
            || ! Schema::hasTable('reseller_settlement_invoices')
        ) {
            return [];
        }

        $columns = [
            'lines.earning_obligation_id',
            'lines.line_payment_status',
            'invoices.payment_status',
            'invoices.amount_paid',
            'invoices.outstanding_balance',
        ];
        if (Schema::hasColumn('reseller_settlement_invoices', 'offset_applied_total')) {
            $columns[] = 'invoices.offset_applied_total';
        }
        if (Schema::hasColumn('reseller_settlement_invoices', 'previous_invoice_offset_total')) {
            $columns[] = 'invoices.previous_invoice_offset_total';
        }

        $rows = DB::table('reseller_settlement_invoice_lines as lines')
            ->join('reseller_settlement_invoices as invoices', 'invoices.id', '=', 'lines.invoice_id')
            ->whereIn('lines.earning_obligation_id', $obligationIds)
            ->where('invoices.instruction_status', ResellerSettlementInvoice::INSTRUCTION_STATUS_ACTIVE)
            ->whereNull('invoices.voided_at')
            ->get($columns);

        $lookup = [];
        foreach ($rows as $row) {
            $lookup[(int) $row->earning_obligation_id] = [
                'label' => $this->earningSettlementLabel($row),
            ];
        }

        return $lookup;
    }

    private function earningSettlementLabel(object $row): string
    {
        $paid = (float) ($row->amount_paid ?? 0) > 0;
        $offsetApplied = (float) ($row->offset_applied_total ?? 0) > 0;
        $offsetIssued = (float) ($row->previous_invoice_offset_total ?? 0) > 0;
        $open = (float) ($row->outstanding_balance ?? 0) > 0;
        $linePaid = (string) ($row->line_payment_status ?? '') === 'PAID';

        if ($paid && $offsetApplied) {
            return $open
                ? 'Cash payment and an internal invoice offset both apply. Balance remains. Excluded from new payable earnings.'
                : 'Settled by a cash payment and an internal invoice offset. Excluded from new payable earnings.';
        }

        if ($paid && $offsetIssued) {
            return $open
                ? 'Partially settled by a cash payment. This invoice also applied an internal offset to a previous invoice. Excluded from new payable earnings.'
                : 'Settled by a cash payment after applying an internal offset to a previous invoice. Excluded from new payable earnings.';
        }

        if ($linePaid || ($paid && ! $open)) {
            return 'Settled by a cash payment. Excluded from new payable earnings.';
        }

        if ($paid) {
            return 'Partially settled by a cash payment. No internal invoice offset. Excluded from new payable earnings.';
        }

        if ($offsetApplied) {
            return $open
                ? 'Partially settled by an internal invoice offset, not a cash payment. Excluded from new payable earnings.'
                : 'Settled by an internal invoice offset, not a cash payment. Excluded from new payable earnings.';
        }

        if ($offsetIssued) {
            return 'Applied to a previous invoice as an internal offset, not a cash payment. Excluded from new payable earnings.';
        }

        return 'On an open invoice. Not settled by cash or an internal offset. Excluded from new payable earnings.';
    }
}
