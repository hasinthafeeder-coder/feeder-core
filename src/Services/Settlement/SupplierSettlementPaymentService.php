<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierPayment;
use Feeder\Core\Models\SupplierSettlementBankExportLine;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\SupplierSettlementInvoiceLine;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Services\Notification\FinancialNotificationService;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records and reverses supplier settlement payments against LOCKED invoices.
 *
 * Append-only ledger: PAYMENT rows are never mutated for business state.
 * Reversals insert a new REVERSAL row and clear the invoice effective payment.
 *
 * Settlement writer lock order: market → batch → invoice → payment records.
 *
 * A READY bank export is a payment instruction only — never proof of payment.
 * Recording requires an explicit bank-transfer confirmation from the actor.
 */
class SupplierSettlementPaymentService
{
    public function __construct(
        private readonly MoneyNormalizer $money,
        private readonly SupplierSettlementNumberGenerator $numbers,
        private readonly FinancialDailySummaryUpdater $financialDailySummaries,
        private readonly FinancialNotificationService $financialNotifications,
    ) {}

    /**
     * Record a full payment against one LOCKED, unpaid invoice.
     *
     * Idempotent for identical (idempotency_key + material payment fields) retries.
     */
    public function record(
        SupplierSettlementInvoice $invoice,
        User $actor,
        mixed $amount,
        int $currencyId,
        string $idempotencyKey,
        bool $bankTransferConfirmed,
        ?string $bankReference = null,
        ?int $bankExportLineId = null,
    ): SupplierPayment {
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        $payment = DB::transaction(function () use (
            $invoice,
            $actor,
            $amount,
            $currencyId,
            $idempotencyKey,
            $bankTransferConfirmed,
            $bankReference,
            $bankExportLineId,
        ) {
            [, $lockedBatch, $lockedInvoice] = $this->lockMarketBatchInvoice($invoice);

            $existingByKey = SupplierPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingByKey !== null) {
                $existing = $this->resolveIdempotentPayment(
                    $existingByKey,
                    $lockedInvoice,
                    $amount,
                    $currencyId,
                    $bankExportLineId,
                    $bankReference,
                );
                $this->financialDailySummaries->recordSupplierPayment($existing);
                $this->financialDailySummaries->refreshSupplierPaymentState($lockedBatch);

                return $existing;
            }

            $this->assertBatchPayable($lockedBatch);
            $this->assertInvoicePayable($lockedInvoice, $lockedBatch);
            $this->assertBankTransferConfirmed($bankTransferConfirmed);

            $normalizedAmount = $this->normalizePositivePaymentAmount($amount);
            $netPayable = (string) ($lockedInvoice->net_payable ?? $lockedInvoice->payable_total);
            if (! $this->money->equals($normalizedAmount, $netPayable)) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_PAYMENT_AMOUNT_MISMATCH',
                    'Payment amount must equal the full outstanding invoice net payable.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'payable_total' => (string) $lockedInvoice->payable_total,
                        'net_payable' => $netPayable,
                        'transfer_fee_amount' => (string) ($lockedInvoice->transfer_fee_amount ?? '0.00'),
                        'requested_amount' => $normalizedAmount,
                    ],
                );
            }

            if ((int) $currencyId !== (int) $lockedInvoice->currency_id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_PAYMENT_CURRENCY_MISMATCH',
                    'Payment currency must match the invoice currency exactly.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'invoice_currency_id' => $lockedInvoice->currency_id,
                        'requested_currency_id' => $currencyId,
                    ],
                );
            }

            SupplierPayment::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedInvoice->effective_payment_id !== null
                || $lockedInvoice->payment_status === SupplierSettlementInvoice::PAYMENT_STATUS_PAID) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_INVOICE_ALREADY_PAID',
                    'Invoice already has an effective payment.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'effective_payment_id' => $lockedInvoice->effective_payment_id,
                    ],
                    409,
                );
            }

            $resolvedExportLineId = $this->resolveOptionalBankExportLineId(
                $lockedInvoice,
                $bankExportLineId,
            );

            try {
                $payment = SupplierPayment::query()->create([
                    'payment_number' => $this->numbers->paymentNumber(
                        (int) $lockedInvoice->id,
                        SupplierPayment::ENTRY_TYPE_PAYMENT,
                    ),
                    'entry_type' => SupplierPayment::ENTRY_TYPE_PAYMENT,
                    'invoice_id' => $lockedInvoice->id,
                    'bank_export_line_id' => $resolvedExportLineId,
                    'supplier_company_id' => $lockedInvoice->supplier_company_id,
                    'currency_id' => $lockedInvoice->currency_id,
                    'currency_code_snapshot' => $lockedInvoice->currency_code_snapshot,
                    'amount' => $normalizedAmount,
                    'bank_reference' => $this->normalizeOptionalBankReference($bankReference),
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now(),
                    'reverses_payment_id' => null,
                    'reversal_reason' => null,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    $existing = SupplierPayment::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $resolved = $this->resolveIdempotentPayment(
                            $existing,
                            $lockedInvoice,
                            $amount,
                            $currencyId,
                            $bankExportLineId,
                            $bankReference,
                        );
                        // Same healing path as the pre-create idempotent hit: upsert is
                        // safe under retries and cannot double-count contributions.
                        $this->financialDailySummaries->recordSupplierPayment($resolved);
                        $this->financialDailySummaries->refreshSupplierPaymentState($lockedBatch);

                        return $resolved;
                    }
                }

                throw $exception;
            }

            $updated = SupplierSettlementInvoice::query()
                ->whereKey($lockedInvoice->id)
                ->whereNull('effective_payment_id')
                ->where('payment_status', SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID)
                ->update([
                    'effective_payment_id' => $payment->id,
                    'payment_status' => SupplierSettlementInvoice::PAYMENT_STATUS_PAID,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_PAYMENT_EFFECTIVE_UPDATE_FAILED',
                    'Could not set the invoice effective payment; another payment may have won the race.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'payment_id' => $payment->id,
                        'rows_updated' => $updated,
                    ],
                    409,
                );
            }

            $this->syncInvoiceLinePaymentStatus(
                $lockedInvoice,
                SupplierSettlementInvoiceLine::LINE_PAYMENT_STATUS_PAID,
            );

            $freshPayment = $payment->fresh() ?? $payment;
            $this->financialDailySummaries->recordSupplierPayment($freshPayment);
            $this->financialDailySummaries->refreshSupplierPaymentState($lockedBatch);

            return $freshPayment;
        });

        $freshInvoice = SupplierSettlementInvoice::query()->find($payment->invoice_id);
        if ($freshInvoice !== null) {
            $this->financialNotifications->afterCommit(function () use ($actor, $freshInvoice): void {
                $this->financialNotifications->notifySupplierSettlementCompleted($actor, $freshInvoice);
            });
        }

        return $payment;
    }

    /**
     * Reverse an effective PAYMENT by appending a REVERSAL ledger row.
     *
     * The original PAYMENT row is never mutated. Idempotent for identical retries.
     */
    public function reverse(
        SupplierPayment $payment,
        User $actor,
        string $reason,
        string $idempotencyKey,
    ): SupplierPayment {
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $reason = trim($reason);

        if ($reason === '') {
            throw new SupplierSettlementException(
                'SETTLEMENT_REVERSAL_REASON_REQUIRED',
                'A non-empty reversal reason is required.',
                ['payment_id' => $payment->id],
            );
        }

        return DB::transaction(function () use ($payment, $actor, $reason, $idempotencyKey) {
            $invoice = SupplierSettlementInvoice::query()->whereKey($payment->invoice_id)->firstOrFail();
            [, $lockedBatch, $lockedInvoice] = $this->lockMarketBatchInvoice($invoice);

            $existingByKey = SupplierPayment::query()
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
                $this->financialDailySummaries->recordSupplierPayment($existing);
                $this->financialDailySummaries->refreshSupplierPaymentState($lockedBatch);

                return $existing;
            }

            $this->assertBatchPayable($lockedBatch);

            $ledgerRows = SupplierPayment::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var SupplierPayment|null $lockedPayment */
            $lockedPayment = $ledgerRows->firstWhere('id', (int) $payment->id);
            if ($lockedPayment === null) {
                $lockedPayment = SupplierPayment::query()
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if ($lockedPayment->entry_type !== SupplierPayment::ENTRY_TYPE_PAYMENT) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_REVERSAL_NOT_A_PAYMENT',
                    'Only PAYMENT ledger rows can be reversed.',
                    [
                        'payment_id' => $lockedPayment->id,
                        'entry_type' => $lockedPayment->entry_type,
                    ],
                );
            }

            if ((int) $lockedPayment->invoice_id !== (int) $lockedInvoice->id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_REVERSAL_INVOICE_MISMATCH',
                    'Payment does not belong to the locked invoice.',
                    [
                        'payment_id' => $lockedPayment->id,
                        'invoice_id' => $lockedInvoice->id,
                    ],
                );
            }

            $existingReversal = $ledgerRows->first(
                static fn (SupplierPayment $row): bool => $row->entry_type === SupplierPayment::ENTRY_TYPE_REVERSAL
                    && (int) $row->reverses_payment_id === (int) $lockedPayment->id
            );

            if ($existingReversal !== null) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_PAYMENT_ALREADY_REVERSED',
                    'This payment has already been reversed.',
                    [
                        'payment_id' => $lockedPayment->id,
                        'reversal_id' => $existingReversal->id,
                    ],
                    409,
                );
            }

            if ((int) $lockedInvoice->effective_payment_id !== (int) $lockedPayment->id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_PAYMENT_NOT_EFFECTIVE',
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
                $reversal = SupplierPayment::query()->create([
                    'payment_number' => $this->numbers->paymentNumber(
                        (int) $lockedInvoice->id,
                        SupplierPayment::ENTRY_TYPE_REVERSAL,
                    ),
                    'entry_type' => SupplierPayment::ENTRY_TYPE_REVERSAL,
                    'invoice_id' => $lockedInvoice->id,
                    'bank_export_line_id' => null,
                    'supplier_company_id' => $lockedInvoice->supplier_company_id,
                    'currency_id' => $lockedPayment->currency_id,
                    'currency_code_snapshot' => $lockedPayment->currency_code_snapshot,
                    'amount' => $lockedPayment->amount,
                    'bank_reference' => null,
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now(),
                    'reverses_payment_id' => $lockedPayment->id,
                    'reversal_reason' => $reason,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    $existing = SupplierPayment::query()
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
                        $this->financialDailySummaries->recordSupplierPayment($resolved);
                        $this->financialDailySummaries->refreshSupplierPaymentState($lockedBatch);

                        return $resolved;
                    }

                    $raceReversal = SupplierPayment::query()
                        ->where('reverses_payment_id', $lockedPayment->id)
                        ->lockForUpdate()
                        ->first();

                    if ($raceReversal !== null) {
                        throw new SupplierSettlementException(
                            'SETTLEMENT_PAYMENT_ALREADY_REVERSED',
                            'This payment has already been reversed.',
                            [
                                'payment_id' => $lockedPayment->id,
                                'reversal_id' => $raceReversal->id,
                            ],
                            409,
                        );
                    }
                }

                throw $exception;
            }

            // Original PAYMENT row remains unchanged (append-only ledger).
            $updated = SupplierSettlementInvoice::query()
                ->whereKey($lockedInvoice->id)
                ->where('effective_payment_id', $lockedPayment->id)
                ->update([
                    'effective_payment_id' => null,
                    'payment_status' => SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_REVERSAL_EFFECTIVE_CLEAR_FAILED',
                    'Could not clear the invoice effective payment for this reversal.',
                    [
                        'invoice_id' => $lockedInvoice->id,
                        'payment_id' => $lockedPayment->id,
                        'reversal_id' => $reversal->id,
                        'rows_updated' => $updated,
                    ],
                    409,
                );
            }

            $this->syncInvoiceLinePaymentStatus(
                $lockedInvoice,
                SupplierSettlementInvoiceLine::LINE_PAYMENT_STATUS_UNPAID,
            );

            $freshReversal = $reversal->fresh() ?? $reversal;
            $this->financialDailySummaries->recordSupplierPayment($freshReversal);
            $this->financialDailySummaries->refreshSupplierPaymentState($lockedBatch);

            return $freshReversal;
        });
    }

    /**
     * Keep payable-line payment status aligned with the invoice effective payment.
     * Full-invoice payment only — lines are never paid independently.
     */
    private function syncInvoiceLinePaymentStatus(
        SupplierSettlementInvoice $invoice,
        string $linePaymentStatus,
    ): void {
        SupplierSettlementInvoiceLine::query()
            ->where('invoice_id', $invoice->id)
            ->update([
                'line_payment_status' => $linePaymentStatus,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{0: Market, 1: SupplierSettlementBatch, 2: SupplierSettlementInvoice}
     */
    private function lockMarketBatchInvoice(SupplierSettlementInvoice $invoice): array
    {
        $batchId = (int) ($invoice->batch_id
            ?? SupplierSettlementInvoice::query()->whereKey($invoice->id)->value('batch_id'));

        $marketId = (int) SupplierSettlementBatch::query()->whereKey($batchId)->value('market_id');

        $market = Market::query()->whereKey($marketId)->lockForUpdate()->firstOrFail();

        /** @var SupplierSettlementBatch $lockedBatch */
        $lockedBatch = SupplierSettlementBatch::query()
            ->whereKey($batchId)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $lockedBatch->market_id !== (int) $market->id) {
            throw new SupplierSettlementException(
                'SETTLEMENT_BATCH_MARKET_MISMATCH',
                'Settlement batch market changed during payment locking.',
                ['batch_id' => $lockedBatch->id],
            );
        }

        /** @var SupplierSettlementInvoice $lockedInvoice */
        $lockedInvoice = SupplierSettlementInvoice::query()
            ->whereKey($invoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $lockedInvoice->batch_id !== (int) $lockedBatch->id) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_BATCH_MISMATCH',
                'Invoice does not belong to the locked settlement batch.',
                [
                    'invoice_id' => $lockedInvoice->id,
                    'batch_id' => $lockedBatch->id,
                ],
            );
        }

        return [$market, $lockedBatch, $lockedInvoice];
    }

    private function assertBatchPayable(SupplierSettlementBatch $batch): void
    {
        if ($batch->voided_at !== null) {
            throw new SupplierSettlementException(
                'SETTLEMENT_BATCH_VOIDED',
                'A voided settlement batch cannot accept payment ledger changes.',
                ['batch_id' => $batch->id],
            );
        }

        if ($batch->status !== SupplierSettlementBatch::STATUS_LOCKED) {
            throw new SupplierSettlementException(
                'SETTLEMENT_BATCH_NOT_LOCKED',
                'Only LOCKED settlement batches may have payments recorded or reversed.',
                [
                    'batch_id' => $batch->id,
                    'status' => $batch->status,
                ],
            );
        }
    }

    private function assertInvoicePayable(
        SupplierSettlementInvoice $invoice,
        SupplierSettlementBatch $batch,
    ): void {
        if ($invoice->instruction_status === SupplierSettlementInvoice::INSTRUCTION_STATUS_VOIDED) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_VOIDED',
                'A voided invoice cannot receive a payment.',
                ['invoice_id' => $invoice->id],
            );
        }

        if ($invoice->instruction_status !== SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_NOT_PAYABLE',
                'Only ACTIVE invoices from LOCKED batches may receive payments.',
                [
                    'invoice_id' => $invoice->id,
                    'instruction_status' => $invoice->instruction_status,
                ],
            );
        }

        $netPayable = (string) ($invoice->net_payable ?? $invoice->payable_total);
        if (! $this->money->isPositive($netPayable)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_NOT_POSITIVE',
                'Invoice net payable must be positive to record a payment.',
                [
                    'invoice_id' => $invoice->id,
                    'payable_total' => (string) $invoice->payable_total,
                    'net_payable' => $netPayable,
                ],
            );
        }

        if ((int) $invoice->batch_id !== (int) $batch->id) {
            throw new SupplierSettlementException(
                'SETTLEMENT_INVOICE_BATCH_MISMATCH',
                'Invoice does not belong to the locked settlement batch.',
                [
                    'invoice_id' => $invoice->id,
                    'batch_id' => $batch->id,
                ],
            );
        }
    }

    private function assertBankTransferConfirmed(bool $bankTransferConfirmed): void
    {
        if (! $bankTransferConfirmed) {
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_TRANSFER_NOT_CONFIRMED',
                'Payment recording requires explicit confirmation that the bank transfer occurred.',
                [],
            );
        }
    }

    private function normalizePositivePaymentAmount(mixed $amount): string
    {
        try {
            $normalized = $this->money->normalizeNonNegative($amount, 'amount');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_AMOUNT_INVALID',
                'Payment amount must be a valid non-negative monetary value.',
                ['amount' => $amount],
            );
        }

        if (! $this->money->isPositive($normalized)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_AMOUNT_NOT_POSITIVE',
                'Payment amount must be strictly positive; partial and zero payments are not supported.',
                ['amount' => $normalized],
            );
        }

        return $normalized;
    }

    private function normalizeIdempotencyKey(string $idempotencyKey): string
    {
        $key = trim($idempotencyKey);

        if ($key === '' || strlen($key) > 64) {
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_IDEMPOTENCY_KEY_INVALID',
                'Idempotency key must be a non-empty string of at most 64 characters.',
                ['idempotency_key' => $idempotencyKey],
            );
        }

        return $key;
    }

    private function normalizeOptionalBankReference(?string $bankReference): ?string
    {
        if ($bankReference === null) {
            return null;
        }

        $value = trim($bankReference);
        if ($value === '') {
            return null;
        }

        return Str::limit($value, 100, '');
    }

    private function resolveOptionalBankExportLineId(
        SupplierSettlementInvoice $invoice,
        ?int $bankExportLineId,
    ): ?int {
        if ($bankExportLineId === null) {
            return null;
        }

        $line = SupplierSettlementBankExportLine::query()
            ->whereKey($bankExportLineId)
            ->lockForUpdate()
            ->first();

        if ($line === null) {
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_EXPORT_LINE_NOT_FOUND',
                'Referenced bank export line was not found.',
                ['bank_export_line_id' => $bankExportLineId],
            );
        }

        if ((int) $line->invoice_id !== (int) $invoice->id) {
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_EXPORT_LINE_MISMATCH',
                'Bank export line does not belong to the invoice being paid.',
                [
                    'invoice_id' => $invoice->id,
                    'bank_export_line_id' => $bankExportLineId,
                    'export_line_invoice_id' => $line->invoice_id,
                ],
            );
        }

        return (int) $line->id;
    }

    private function resolveIdempotentPayment(
        SupplierPayment $existing,
        SupplierSettlementInvoice $invoice,
        mixed $amount,
        int $currencyId,
        ?int $bankExportLineId,
        ?string $bankReference,
    ): SupplierPayment {
        if ($existing->entry_type !== SupplierPayment::ENTRY_TYPE_PAYMENT) {
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_IDEMPOTENCY_CONFLICT',
                'Idempotency key is already used by a non-PAYMENT ledger entry.',
                [
                    'idempotency_key' => $existing->idempotency_key,
                    'existing_entry_type' => $existing->entry_type,
                ],
                409,
            );
        }

        $normalizedAmount = $this->normalizePositivePaymentAmount($amount);
        $normalizedReference = $this->normalizeOptionalBankReference($bankReference);

        $matches = (int) $existing->invoice_id === (int) $invoice->id
            && (int) $existing->currency_id === (int) $currencyId
            && $this->money->equals((string) $existing->amount, $normalizedAmount)
            && (int) $existing->supplier_company_id === (int) $invoice->supplier_company_id
            && (int) ($existing->bank_export_line_id ?? 0) === (int) ($bankExportLineId ?? 0)
            && ($existing->bank_reference ?? null) === $normalizedReference;

        if (! $matches) {
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_IDEMPOTENCY_CONFLICT',
                'Idempotency key was reused with conflicting payment data.',
                [
                    'idempotency_key' => $existing->idempotency_key,
                    'existing_payment_id' => $existing->id,
                    'invoice_id' => $invoice->id,
                ],
                409,
            );
        }

        return $existing->fresh() ?? $existing;
    }

    private function resolveIdempotentReversal(
        SupplierPayment $existing,
        SupplierPayment $originalPayment,
        SupplierSettlementInvoice $invoice,
        string $reason,
    ): SupplierPayment {
        if ($existing->entry_type !== SupplierPayment::ENTRY_TYPE_REVERSAL) {
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_IDEMPOTENCY_CONFLICT',
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
            throw new SupplierSettlementException(
                'SETTLEMENT_PAYMENT_IDEMPOTENCY_CONFLICT',
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
