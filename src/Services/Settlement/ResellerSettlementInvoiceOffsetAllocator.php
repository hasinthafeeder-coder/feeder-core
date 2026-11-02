<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Exceptions\ResellerSettlementException;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Models\ResellerSettlementInvoiceOffset;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Database\QueryException;

/**
 * Settles previous unpaid reseller invoices from a new invoice's remaining amount.
 *
 * Order of use is fixed by the caller: earnings, then debt, then transfer fee,
 * then these offsets. Oldest previous invoice first (settlement date, batch id,
 * issued time, invoice id). Same reseller, market, and currency only.
 *
 * Offsets are not ResellerPayment rows. Rebuilding the source batch reverses
 * only that batch's offsets. A batch whose invoices were offset by a later
 * batch cannot be rebuilt until that later batch releases them.
 */
class ResellerSettlementInvoiceOffsetAllocator
{
    public function __construct(
        private readonly MoneyNormalizer $money,
    ) {}

    /**
     * @return array{offset_total: string, allocation_count: int}
     */
    public function allocateForInvoice(
        ResellerSettlementBatch $batch,
        ResellerSettlementInvoice $invoice,
        string $availableAmount,
    ): array {
        $remaining = $this->money->normalizeNonNegative($availableAmount, 'available_amount');
        if (! $this->money->isPositive($remaining)) {
            return [
                'offset_total' => '0.00',
                'allocation_count' => 0,
            ];
        }

        $settlementDate = $batch->settlement_date?->format('Y-m-d')
            ?? (string) $batch->getRawOriginal('settlement_date');

        $targetIds = $this->candidateTargetIds($batch, $invoice, $settlementDate);
        $offsetTotal = '0.00';
        $sequence = 0;

        foreach ($targetIds as $targetId) {
            if (! $this->money->isPositive($remaining)) {
                break;
            }

            /** @var ResellerSettlementInvoice|null $target */
            $target = ResellerSettlementInvoice::query()
                ->whereKey($targetId)
                ->lockForUpdate()
                ->first();

            if ($target === null || ! $this->targetStillEligible($batch, $invoice, $target, $settlementDate)) {
                continue;
            }

            $outstanding = number_format((float) $target->outstanding_balance, 2, '.', '');
            $offsetApplied = number_format((float) ($target->offset_applied_total ?? 0), 2, '.', '');
            $amountPaid = number_format((float) $target->amount_paid, 2, '.', '');
            $netPayable = number_format((float) $target->net_payable, 2, '.', '');
            $room = $this->money->subtract(
                $this->money->subtract($netPayable, $amountPaid),
                $offsetApplied,
            );

            if ((float) $room < 0 || ! $this->money->equals($room, $outstanding)) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_INVOICE_OFFSET_BALANCE_INCONSISTENT',
                    'Previous invoice outstanding does not match net payable, cash paid, and offsets already applied. The settlement batch was not committed.',
                    [
                        'target_invoice_id' => $target->id,
                        'source_invoice_id' => $invoice->id,
                        'outstanding_balance' => $outstanding,
                        'calculated_room' => $room,
                    ],
                    409,
                );
            }

            if (! $this->money->isPositive($outstanding)) {
                continue;
            }

            $allocate = $this->minMoney($remaining, $outstanding);
            $afterOutstanding = $this->money->subtract($outstanding, $allocate);
            $afterOffsetApplied = $this->money->add($offsetApplied, $allocate);

