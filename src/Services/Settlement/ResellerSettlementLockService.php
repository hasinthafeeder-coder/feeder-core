<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Exceptions\ResellerSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Models\User;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Locks an OPEN reseller settlement batch after revalidating invoice totals.
 *
 * Minus Report batches are membership-only via deduction lines (no invoices).
 * Writer lock order: market → batch.
 */
class ResellerSettlementLockService
{
    public function __construct(
        private readonly MoneyNormalizer $money,
    ) {}

    public function lock(ResellerSettlementBatch $batch, User $actor): ResellerSettlementBatch
    {
        return DB::transaction(function () use ($batch, $actor) {
            $marketId = (int) ($batch->market_id
                ?? ResellerSettlementBatch::query()->whereKey($batch->id)->value('market_id'));

            Market::query()->whereKey($marketId)->lockForUpdate()->firstOrFail();

            /** @var ResellerSettlementBatch $locked */
            $locked = ResellerSettlementBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->voided_at !== null) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_BATCH_VOIDED',
                    'A voided reseller settlement batch cannot be locked.',
                    ['batch_id' => $locked->id],
                );
            }

            if ($locked->status === ResellerSettlementBatch::STATUS_LOCKED) {
                return $locked->fresh($this->lockRelations());
            }

            if ($locked->status !== ResellerSettlementBatch::STATUS_OPEN) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_BATCH_NOT_OPEN',
                    'Only OPEN reseller settlement batches can be locked.',
                    [
                        'batch_id' => $locked->id,
                        'status' => $locked->status,
                    ],
                );
            }

            $locked->load($this->lockRelations());

            if ($this->batchHasNoLockableMembership($locked)) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_BATCH_EMPTY',
                    'Cannot lock this reseller payment batch because it has no invoices, review items, or Minus Report deduction lines. Leave it unlocked until eligible content is generated.',
                    [
                        'batch_id' => $locked->id,
                        'report_type' => $locked->report_type,
                        'invoice_count' => $locked->invoices->count(),
                        'review_item_count' => $locked->reviewItems->count(),
                        'deduction_line_count' => $this->deductionLineCount($locked),
                    ],
                );
            }

            foreach ($locked->invoices as $invoice) {
                $this->revalidateInvoice($invoice);
            }

            $locked->forceFill([
                'status' => ResellerSettlementBatch::STATUS_LOCKED,
                'locked_by' => $actor->id,
                'locked_at' => now(),
            ])->save();

            return $locked->fresh($this->lockRelations());
        });
    }

    /**
     * @return list<string>
     */
    private function lockRelations(): array
    {
        $relations = [
            'invoices.earningLines',
            'invoices.debtAllocations',
            'invoices.offsetsIssued',
            'reviewItems',
        ];

        if (Schema::hasTable('reseller_settlement_deduction_lines')) {
            $relations[] = 'deductionLines';
        }

        return $relations;
    }

    private function batchHasNoLockableMembership(ResellerSettlementBatch $batch): bool
    {
        return $batch->invoices->isEmpty()
            && $batch->reviewItems->isEmpty()
            && $this->deductionLineCount($batch) === 0;
    }

    private function deductionLineCount(ResellerSettlementBatch $batch): int
    {
        if (! Schema::hasTable('reseller_settlement_deduction_lines')) {
            return 0;
        }

        if ($batch->relationLoaded('deductionLines')) {
            return $batch->deductionLines->count();
        }

        return $batch->deductionLines()->count();
    }

    private function revalidateInvoice(ResellerSettlementInvoice $invoice): void
    {
        if ($invoice->instruction_status === ResellerSettlementInvoice::INSTRUCTION_STATUS_VOIDED) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_VOIDED',
                'Cannot lock a batch that contains a voided invoice.',
                ['invoice_id' => $invoice->id],
            );
        }

        $lines = $invoice->earningLines;
        if ($lines->isEmpty()) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_EMPTY',
                'Cannot lock a batch that contains an invoice with no earning lines.',
                ['invoice_id' => $invoice->id],
            );
        }

        $gross = '0.00';
        foreach ($lines as $line) {
            $gross = $this->money->add($gross, (string) $line->gross_reseller_profit);
        }

        if (! $this->money->equals($gross, (string) $invoice->gross_earnings_total)) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_GROSS_MISMATCH',
                'Invoice gross earnings total does not match earning lines.',
                [
                    'invoice_id' => $invoice->id,
                    'invoice_gross' => (string) $invoice->gross_earnings_total,
                    'lines_gross' => $gross,
                ],
            );
        }

        $debtFromAllocations = '0.00';
        foreach ($invoice->debtAllocations as $allocation) {
            $debtFromAllocations = $this->money->add(
                $debtFromAllocations,
                (string) $allocation->allocated_amount,
            );
        }

        if (! $this->money->equals($debtFromAllocations, (string) $invoice->debt_deduction_total)) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_DEBT_MISMATCH',
                'Invoice debt deduction total does not match allocation history.',
                [
                    'invoice_id' => $invoice->id,
                    'invoice_debt' => (string) $invoice->debt_deduction_total,
                    'allocations_debt' => $debtFromAllocations,
                ],
            );
        }

        $transferFee = '0.00';
        if ($invoice->transfer_fee_status === ResellerSettlementInvoice::TRANSFER_FEE_STATUS_APPLIED) {
            $transferFee = number_format((float) $invoice->transfer_fee_amount, 2, '.', '');
        }

        $previousOffset = '0.00';
        foreach ($invoice->offsetsIssued as $offset) {
            $previousOffset = $this->money->add($previousOffset, (string) $offset->allocated_amount);
        }

        if (! $this->money->equals($previousOffset, number_format((float) ($invoice->previous_invoice_offset_total ?? 0), 2, '.', ''))) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_OFFSET_MISMATCH',
                'Invoice previous-invoice offset total does not match offset history.',
                [
                    'invoice_id' => $invoice->id,
                    'invoice_offset' => (string) ($invoice->previous_invoice_offset_total ?? '0.00'),
                    'allocations_offset' => $previousOffset,
                ],
            );
        }

        $expectedNet = $this->money->subtract($gross, $debtFromAllocations);
        if ((float) $expectedNet < 0) {
            $expectedNet = '0.00';
        }
        $expectedNet = $this->money->subtract($expectedNet, $transferFee);
        if ((float) $expectedNet < 0) {
            $expectedNet = '0.00';
        }
        $expectedNet = $this->money->subtract($expectedNet, $previousOffset);
        if ((float) $expectedNet < 0) {
            $expectedNet = '0.00';
        }

        if (! $this->money->equals($expectedNet, (string) $invoice->net_payable)) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_NET_MISMATCH',
                'Invoice net payable does not match gross minus debt, transfer fee, and previous invoice offsets.',
                [
                    'invoice_id' => $invoice->id,
                    'invoice_net' => (string) $invoice->net_payable,
                    'expected_net' => $expectedNet,
                    'transfer_fee_amount' => $transferFee,
                    'transfer_fee_status' => (string) $invoice->transfer_fee_status,
                    'previous_invoice_offset_total' => $previousOffset,
                ],
            );
        }
    }
}
