<?php

namespace Feeder\Core\Services\Finance;

use Feeder\Core\Enums\OrderPaymentMethod;
use Feeder\Core\Enums\OrderPaymentReviewStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live SQL income basis aligned with FinancialDailySummaryUpdater::resolveIncomeAmount.
 *
 * One remittance row contributes either:
 *  - the single APPROVED BANK_TRANSFER submission amount, or
 *  - COD gross_remittance_amount when that BT path is not calculable.
 *
 * Never uses net_remittance_amount. Never adds gross and bank transfer together.
 * Ambiguous / missing / invalid approved transfers fall through to gross (same as the updater).
 *
 * The approved-BT lookup is one grouped LEFT JOIN so remittance aggregates stay indexed
 * and do not re-introduce per-row correlated subqueries.
 */
class FinancialOverviewIncomeAmount
{
    public const BT_JOIN_ALIAS = 'overview_income_bt';

    public static function sqlExpression(
        string $grossColumn = 'src.gross_remittance_amount',
        string $btAlias = self::BT_JOIN_ALIAS,
    ): string {
        if (! self::bankTransferTableReady()) {
            return $grossColumn;
        }

        return 'COALESCE('.$btAlias.'.approved_amount, '.$grossColumn.')';
    }

    public static function applySingleApprovedBankTransferJoin(
        Builder $query,
        string $orderIdColumn = 'src.order_id',
        string $alias = self::BT_JOIN_ALIAS,
    ): void {
        if (! self::bankTransferTableReady()) {
            return;
        }

        $sub = DB::table('order_payment_submissions')
            ->select('order_id')
            ->selectRaw('MAX(amount) as approved_amount')
            ->whereRaw('UPPER(TRIM(method)) = ?', [OrderPaymentMethod::BANK_TRANSFER->value])
            ->whereRaw('UPPER(TRIM(review_status)) = ?', [OrderPaymentReviewStatus::APPROVED->value])
            ->whereNotNull('amount')
            ->whereRaw("TRIM(amount) <> ''")
            ->whereRaw('CAST(amount AS DECIMAL(15,2)) >= 0')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) = 1');

        $query->leftJoinSub($sub, $alias, $alias.'.order_id', '=', $orderIdColumn);
    }

    private static function bankTransferTableReady(): bool
    {
        // Avoid Schema::hasTable cache across scratch databases in the same process.
        try {
            DB::select('select 1 as present from `order_payment_submissions` limit 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