            if ((float) $afterOutstanding < 0 || (float) $afterOffsetApplied > (float) $room + (float) $offsetApplied) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_INVOICE_OFFSET_OVER_ALLOCATION',
                    'Previous invoice offset would exceed the invoice outstanding balance.',
                    [
                        'target_invoice_id' => $target->id,
                        'source_invoice_id' => $invoice->id,
                        'requested_amount' => $allocate,
                        'outstanding_balance' => $outstanding,
                    ],
                    409,
                );
            }

            $sequence++;
            $paymentStatus = ResellerSettlementInvoice::resolvePaymentStatus(
                $amountPaid,
                $afterOffsetApplied,
                $afterOutstanding,
            );

            try {
                ResellerSettlementInvoiceOffset::query()->create([
                    'source_invoice_id' => $invoice->id,
                    'source_batch_id' => $batch->id,
                    'target_invoice_id' => $target->id,
                    'reseller_id' => (int) $invoice->reseller_id,
                    'market_id' => (int) $batch->market_id,
                    'currency_id' => (int) $invoice->currency_id,
                    'currency_code_snapshot' => strtoupper((string) $invoice->currency_code_snapshot),
                    'allocated_amount' => $allocate,
                    'target_outstanding_before' => $outstanding,
                    'target_outstanding_after' => $afterOutstanding,
                    'target_offset_applied_before' => $offsetApplied,
                    'target_offset_applied_after' => $afterOffsetApplied,
                    'allocation_sequence' => $sequence,
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw new ResellerSettlementException(
                        'RESELLER_SETTLEMENT_INVOICE_OFFSET_DUPLICATE',
                        'This settlement batch already offset that previous invoice. The batch was not committed.',
                        [
                            'target_invoice_id' => $target->id,
                            'source_invoice_id' => $invoice->id,
                            'source_batch_id' => $batch->id,
                        ],
                        409,
                    );
                }

                throw $exception;
            }

            $updated = ResellerSettlementInvoice::query()
                ->whereKey($target->id)
                ->where('outstanding_balance', $outstanding)
                ->where('offset_applied_total', $offsetApplied)
                ->where('instruction_status', ResellerSettlementInvoice::INSTRUCTION_STATUS_ACTIVE)
                ->update([
                    'offset_applied_total' => $afterOffsetApplied,
                    'outstanding_balance' => $afterOutstanding,
                    'payment_status' => $paymentStatus,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_INVOICE_OFFSET_CONFLICT',
                    'Previous invoice balance changed during offset allocation. The settlement batch was not committed.',
                    [
                        'target_invoice_id' => $target->id,
                        'source_invoice_id' => $invoice->id,
                        'source_batch_id' => $batch->id,
                    ],
                    409,
                );
            }

            $offsetTotal = $this->money->add($offsetTotal, $allocate);
            $remaining = $this->money->subtract($remaining, $allocate);
        }

        return [
            'offset_total' => $offsetTotal,
            'allocation_count' => $sequence,
        ];
    }

    /**
     * Refuse a rebuild that would delete invoices a different batch has already offset.
     */
    public function assertBatchNotTargetedByOtherBatches(ResellerSettlementBatch $batch): void
    {
        $invoiceIds = $batch->invoices()->pluck('id');
        if ($invoiceIds->isEmpty()) {
            return;
        }

        $external = ResellerSettlementInvoiceOffset::query()
            ->whereIn('target_invoice_id', $invoiceIds)
            ->where('source_batch_id', '!=', $batch->id)
            ->lockForUpdate()
            ->exists();

        if ($external) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_BATCH_TARGETED_BY_OTHER_OFFSET',
                'Cannot rebuild an OPEN batch whose invoices were offset by another settlement batch. Rebuild that later batch first.',
                ['batch_id' => $batch->id],
                409,
            );
        }
    }

    /**
     * Restore previous-invoice balances for offsets this batch created, then delete them.
     * Cash payments and other batches' offsets are left in place.
     */
    public function reverseOffsetsForBatch(ResellerSettlementBatch $batch): void
    {
        $offsets = ResellerSettlementInvoiceOffset::query()
            ->where('source_batch_id', $batch->id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($offsets as $offset) {
            /** @var ResellerSettlementInvoiceOffset $offset */
            /** @var ResellerSettlementInvoice|null $target */
            $target = ResellerSettlementInvoice::query()
                ->whereKey($offset->target_invoice_id)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_INVOICE_OFFSET_TARGET_MISSING',
                    'Cannot reverse an invoice offset because the previous invoice no longer exists. The rebuild was not committed.',
                    [
                        'offset_id' => $offset->id,
                        'target_invoice_id' => $offset->target_invoice_id,
                        'batch_id' => $batch->id,
                    ],
                    409,
                );
            }

            $allocated = number_format((float) $offset->allocated_amount, 2, '.', '');
            $currentOffsetApplied = number_format((float) ($target->offset_applied_total ?? 0), 2, '.', '');
            $restoredOffsetApplied = $this->money->subtract($currentOffsetApplied, $allocated);

            if ((float) $restoredOffsetApplied < 0) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_INVOICE_OFFSET_RESTORE_INVALID',
                    'Reversing an invoice offset would make the applied offset negative. The rebuild was not committed.',
                    [
                        'offset_id' => $offset->id,
                        'target_invoice_id' => $target->id,
                        'batch_id' => $batch->id,
                        'offset_applied_total' => $currentOffsetApplied,
                        'allocated_amount' => $allocated,
                    ],
                    409,
                );
            }

            $amountPaid = number_format((float) $target->amount_paid, 2, '.', '');
            $netPayable = number_format((float) $target->net_payable, 2, '.', '');
            $restoredOutstanding = $this->money->subtract(
                $this->money->subtract($netPayable, $amountPaid),
                $restoredOffsetApplied,
            );

            if ((float) $restoredOutstanding < 0) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_INVOICE_OFFSET_RESTORE_INVALID',
                    'Reversing an invoice offset would make the outstanding balance negative. The rebuild was not committed.',
                    [
                        'offset_id' => $offset->id,
                        'target_invoice_id' => $target->id,
                        'batch_id' => $batch->id,
                        'restored_outstanding' => $restoredOutstanding,
                    ],
                    409,
                );
            }

            $target->forceFill([
                'offset_applied_total' => $restoredOffsetApplied,
                'outstanding_balance' => $restoredOutstanding,
                'payment_status' => ResellerSettlementInvoice::resolvePaymentStatus(
                    $amountPaid,
                    $restoredOffsetApplied,
                    $restoredOutstanding,
                ),
            ])->save();

            $offset->delete();
        }
    }

    /**
     * @return list<int>
     */
    private function candidateTargetIds(
        ResellerSettlementBatch $batch,
        ResellerSettlementInvoice $invoice,
        string $settlementDate,
    ): array {
        return ResellerSettlementInvoice::query()
            ->join(
                'reseller_settlement_batches as target_batches',
                'target_batches.id',
                '=',
                'reseller_settlement_invoices.batch_id',
            )
            ->where('reseller_settlement_invoices.reseller_id', $invoice->reseller_id)
            ->where('reseller_settlement_invoices.currency_id', $invoice->currency_id)
            ->where(
                'reseller_settlement_invoices.currency_code_snapshot',
                strtoupper((string) $invoice->currency_code_snapshot),
            )
            ->where(
                'reseller_settlement_invoices.instruction_status',
                ResellerSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
            )
            ->whereNull('reseller_settlement_invoices.voided_at')
            ->where('reseller_settlement_invoices.outstanding_balance', '>', 0)
            ->whereNotIn(
                'reseller_settlement_invoices.payment_status',
                ResellerSettlementInvoice::finalizedPaymentStatuses(),
            )
            ->where('reseller_settlement_invoices.id', '!=', $invoice->id)
            ->where('target_batches.market_id', $batch->market_id)
            ->whereNull('target_batches.voided_at')
            ->where('target_batches.id', '!=', $batch->id)
            ->where(function ($query) use ($batch, $settlementDate): void {
                $query->whereDate('target_batches.settlement_date', '<', $settlementDate)
                    ->orWhere(function ($sameDay) use ($batch, $settlementDate): void {
                        $sameDay->whereDate('target_batches.settlement_date', '=', $settlementDate)
                            ->where('target_batches.id', '<', (int) $batch->id);
                    });
            })
            ->orderBy('target_batches.settlement_date')
            ->orderBy('target_batches.id')
            ->orderBy('reseller_settlement_invoices.issued_at')
            ->orderBy('reseller_settlement_invoices.id')
            ->pluck('reseller_settlement_invoices.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function targetStillEligible(
        ResellerSettlementBatch $batch,
        ResellerSettlementInvoice $source,
        ResellerSettlementInvoice $target,
        string $settlementDate,
    ): bool {
        if ((int) $target->reseller_id !== (int) $source->reseller_id) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_OFFSET_RESELLER_MISMATCH',
                'Previous invoice offset cannot cross resellers.',
                [
                    'source_invoice_id' => $source->id,
                    'target_invoice_id' => $target->id,
                ],
                409,
            );
        }

        if ((int) $target->currency_id !== (int) $source->currency_id
            || strtoupper((string) $target->currency_code_snapshot) !== strtoupper((string) $source->currency_code_snapshot)
        ) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_OFFSET_CURRENCY_MISMATCH',
                'Previous invoice offset cannot mix currencies.',
                [
                    'source_invoice_id' => $source->id,
                    'target_invoice_id' => $target->id,
                    'source_currency' => $source->currency_code_snapshot,
                    'target_currency' => $target->currency_code_snapshot,
                ],
                409,
            );
        }

        if ((int) $target->id === (int) $source->id || (int) $target->batch_id === (int) $batch->id) {
            return false;
        }

        if ($target->instruction_status !== ResellerSettlementInvoice::INSTRUCTION_STATUS_ACTIVE
            || $target->voided_at !== null
            || in_array((string) $target->payment_status, ResellerSettlementInvoice::finalizedPaymentStatuses(), true)
        ) {
            return false;
        }

        $targetBatch = ResellerSettlementBatch::query()
            ->whereKey($target->batch_id)
            ->lockForUpdate()
            ->first();

        if ($targetBatch === null
            || $targetBatch->voided_at !== null
            || (int) $targetBatch->market_id !== (int) $batch->market_id
        ) {
            if ($targetBatch !== null && (int) $targetBatch->market_id !== (int) $batch->market_id) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_INVOICE_OFFSET_MARKET_MISMATCH',
                    'Previous invoice offset cannot cross markets.',
                    [
                        'source_batch_id' => $batch->id,
                        'target_batch_id' => $targetBatch->id,
                    ],
                    409,
                );
            }

            return false;
        }

        $targetDate = $targetBatch->settlement_date?->format('Y-m-d')
            ?? (string) $targetBatch->getRawOriginal('settlement_date');

        if ($targetDate > $settlementDate) {
            return false;
        }

        if ($targetDate === $settlementDate && (int) $targetBatch->id >= (int) $batch->id) {
            return false;
        }

        return $this->money->isPositive(number_format((float) $target->outstanding_balance, 2, '.', ''));
    }

    private function minMoney(string $left, string $right): string
    {
        return (float) $left <= (float) $right ? $left : $right;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $driverCode === 1062
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
