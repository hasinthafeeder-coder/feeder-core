<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\SupplierSettlementReturnedReference;
use Feeder\Core\Models\User;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Locks an OPEN settlement batch after revalidating eligibility and totals.
 *
 * Settlement writer lock order: market → batch → (revalidation reads) → dependents.
 */
class SupplierSettlementLockService
{
    public function __construct(
        private readonly SupplierSettlementPayableEligibilityEvaluator $eligibility,
        private readonly MoneyNormalizer $money,
    ) {}

    public function lock(SupplierSettlementBatch $batch, User $actor): SupplierSettlementBatch
    {
        return DB::transaction(function () use ($batch, $actor) {
            $marketId = (int) ($batch->market_id
                ?? SupplierSettlementBatch::query()->whereKey($batch->id)->value('market_id'));

            $market = Market::query()->whereKey($marketId)->lockForUpdate()->firstOrFail();
            $market->loadMissing('currency');

            /** @var SupplierSettlementBatch $locked */
            $locked = SupplierSettlementBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->market_id !== (int) $market->id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_MARKET_MISMATCH',
                    'Settlement batch market changed during lock acquisition.',
                    ['batch_id' => $locked->id],
                );
            }

            if ($locked->voided_at !== null) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_VOIDED',
                    'A voided settlement batch cannot be locked.',
                    ['batch_id' => $locked->id],
                );
            }

            if ($locked->status === SupplierSettlementBatch::STATUS_LOCKED) {
                return $locked->fresh(['invoices.payableLines', 'returnedReferences']);
            }

            if ($locked->status !== SupplierSettlementBatch::STATUS_OPEN) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_NOT_OPEN',
                    'Only OPEN settlement batches can be locked.',
                    [
                        'batch_id' => $locked->id,
                        'status' => $locked->status,
                    ],
                );
            }

            $locked->load(['invoices.payableLines', 'returnedReferences']);

            if ($locked->invoices->isEmpty() && $locked->returnedReferences->isEmpty()) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_EMPTY',
                    'Cannot lock a settlement batch with no invoices or returned references.',
                    ['batch_id' => $locked->id],
                );
            }

            foreach ($locked->invoices as $invoice) {
                $this->revalidateInvoice($locked, $market, $invoice);
            }

            foreach ($locked->returnedReferences as $reference) {
                $this->revalidateReturnedReference($locked, $market, $reference);
            }

            $locked->forceFill([
                'status' => SupplierSettlementBatch::STATUS_LOCKED,
                'locked_by' => $actor->id,
                'locked_at' => now(),
            ])->save();

            return $locked->fresh(['invoices.payableLines', 'returnedReferences']);
        });
    }

    private function revalidateInvoice(
        SupplierSettlementBatch $batch,
        Market $market,
        SupplierSettlementInvoice $invoice,
    ): void {
        if ($invoice->instruction_status === SupplierSettlementInvoice::INSTRUCTION_STATUS_VOIDED) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_VOIDED',
                'Cannot lock a batch that contains a voided invoice.',
                ['invoice_id' => $invoice->id],
            );
        }

        $lines = $invoice->payableLines;
        if ($lines->isEmpty()) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_EMPTY',
                'Cannot lock a batch that contains an invoice with no payable lines.',
                ['invoice_id' => $invoice->id],
            );
        }

        $sum = '0.00';
        foreach ($lines as $line) {
            if ((int) $line->currency_id !== (int) $invoice->currency_id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_INVOICE_CURRENCY_MISMATCH',
                    'Invoice line currency does not match its invoice currency.',
                    ['invoice_id' => $invoice->id, 'invoice_line_id' => $line->id],
                );
            }

            if ((int) $invoice->currency_id !== (int) $market->currency_id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_MARKET_CURRENCY_MISMATCH',
                    'Invoice currency does not match the batch market currency.',
                    ['invoice_id' => $invoice->id],
                );
            }

            $this->eligibility->assertLineStillPayable($batch, $market, $line);

            $expectedLine = $this->money->multiply((string) $line->unit_cost, (int) $line->quantity);
            if (! $this->money->equals((string) $line->line_payable, $expectedLine)) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_LINE_TOTAL_MISMATCH',
                    'Invoice line payable does not equal unit cost × quantity.',
                    ['invoice_line_id' => $line->id],
                );
            }

            if (! $this->money->isPositive((string) $line->line_payable)) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_LINE_NOT_POSITIVE',
                    'Invoice lines must have a positive payable amount.',
                    ['invoice_line_id' => $line->id],
                );
            }

            $sum = $this->money->add($sum, (string) $line->line_payable);
        }

        if (! $this->money->isPositive($sum)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_NOT_POSITIVE',
                'Invoices must have a positive payable total.',
                ['invoice_id' => $invoice->id],
            );
        }

        if (! $this->money->equals((string) $invoice->payable_total, $sum)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_TOTAL_MISMATCH',
                'Invoice payable total does not equal the sum of its lines.',
                [
                    'invoice_id' => $invoice->id,
                    'payable_total' => (string) $invoice->payable_total,
                    'lines_total' => $sum,
                ],
            );
        }

        $this->assertTransferFeeNetIntegrity($invoice);

        if ($invoice->instruction_status !== SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE) {
            $invoice->forceFill([
                'instruction_status' => SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
            ])->save();
        }
    }

    private function assertTransferFeeNetIntegrity(SupplierSettlementInvoice $invoice): void
    {
        $feeAmount = (string) ($invoice->transfer_fee_amount ?? '0.00');
        $status = (string) ($invoice->transfer_fee_status
            ?? SupplierSettlementInvoice::TRANSFER_FEE_STATUS_NOT_CONFIGURED);
        $gross = (string) $invoice->payable_total;
        $net = (string) ($invoice->net_payable ?? $gross);

        if ($status === SupplierSettlementInvoice::TRANSFER_FEE_STATUS_NOT_CONFIGURED) {
            if (! $this->money->equals($feeAmount, '0.00')
                || ! $this->money->equals($net, $gross)) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_INVOICE_TRANSFER_FEE_MISMATCH',
                    'Unconfigured transfer fee must leave net payable equal to gross payable.',
                    [
                        'invoice_id' => $invoice->id,
                        'transfer_fee_status' => $status,
                        'transfer_fee_amount' => $feeAmount,
                        'payable_total' => $gross,
                        'net_payable' => $net,
                    ],
                );
            }

            return;
        }

        if ($status !== SupplierSettlementInvoice::TRANSFER_FEE_STATUS_APPLIED) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_TRANSFER_FEE_STATUS_INVALID',
                'Invoice transfer fee status is invalid.',
                [
                    'invoice_id' => $invoice->id,
                    'transfer_fee_status' => $status,
                ],
            );
        }

        $expectedNet = $this->money->subtract($gross, $feeAmount);
        if ((float) $expectedNet < 0) {
            $expectedNet = '0.00';
        }

        if (! $this->money->equals($net, $expectedNet)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_TRANSFER_FEE_MISMATCH',
                'Invoice net payable does not equal gross payable minus transfer fee.',
                [
                    'invoice_id' => $invoice->id,
                    'payable_total' => $gross,
                    'transfer_fee_amount' => $feeAmount,
                    'net_payable' => $net,
                    'expected_net_payable' => $expectedNet,
                ],
            );
        }
    }

    private function revalidateReturnedReference(
        SupplierSettlementBatch $batch,
        Market $market,
        SupplierSettlementReturnedReference $reference,
    ): void {
        if (! $this->money->equals((string) $reference->display_payable, '0.00')) {
            throw new SupplierSettlementException(
                'SETTLEMENT_RETURN_DISPLAY_NONZERO',
                'Returned references must keep a zero display payable.',
                ['returned_reference_id' => $reference->id],
            );
        }

        if ((int) $reference->currency_id !== (int) $market->currency_id) {
            throw new SupplierSettlementException(
                'SETTLEMENT_RETURN_CURRENCY_MISMATCH',
                'Returned reference currency does not match the batch market currency.',
                ['returned_reference_id' => $reference->id],
            );
        }

        $settlementDate = $batch->settlement_date?->toDateString();
        $returnDate = $reference->returned_at_business_date?->toDateString();

        if ($settlementDate === null || $returnDate === null || $returnDate !== $settlementDate) {
            throw new SupplierSettlementException(
                'SETTLEMENT_RETURN_DATE_MISMATCH',
                'Returned reference business date does not belong to this batch.',
                ['returned_reference_id' => $reference->id],
            );
        }

        if ($reference->source === SupplierSettlementReturnedReference::SOURCE_PRE_LOCK
            && $this->eligibility->hasInvoiceLine((int) $reference->order_item_id)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_RETURN_PAYABLE_CONFLICT',
                'A pre-lock returned reference cannot coexist with a payable invoice line.',
                [
                    'returned_reference_id' => $reference->id,
                    'order_item_id' => $reference->order_item_id,
                ],
            );
        }

        if ($reference->source === SupplierSettlementReturnedReference::SOURCE_POST_LOCK_CATCH_UP
            && $reference->prior_payable_invoice_id === null) {
            throw new SupplierSettlementException(
                'SETTLEMENT_RETURN_PRIOR_INVOICE_MISSING',
                'Post-lock returned references require prior_payable_invoice_id.',
                ['returned_reference_id' => $reference->id],
            );
        }
    }
}
