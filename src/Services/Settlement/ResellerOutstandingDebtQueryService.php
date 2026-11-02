<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Models\ResellerDebtObligation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only outstanding reseller debt queries for Minus Report foundations.
 *
 * Never mutates obligation balances. Totals are grouped by currency and never
 * combine LKR with MYR (or any other currency pair).
 *
 * Carry-forward model:
 * 1. Reseller has outstanding debt on reseller_debt_obligations.outstanding_amount.
 * 2. Plus Report earnings become available for the same reseller/market/currency.
 * 3. Plus Report offset is read-only; future payment recording must atomically reduce outstanding.
 * 4. Unpaid outstanding carries forward to subsequent periods.
 * 5. Source uniqueness + explicit reductions prevent double deduction.
 */
class ResellerOutstandingDebtQueryService
{
    /**
     * @param  array{
     *     reseller_id?: int|null,
     *     market_id?: int|null,
     *     currency_id?: int|null,
     *     currency_code?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     outstanding_only?: bool
     * }  $filters
     */
    public function paginateObligations(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($filters)
            ->with([
                'reseller.profile',
                'resellerCompany:id,name',
                'order:id,order_number,uuid,status',
                'market:id,code,name',
                'currency:id,iso_code',
            ])
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Outstanding and original totals grouped by currency_code_snapshot.
     *
     * @param  array{
     *     reseller_id?: int|null,
     *     market_id?: int|null,
     *     currency_id?: int|null,
     *     currency_code?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     outstanding_only?: bool
     * }  $filters
     * @return list<array{
     *     currency: string,
     *     original_total: string,
     *     outstanding_total: string,
     *     obligation_count: int
     * }>
     */
    public function totalsByCurrency(array $filters = []): array
    {
        $rows = $this->baseQuery($filters)
            ->select([
                'currency_code_snapshot',
                DB::raw('COUNT(*) as obligation_count'),
                DB::raw('SUM(original_amount) as original_total'),
                DB::raw('SUM(outstanding_amount) as outstanding_total'),
            ])
            ->groupBy('currency_code_snapshot')
            ->orderBy('currency_code_snapshot')
            ->get();

        return $rows->map(static function ($row): array {
            return [
                'currency' => (string) $row->currency_code_snapshot,
                'original_total' => number_format((float) $row->original_total, 2, '.', ''),
                'outstanding_total' => number_format((float) $row->outstanding_total, 2, '.', ''),
                'obligation_count' => (int) $row->obligation_count,
            ];
        })->all();
    }

    /**
     * Per-reseller outstanding balances grouped by currency (never cross-currency).
     *
     * @return Collection<int, array{
     *     reseller_id: int,
     *     currency: string,
     *     outstanding_total: string,
     *     obligation_count: int
     * }>
     */
    public function outstandingByResellerAndCurrency(?int $resellerId = null, ?int $marketId = null): Collection
    {
        $query = ResellerDebtObligation::query()
            ->where('outstanding_amount', '>', 0)
            ->select([
                'reseller_id',
                'currency_code_snapshot',
                DB::raw('COUNT(*) as obligation_count'),
                DB::raw('SUM(outstanding_amount) as outstanding_total'),
            ])
            ->groupBy('reseller_id', 'currency_code_snapshot')
            ->orderBy('reseller_id')
            ->orderBy('currency_code_snapshot');

        if ($resellerId !== null) {
            $query->where('reseller_id', $resellerId);
        }

        if ($marketId !== null) {
            $query->where('market_id', $marketId);
        }

        return $query->get()->map(static function ($row): array {
            return [
                'reseller_id' => (int) $row->reseller_id,
                'currency' => (string) $row->currency_code_snapshot,
                'outstanding_total' => number_format((float) $row->outstanding_total, 2, '.', ''),
                'obligation_count' => (int) $row->obligation_count,
            ];
        });
    }

    /**
     * @param  array{
     *     reseller_id?: int|null,
     *     market_id?: int|null,
     *     currency_id?: int|null,
     *     currency_code?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     outstanding_only?: bool
     * }  $filters
     * @return Builder<ResellerDebtObligation>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = ResellerDebtObligation::query();

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
            $query->whereDate('effective_date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('effective_date', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['outstanding_only'])) {
            $query->where('outstanding_amount', '>', 0);
        }

        return $query;
    }
}
