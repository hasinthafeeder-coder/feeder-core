<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Exceptions\ResellerSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerPayment;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Models\ResellerSettlementInvoiceLine;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Records and reverses reseller settlement payments against LOCKED invoices.
 *
 * Supports partial payments. Rejects overpayment. Append-only ledger with
 * idempotency_key uniqueness. Reversals insert a REVERSAL row and restore
 * invoice balances; PAYMENT rows are never mutated. Printing/PDF never calls
 * this service.
 *
 * Writer lock order: market → batch → invoice → payment records.
 */
class ResellerSettlementPaymentService
{
    public function __construct(
        private readonly MoneyNormalizer $money,
        private readonly ResellerSettlementNumberGenerator $numbers,
        private readonly FinancialDailySummaryUpdater $financialDailySummaries,
    ) {}

    public function record(
        ResellerSettlementInvoice $invoice,
        User $actor,
        mixed $amount,
        int $currencyId,
        string $idempotencyKey,
        bool $transferConfirmed,
        ?string $paymentReference = null,
    ): ResellerPayment {
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        return DB::transaction(function () use (
            $invoice,
            $actor,
            $amount,
            $currencyId,
            $idempotencyKey,
            $transferConfirmed,
            $paymentReference,
        ) {
            [, $lockedBatch, $lockedInvoice] = $this->lockMarketBatchInvoice($invoice);

            $existingByKey = ResellerPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingByKey !== null) {
                $existing = $this->resolveIdempotentPayment(
                    $existingByKey,
                    $lockedInvoice,
                    $amount,
                    $currencyId,
                    $paymentReference,
                );
                $this->financialDailySummaries->recordResellerPayment($existing);
                $this->financialDailySummaries->refreshResellerPaymentState($lockedBatch);

                return $existing;
            }

            $this->assertBatchPayable($lockedBatch);
            $this->assertInvoicePayable($lockedInvoice);
            $this->assertTransferConfirmed($transferConfirmed);

            $normalizedAmount = $this->normalizePositivePaymentAmount($amount);

            if ((int) $currencyId !== (int) $lockedInvoice->currency_id) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_PAYMENT_CURRENCY_MISMATCH',
                    'Payment currency must match the invoice currency exactly.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'invoice_currency_id' => $lockedInvoice->currency_id,
                        'requested_currency_id' => $currencyId,
                    ],
                );
            }

            ResellerPayment::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lockedInvoice->refresh();

            $outstanding = number_format((float) $lockedInvoice->outstanding_balance, 2, '.', '');
            if (! $this->money->isPositive($outstanding)) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_INVOICE_ALREADY_PAID',
                    'Invoice has no outstanding balance.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'outstanding_balance' => $outstanding,
                    ],
                    409,
                );
            }

            if ((float) $normalizedAmount > (float) $outstanding) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_PAYMENT_OVERPAYMENT',
                    'Payment amount exceeds the outstanding invoice balance.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'outstanding_balance' => $outstanding,
                        'requested_amount' => $normalizedAmount,
                    ],
                );
            }

            try {
                $payment = ResellerPayment::query()->create([
                    'payment_number' => $this->numbers->paymentNumber(
                        (int) $lockedInvoice->id,
                        ResellerPayment::ENTRY_TYPE_PAYMENT,
                    ),
                    'entry_type' => ResellerPayment::ENTRY_TYPE_PAYMENT,
                    'invoice_id' => $lockedInvoice->id,
                    'reseller_id' => $lockedInvoice->reseller_id,
                    'reseller_company_id' => $lockedInvoice->reseller_company_id,
                    'currency_id' => $lockedInvoice->currency_id,
                    'currency_code_snapshot' => $lockedInvoice->currency_code_snapshot,
                    'amount' => $normalizedAmount,
                    'payment_reference' => $this->normalizeOptionalReference($paymentReference),
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now(),
                    'reverses_payment_id' => null,
                    'reversal_reason' => null,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    $existing = ResellerPayment::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $resolved = $this->resolveIdempotentPayment(
                            $existing,
                            $lockedInvoice,
                            $amount,
                            $currencyId,
                            $paymentReference,
                        );
                        // Same healing path as the pre-create idempotent hit: upsert is
                        // safe under retries and cannot double-count contributions.
                        $this->financialDailySummaries->recordResellerPayment($resolved);
                        $this->financialDailySummaries->refreshResellerPaymentState($lockedBatch);

                        return $resolved;
                    }
                }

                throw $exception;
            }

            $offsetApplied = number_format((float) ($lockedInvoice->offset_applied_total ?? 0), 2, '.', '');
            $newAmountPaid = $this->money->add(
                number_format((float) $lockedInvoice->amount_paid, 2, '.', ''),
                $normalizedAmount,
            );
            $newOutstanding = $this->money->subtract(
                $this->money->subtract(
                    number_format((float) $lockedInvoice->net_payable, 2, '.', ''),
                    $newAmountPaid,
                ),
                $offsetApplied,
            );
            if ((float) $newOutstanding < 0) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_PAYMENT_OVERPAYMENT',
                    'Payment would leave a negative outstanding balance.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'requested_amount' => $normalizedAmount,
                    ],
                    409,
                );
            }

            $paymentStatus = ResellerSettlementInvoice::resolvePaymentStatus(
                $newAmountPaid,
                $offsetApplied,
                $newOutstanding,
            );

            $updated = ResellerSettlementInvoice::query()
                ->whereKey($lockedInvoice->id)
                ->where('outstanding_balance', $outstanding)
                ->where('instruction_status', ResellerSettlementInvoice::INSTRUCTION_STATUS_ACTIVE)
                ->update([
                    'amount_paid' => $newAmountPaid,
                    'outstanding_balance' => $newOutstanding,
                    'payment_status' => $paymentStatus,
                    'effective_payment_id' => $payment->id,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_PAYMENT_UPDATE_FAILED',
                    'Could not update invoice balances; another payment may have won the race.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'payment_id' => $payment->id,
                        'rows_updated' => $updated,
                    ],
                    409,
                );
            }

            if ($paymentStatus === ResellerSettlementInvoice::PAYMENT_STATUS_PAID) {
                ResellerSettlementInvoiceLine::query()
                    ->where('invoice_id', $lockedInvoice->id)
                    ->update([
                        'line_payment_status' => ResellerSettlementInvoiceLine::LINE_PAYMENT_STATUS_PAID,
                        'updated_at' => now(),
                    ]);
            }

            $freshPayment = $payment->fresh() ?? $payment;
            $this->financialDailySummaries->recordResellerPayment($freshPayment);
            $this->financialDailySummaries->refreshResellerPaymentState($lockedBatch);

            return $freshPayment;
        });
    }

    /**
     * Reverse an effective PAYMENT by appending a REVERSAL ledger row.
     *
     * Restores invoice amount_paid / outstanding_balance. Idempotent for
     * identical retries. Original PAYMENT row is never mutated.
     */
    public function reverse(
        ResellerPayment $payment,
        User $actor,
        string $reason,
        string $idempotencyKey,
    ): ResellerPayment {
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $reason = trim($reason);

        if ($reason === '') {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_REVERSAL_REASON_REQUIRED',
                'A non-empty reversal reason is required.',
                ['payment_id' => $payment->id],
            );
        }

        return DB::transaction(function () use ($payment, $actor, $reason, $idempotencyKey) {
            $invoice = ResellerSettlementInvoice::query()->whereKey($payment->invoice_id)->firstOrFail();
            [, $lockedBatch, $lockedInvoice] = $this->lockMarketBatchInvoice($invoice);

            $existingByKey = ResellerPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingByKey !== null) {
                $existing = $this->resolveIdempotentReversal(
                    $existingByKey,
                    $payment,
                    $lockedInvoice,
                    $reason,
                );
                $this->financialDailySummaries->recordResellerPayment($existing);
                $this->financialDailySummaries->refreshResellerPaymentState($lockedBatch);

                return $existing;
            }

            $this->assertBatchPayable($lockedBatch);

            $ledgerRows = ResellerPayment::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var ResellerPayment|null $lockedPayment */
            $lockedPayment = $ledgerRows->firstWhere('id', (int) $payment->id);
            if ($lockedPayment === null) {
                $lockedPayment = ResellerPayment::query()
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if ($lockedPayment->entry_type !== ResellerPayment::ENTRY_TYPE_PAYMENT) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_REVERSAL_NOT_A_PAYMENT',
                    'Only PAYMENT ledger rows can be reversed.',
                    [
                        'payment_id' => $lockedPayment->id,
                        'entry_type' => $lockedPayment->entry_type,
                    ],
                );
            }

            if ((int) $lockedPayment->invoice_id !== (int) $lockedInvoice->id) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_REVERSAL_INVOICE_MISMATCH',
                    'Payment does not belong to the locked invoice.',
                    [
                        'payment_id' => $lockedPayment->id,
                        'invoice_id' => $lockedInvoice->id,
                    ],
                );
            }

            $existingReversal = $ledgerRows->first(
                static fn (ResellerPayment $row): bool => $row->entry_type === ResellerPayment::ENTRY_TYPE_REVERSAL
                    && (int) $row->reverses_payment_id === (int) $lockedPayment->id
            );

            if ($existingReversal !== null) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_PAYMENT_ALREADY_REVERSED',
                    'This payment has already been reversed.',
                    [
                        'payment_id' => $lockedPayment->id,
                        'reversal_id' => $existingReversal->id,
                    ],
                    409,
                );
            }

            if ((int) $lockedInvoice->effective_payment_id !== (int) $lockedPayment->id) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_PAYMENT_NOT_EFFECTIVE',
                    'Only the currently effective payment for an invoice can be reversed.',
                    [
                        'payment_id' => $lockedPayment->id,
                        'invoice_id' => $lockedInvoice->id,
                        'effective_payment_id' => $lockedInvoice->effective_payment_id,
                    ],
                    409,
                );
            }

            try {
                $reversal = ResellerPayment::query()->create([
                    'payment_number' => $this->numbers->paymentNumber(
                        (int) $lockedInvoice->id,
                        ResellerPayment::ENTRY_TYPE_REVERSAL,
                    ),
                    'entry_type' => ResellerPayment::ENTRY_TYPE_REVERSAL,
                    'invoice_id' => $lockedInvoice->id,
                    'reseller_id' => $lockedInvoice->reseller_id,
                    'reseller_company_id' => $lockedInvoice->reseller_company_id,
                    'currency_id' => $lockedPayment->currency_id,
                    'currency_code_snapshot' => $lockedPayment->currency_code_snapshot,
                    'amount' => $lockedPayment->amount,
                    'payment_reference' => null,
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now(),
                    'reverses_payment_id' => $lockedPayment->id,
                    'reversal_reason' => $reason,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    $existing = ResellerPayment::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $resolved = $this->resolveIdempotentReversal(
                            $existing,
                            $lockedPayment,
                            $lockedInvoice,
                            $reason,
                        );
                        $this->financialDailySummaries->recordResellerPayment($resolved);
                        $this->financialDailySummaries->refreshResellerPaymentState($lockedBatch);

                        return $resolved;
                    }
                }

                throw $exception;
            }

            $lockedInvoice->refresh();
            $offsetApplied = number_format((float) ($lockedInvoice->offset_applied_total ?? 0), 2, '.', '');
            $paymentAmount = number_format((float) $lockedPayment->amount, 2, '.', '');
            $newAmountPaid = $this->money->subtract(
                number_format((float) $lockedInvoice->amount_paid, 2, '.', ''),
                $paymentAmount,
            );
            if ((float) $newAmountPaid < 0) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_REVERSAL_AMOUNT_PAID_INVALID',
                    'Reversal would leave a negative amount_paid on the invoice.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'payment_id' => $lockedPayment->id,
                    ],
                    409,
                );
            }

            $newOutstanding = $this->money->subtract(
                $this->money->subtract(
                    number_format((float) $lockedInvoice->net_payable, 2, '.', ''),
                    $newAmountPaid,
                ),
                $offsetApplied,
            );

            $paymentStatus = ResellerSettlementInvoice::resolvePaymentStatus(
                $newAmountPaid,
                $offsetApplied,
                $newOutstanding,
            );

            $previousEffectiveId = (int) $lockedInvoice->effective_payment_id;
            $priorPayment = $ledgerRows
                ->filter(function (ResellerPayment $row) use ($lockedPayment, $ledgerRows): bool {
                    if ($row->entry_type !== ResellerPayment::ENTRY_TYPE_PAYMENT) {
                        return false;
                    }

                    if ((int) $row->id === (int) $lockedPayment->id) {
                        return false;
                    }

                    $alreadyReversed = $ledgerRows->contains(
                        static function (ResellerPayment $rev) use ($row): bool {
                            return $rev->entry_type === ResellerPayment::ENTRY_TYPE_REVERSAL
                                && (int) $rev->reverses_payment_id === (int) $row->id;
                        }
                    );

                    return ! $alreadyReversed;
                })
                ->sortByDesc('id')
                ->first();

            $updated = ResellerSettlementInvoice::query()
                ->whereKey($lockedInvoice->id)
                ->where('effective_payment_id', $previousEffectiveId)
                ->update([
                    'amount_paid' => $newAmountPaid,
                    'outstanding_balance' => $newOutstanding,
                    'payment_status' => $paymentStatus,
                    'effective_payment_id' => $priorPayment?->id,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ResellerSettlementException(
                    'RESELLER_SETTLEMENT_REVERSAL_UPDATE_FAILED',
                    'Could not restore invoice balances for this reversal.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'payment_id' => $lockedPayment->id,
                        'reversal_id' => $reversal->id,
                        'rows_updated' => $updated,
                    ],
                    409,
                );
            }

            if ($paymentStatus !== ResellerSettlementInvoice::PAYMENT_STATUS_PAID) {
                ResellerSettlementInvoiceLine::query()
                    ->where('invoice_id', $lockedInvoice->id)
                    ->update([
                        'line_payment_status' => ResellerSettlementInvoiceLine::LINE_PAYMENT_STATUS_UNPAID,
                        'updated_at' => now(),
                    ]);
            }

            $freshReversal = $reversal->fresh() ?? $reversal;
            $this->financialDailySummaries->recordResellerPayment($freshReversal);
            $this->financialDailySummaries->refreshResellerPaymentState($lockedBatch);

            return $freshReversal;
        });
    }

    /**
     * @return array{0: Market, 1: ResellerSettlementBatch, 2: ResellerSettlementInvoice}
     */
    private function lockMarketBatchInvoice(ResellerSettlementInvoice $invoice): array
    {
        $invoiceId = (int) $invoice->id;
        $batchId = (int) ($invoice->batch_id
            ?? ResellerSettlementInvoice::query()->whereKey($invoiceId)->value('batch_id'));

        $batch = ResellerSettlementBatch::query()->whereKey($batchId)->firstOrFail();
        $market = Market::query()->whereKey($batch->market_id)->lockForUpdate()->firstOrFail();

        /** @var ResellerSettlementBatch $lockedBatch */
        $lockedBatch = ResellerSettlementBatch::query()
            ->whereKey($batchId)
            ->lockForUpdate()
            ->firstOrFail();

        /** @var ResellerSettlementInvoice $lockedInvoice */
        $lockedInvoice = ResellerSettlementInvoice::query()
            ->whereKey($invoiceId)
            ->lockForUpdate()
            ->firstOrFail();

        return [$market, $lockedBatch, $lockedInvoice];
    }

    private function assertBatchPayable(ResellerSettlementBatch $batch): void
    {
        if ($batch->voided_at !== null) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_BATCH_VOIDED',
                'Cannot record payment against a voided batch.',
                ['batch_id' => $batch->id],
            );
        }

        if ($batch->status !== ResellerSettlementBatch::STATUS_LOCKED) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_BATCH_NOT_LOCKED',
                'Payments can only be recorded against LOCKED reseller settlement batches.',
                [
                    'batch_id' => $batch->id,
                    'status' => $batch->status,
                ],
            );
        }
    }

    private function assertInvoicePayable(ResellerSettlementInvoice $invoice): void
    {
        if ($invoice->instruction_status !== ResellerSettlementInvoice::INSTRUCTION_STATUS_ACTIVE) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_NOT_ACTIVE',
                'Only ACTIVE invoices can receive payments.',
                [
                    'invoice_id' => $invoice->id,
                    'instruction_status' => $invoice->instruction_status,
                ],
            );
        }

        if (in_array((string) $invoice->payment_status, ResellerSettlementInvoice::finalizedPaymentStatuses(), true)
            && ! $this->money->isPositive((string) $invoice->outstanding_balance)
        ) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_INVOICE_ALREADY_PAID',
                'Invoice is already fully paid.',
                ['invoice_id' => $invoice->id],
                409,
            );
        }
    }

    private function assertTransferConfirmed(bool $transferConfirmed): void
    {
        if (! $transferConfirmed) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_PAYMENT_CONFIRMATION_REQUIRED',
                'Explicit transfer confirmation is required before recording a reseller payment.',
            );
        }
    }

    private function normalizePositivePaymentAmount(mixed $amount): string
    {
        $normalized = $this->money->normalizeNonNegative($amount, 'amount');
        if (! $this->money->isPositive($normalized)) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_PAYMENT_AMOUNT_INVALID',
                'Payment amount must be greater than zero.',
                ['amount' => $normalized],
            );
        }

        return $normalized;
    }

    private function normalizeIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 64) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_PAYMENT_IDEMPOTENCY_KEY_INVALID',
                'A valid idempotency key (1–64 characters) is required.',
            );
        }

        return $key;
    }

    private function normalizeOptionalReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $trimmed = trim($reference);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 100);
    }

    private function resolveIdempotentPayment(
        ResellerPayment $existing,
        ResellerSettlementInvoice $invoice,
        mixed $amount,
        int $currencyId,
        ?string $paymentReference,
    ): ResellerPayment {
        $normalizedAmount = $this->normalizePositivePaymentAmount($amount);
        $normalizedReference = $this->normalizeOptionalReference($paymentReference);

        $sameMaterial = (int) $existing->invoice_id === (int) $invoice->id
            && $this->money->equals((string) $existing->amount, $normalizedAmount)
            && (int) $existing->currency_id === (int) $currencyId
            && ($existing->payment_reference ?? null) === $normalizedReference
            && $existing->entry_type === ResellerPayment::ENTRY_TYPE_PAYMENT;

        if (! $sameMaterial) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_PAYMENT_IDEMPOTENCY_CONFLICT',
                'Idempotency key was reused with different payment details.',
                [
                    'idempotency_key' => $existing->idempotency_key,
                    'existing_payment_id' => $existing->id,
                ],
                409,
            );
        }

        return $existing->fresh() ?? $existing;
    }

    private function resolveIdempotentReversal(
        ResellerPayment $existing,
        ResellerPayment $originalPayment,
        ResellerSettlementInvoice $invoice,
        string $reason,
    ): ResellerPayment {
        if ($existing->entry_type !== ResellerPayment::ENTRY_TYPE_REVERSAL) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_PAYMENT_IDEMPOTENCY_CONFLICT',
                'Idempotency key is already used by a non-REVERSAL ledger entry.',
                [
                    'idempotency_key' => $existing->idempotency_key,
                    'existing_entry_type' => $existing->entry_type,
                ],
                409,
            );
        }

        $matches = (int) $existing->invoice_id === (int) $invoice->id
            && (int) $existing->reverses_payment_id === (int) $originalPayment->id
            && trim((string) $existing->reversal_reason) === $reason;

        if (! $matches) {
            throw new ResellerSettlementException(
                'RESELLER_SETTLEMENT_PAYMENT_IDEMPOTENCY_CONFLICT',
                'Idempotency key was reused with conflicting reversal data.',
                [
                    'idempotency_key' => $existing->idempotency_key,
                    'existing_payment_id' => $existing->id,
                    'payment_id' => $originalPayment->id,
                ],
                409,
            );
        }

        return $existing->fresh() ?? $existing;
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
