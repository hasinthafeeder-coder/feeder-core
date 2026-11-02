<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Exceptions\ResellerSettlementException;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ResellerDebtObligation;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementDebtAllocation;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Support\MoneyNormalizer;

/**
 * Allocates outstanding reseller debt against an invoice's gross earnings.
 *
 * Rule: oldest outstanding eligible debt first, tie-break by obligation id ASC.
 * Same reseller + market + currency only — never cross LKR/MYR.
 * Persists allocation history and atomically reduces outstanding_amount.
 */
class ResellerSettlementDebtAllocator
{
    public function __construct(
        private readonly MoneyNormalizer $money,
    ) {}

    /**
     * @return array{
     *     debt_deduction_total: string,
     *     allocation_count: int
     * }
     */
    public function allocateForInvoice(
        ResellerSettlementBatch $batch,
        ResellerSettlementInvoice $invoice,
        string $grossEarnings,
    ): array {
        $remaining = $this->money->normalizeNonNegative($grossEarnings, 'gross_earnings');
        if (! $this->money->isPositive($remaining)) {
            return [
                'debt_deduction_total' => '0.00',
                'allocation_count' => 0,
            ];
        }

        $settlementDate = $batch->settlement_date?->format('Y-m-d')
            ?? (string) $batch->getRawOriginal('settlement_date');

        $debts = ResellerDebtObligation::query()
            ->where('reseller_id', $invoice->reseller_id)
            ->where('market_id', $batch->market_id)
            ->where('currency_id', $invoice->currency_id)
            ->where('outstanding_amount', '>', 0)
            ->whereDate('effective_date', '<=', $settlementDate)
            ->orderBy('effective_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $deductionTotal = '0.00';
        $sequence = 0;

        foreach ($debts as $debt) {
            if (! $this->money->isPositive($remaining)) {
                break;
            }

            /** @var ResellerDebtObligation $debt */
            $outstanding = number_format((float) $debt->outstanding_amount, 2, '.', '');
            if (! $this->money->isPositive($outstanding)) {
                continue;
            }

            // Never mix currencies even if FK ids somehow diverge from snapshots.
            if (strtoupper((string) $debt->currency_code_snapshot)
                !== strtoupper((string) $invoice->currency_code_snapshot)
            ) {
                continue;
            }

            $allocate = $this->minMoney($remaining, $outstanding);
            $after = $this->money->subtract($outstanding, $allocate);

            $sequence++;
            $order = $debt->order_id
                ? Order::query()->whereKey($debt->order_id)->first()
                : null;

            ResellerSettlementDebtAllocation::query()->create([
                'invoice_id' => $invoice->id,
                'batch_id' => $batch->id,
                'debt_obligation_id' => $debt->id,
                'debt_obligation_number_snapshot' => (string) $debt->obligation_number,
                'obligation_type_snapshot' => (string) (
                    $debt->obligation_type?->value ?? $debt->getRawOriginal('obligation_type')
                ),
                'return_reason_snapshot' => $debt->return_reason !== null
                    ? (string) ($debt->return_reason->value ?? $debt->getRawOriginal('return_reason'))
                    : null,
                'order_id' => $debt->order_id,
                'order_number_snapshot' => $order?->order_number,
                'currency_id' => $debt->currency_id,
                'currency_code_snapshot' => strtoupper((string) $debt->currency_code_snapshot),
                'allocated_amount' => $allocate,
                'debt_outstanding_before' => $outstanding,
                'debt_outstanding_after' => $after,
                'allocation_sequence' => $sequence,
            ]);

            $updated = ResellerDebtObligation::query()
                ->whereKey($debt->id)
                ->where('outstanding_amount', $outstanding)
                ->update([
                    'outstanding_amount' => $after,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                // Concurrent mutation — abort so the outer transaction rolls back
                // the allocation row written above.
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_DEBT_ALLOCATION_CONFLICT',
                    'Debt outstanding amount changed during allocation. The settlement batch was not committed.',
                    [
                        'debt_obligation_id' => $debt->id,
                        'invoice_id' => $invoice->id,
                        'batch_id' => $batch->id,
                    ],
                    409,
                );
            }

            $deductionTotal = $this->money->add($deductionTotal, $allocate);
            $remaining = $this->money->subtract($remaining, $allocate);
        }

        return [
            'debt_deduction_total' => $deductionTotal,
            'allocation_count' => $sequence,
        ];
    }

    /**
     * Reverse all debt allocations for an OPEN batch rebuild.
     */
    public function reverseAllocationsForBatch(ResellerSettlementBatch $batch): void
    {
        $allocations = ResellerSettlementDebtAllocation::query()
            ->where('batch_id', $batch->id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            /** @var ResellerSettlementDebtAllocation $allocation */
            $debt = ResellerDebtObligation::query()
                ->whereKey($allocation->debt_obligation_id)
                ->lockForUpdate()
                ->first();

            if ($debt !== null) {
                $restored = $this->money->add(
                    number_format((float) $debt->outstanding_amount, 2, '.', ''),
                    number_format((float) $allocation->allocated_amount, 2, '.', ''),
                );

                // Never silently drop debt. If the restored balance would exceed the
                // original fine, the allocation history is inconsistent and the
                // surrounding generation transaction must roll back unchanged.
                $original = number_format((float) $debt->original_amount, 2, '.', '');
                if ((float) $restored > (float) $original) {
                    throw new ResellerSettlementException(
                        'RESELLER_SETTLEMENT_DEBT_RESTORE_EXCEEDS_ORIGINAL',
                        'Reversing a debt allocation would raise outstanding debt above the original fine. The rebuild was not committed.',
                        [
                            'debt_obligation_id' => $debt->id,
                            'allocation_id' => $allocation->id,
                            'batch_id' => $batch->id,
                            'restored_outstanding' => $restored,
                            'original_amount' => $original,
                        ],
                        409,
                    );
                }

                $debt->forceFill([
                    'outstanding_amount' => $restored,
                ])->save();
            }

            $allocation->delete();
        }
    }

    private function minMoney(string $left, string $right): string
    {
        return (float) $left <= (float) $right ? $left : $right;
    }
}
