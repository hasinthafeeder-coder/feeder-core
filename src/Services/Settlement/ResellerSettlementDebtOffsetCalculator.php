<?php

namespace Feeder\Core\Services\Settlement;

/**
 * Read-only debt offset arithmetic for Plus Report settlement calculation.
 *
 * Payable reseller earnings = max(0, eligible earnings − outstanding debt)
 * Remaining debt           = max(0, outstanding debt − eligible earnings)
 *
 * Never mutates reseller_debt_obligations or records payments.
 */
class ResellerSettlementDebtOffsetCalculator
{
    /**
     * @return array{
     *     eligible_earnings: string,
     *     outstanding_debt: string,
     *     payable_earnings: string,
     *     remaining_debt: string
     * }
     */
    public function calculate(string $eligibleEarnings, string $outstandingDebt): array
    {
        $earnings = $this->money($eligibleEarnings);
        $debt = $this->money($outstandingDebt);

        $payable = max(0.0, round($earnings - $debt, 2));
        $remaining = max(0.0, round($debt - $earnings, 2));

        return [
            'eligible_earnings' => $this->format($earnings),
            'outstanding_debt' => $this->format($debt),
            'payable_earnings' => $this->format($payable),
            'remaining_debt' => $this->format($remaining),
        ];
    }

    private function money(string $value): float
    {
        return round((float) $value, 2);
    }

    private function format(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
