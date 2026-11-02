<?php

namespace Feeder\Core\Services\Settlement;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Models\CompanyBankAccount;
use Feeder\Core\Models\Currency;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierSettlementBankExport;
use Feeder\Core\Models\SupplierSettlementBankExportException;
use Feeder\Core\Models\SupplierSettlementBankExportLine;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\User;
use Feeder\Core\Services\UuidService;
use Feeder\Core\Support\MoneyNormalizer;
use Feeder\Core\Support\SimpleXlsxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Prepares supplier settlement bank-export workbooks from LOCKED batches.
 *
 * Payment-instruction preparation only: does not record supplier payments,
 * reverse payments, mark invoices paid, or initiate bank transfers.
 * Preparing or downloading an export never implies a payment was made.
 *
 * Feeder does not handle, hold, or transfer money. Origin/platform debit
 * account fields are intentionally left blank — no PLATFORM_ORIGIN designation
 * or Admin bank account is required or selected.
 *
 * Beneficiary account: the invoice supplier company's sole registered
 * company_bank_accounts row (registration data); not SUPPLIER_BENEFICIARY designations.
 *
 * Settlement writer lock order: market → batch → export stream → invoices.
 */
class SupplierSettlementBankExportService
{
    private const STORAGE_DIRECTORY = 'supplier-settlement-bank-exports';

    public function __construct(
        private readonly MoneyNormalizer $money,
        private readonly SimpleXlsxWriter $xlsxWriter,
        private readonly SupplierSettlementBankExportWorkbookBuilder $workbookBuilder,
    ) {}

    /**
     * Prepare a READY bank export for one batch and one currency, or return the
     * existing non-superseded READY export (idempotent).
     *
     * Failed validation/generation attempts persist as FAILED with ready_version NULL.
     */
    public function prepare(
        SupplierSettlementBatch $batch,
        int $currencyId,
        User $actor,
        ?CarbonInterface $valueDate = null,
        ?User $valueDateOverriddenBy = null,
        ?string $valueDateOverrideReason = null,
    ): SupplierSettlementBankExport {
        return DB::transaction(function () use (
            $batch,
            $currencyId,
            $actor,
            $valueDate,
            $valueDateOverriddenBy,
            $valueDateOverrideReason,
        ) {
            $marketId = (int) ($batch->market_id
                ?? SupplierSettlementBatch::query()->whereKey($batch->id)->value('market_id'));

            $market = Market::query()->whereKey($marketId)->lockForUpdate()->firstOrFail();
            $market->loadMissing('currency');

            /** @var SupplierSettlementBatch $lockedBatch */
            $lockedBatch = SupplierSettlementBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedBatch->market_id !== (int) $market->id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_MARKET_MISMATCH',
                    'Settlement batch market changed during bank-export locking.',
                    ['batch_id' => $lockedBatch->id],
                );
            }

            if ($lockedBatch->voided_at !== null) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_VOIDED',
                    'A voided settlement batch cannot be exported.',
                    ['batch_id' => $lockedBatch->id],
                );
            }

            if ($lockedBatch->status !== SupplierSettlementBatch::STATUS_LOCKED) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_NOT_LOCKED',
                    'Only LOCKED settlement batches can be exported.',
                    [
                        'batch_id' => $lockedBatch->id,
                        'status' => $lockedBatch->status,
                    ],
                );
            }

            $currency = Currency::query()->whereKey($currencyId)->first();
            if ($currency === null) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_CURRENCY_NOT_FOUND',
                    'Export currency was not found.',
                    ['currency_id' => $currencyId],
                );
            }

            if ((int) $market->currency_id !== (int) $currency->id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_EXPORT_CURRENCY_MISMATCH',
                    'Bank exports must use the batch market currency.',
                    [
                        'batch_id' => $lockedBatch->id,
                        'market_currency_id' => $market->currency_id,
                        'requested_currency_id' => $currency->id,
                    ],
                );
            }

            $existingExports = SupplierSettlementBankExport::query()
                ->where('batch_id', $lockedBatch->id)
                ->where('currency_id', $currency->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $activeReady = $existingExports->first(
                static fn (SupplierSettlementBankExport $export): bool => $export->status === SupplierSettlementBankExport::STATUS_READY
                    && $export->superseded_at === null
            );

            if ($activeReady !== null) {
                return $activeReady->fresh(['exportLines', 'exceptions']);
            }

            $attemptNumber = ((int) $existingExports->max('attempt_number')) + 1;
            $resolvedValueDate = $this->resolveValueDate($lockedBatch, $valueDate);
            $valueDateOverridden = $valueDate !== null
                && $resolvedValueDate->toDateString() !== $lockedBatch->settlement_date?->toDateString();

            $invoices = SupplierSettlementInvoice::query()
                ->where('batch_id', $lockedBatch->id)
                ->where('currency_id', $currency->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $activeExportIds = $existingExports
                ->filter(
                    static fn (SupplierSettlementBankExport $export): bool => $export->status === SupplierSettlementBankExport::STATUS_READY
                        && $export->superseded_at === null
                )
                ->pluck('id')
                ->all();

            $alreadyExportedInvoiceIds = $activeExportIds === []
                ? []
                : SupplierSettlementBankExportLine::query()
                    ->whereIn('bank_export_id', $activeExportIds)
                    ->pluck('invoice_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

            $linePayloads = [];
            $exceptionPayloads = [];
            $rowNumber = 0;
            $valueDateText = $resolvedValueDate->format('d/m/Y');
            $particulars = (string) $lockedBatch->batch_number;

            foreach ($invoices as $invoice) {
                $evaluation = $this->evaluateInvoiceForExport(
                    $invoice,
                    $currency,
                    $alreadyExportedInvoiceIds,
                    $valueDateText,
                    $particulars,
                );

                if ($evaluation['exception'] !== null) {
                    $exceptionPayloads[] = $evaluation['exception'];

                    continue;
                }

                $rowNumber++;
                $linePayloads[] = $evaluation['line'] + ['row_number' => $rowNumber];
            }

            if ($linePayloads === []) {
                return $this->persistFailedAttempt(
                    $lockedBatch,
                    $currency,
                    $attemptNumber,
                    $resolvedValueDate,
                    $valueDateOverridden,
                    $valueDateOverriddenBy,
                    $valueDateOverrideReason,
                    $actor,
                    'NO_ELIGIBLE_INVOICES',
                    $exceptionPayloads,
                );
            }

            $readyVersion = ((int) $existingExports->max('ready_version')) + 1;

            $export = SupplierSettlementBankExport::query()->create([
                'batch_id' => $lockedBatch->id,
                'currency_id' => $currency->id,
                'currency_code_snapshot' => strtoupper((string) $currency->code),
                'purpose' => SupplierSettlementBankExport::PURPOSE_SETTLEMENT,
                'attempt_number' => $attemptNumber,
                'ready_version' => $readyVersion,
                'status' => SupplierSettlementBankExport::STATUS_READY,
                'value_date' => $resolvedValueDate->toDateString(),
                'value_date_overridden' => $valueDateOverridden,
                'value_date_overridden_by' => $valueDateOverridden ? $valueDateOverriddenBy?->id : null,
                'value_date_override_reason' => $valueDateOverridden ? $valueDateOverrideReason : null,
                'generated_by' => $actor->id,
                'generated_at' => now(),
            ]);

            foreach ($linePayloads as $payload) {
                SupplierSettlementBankExportLine::query()->create($payload + [
                    'bank_export_id' => $export->id,
                ]);
            }

            foreach ($exceptionPayloads as $payload) {
                SupplierSettlementBankExportException::query()->create($payload + [
                    'bank_export_id' => $export->id,
                ]);
            }

            $export->load('exportLines');

            try {
                $this->writeWorkbookFile($export);
            } catch (Throwable $e) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_EXPORT_WORKBOOK_FAILED',
                    'Bank-export workbook generation failed.',
                    [
                        'batch_id' => $lockedBatch->id,
                        'currency_id' => $currency->id,
                        'cause' => $e->getMessage(),
                    ],
                );
            }

            return $export->fresh(['exportLines', 'exceptions']);
        });
    }

    /**
     * Resolve the absolute path of a READY export's stored workbook for download.
     *
     * Read-only: never prepares, regenerates, or mutates export state.
     * Returns null when status/file metadata is invalid or the file is missing.
     */
    public function resolveStoredWorkbookAbsolutePath(SupplierSettlementBankExport $export): ?string
    {
        if ($export->status !== SupplierSettlementBankExport::STATUS_READY) {
            return null;
        }

        $fileUuid = trim((string) $export->file_uuid);
        if ($fileUuid === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $fileUuid)) {
            return null;
        }

        $batchId = (int) $export->batch_id;
        if ($batchId <= 0) {
            return null;
        }

        $relativePath = self::STORAGE_DIRECTORY.'/'.$batchId.'/'.$fileUuid.'.xlsx';
        if (str_contains($relativePath, '..')) {
            return null;
        }

        $disk = Storage::disk('local');
        $absolutePath = $disk->path($relativePath);
        $exportsRoot = realpath($disk->path(self::STORAGE_DIRECTORY))
            ?: $disk->path(self::STORAGE_DIRECTORY);
        $resolvedPath = realpath($absolutePath);

        if ($resolvedPath === false || ! is_file($resolvedPath)) {
            return null;
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $exportsRoot), '/').'/';
        $normalizedPath = str_replace('\\', '/', $resolvedPath);
        if (! str_starts_with($normalizedPath, $rootPrefix) && $normalizedPath !== rtrim($rootPrefix, '/')) {
            return null;
        }

        return $resolvedPath;
    }

    /**
     * Supersede an active READY export so a later prepare() can allocate the next ready_version.
     * Preserves historical export rows and lines.
     */
    public function supersede(
        SupplierSettlementBankExport $export,
        User $actor,
        string $reason,
        string $previousFileDisposition = SupplierSettlementBankExport::DISPOSITION_NOT_SUBMITTED,
    ): SupplierSettlementBankExport {
        $reason = trim($reason);
        if ($reason === '') {
            throw new SupplierSettlementException(
                'SETTLEMENT_EXPORT_SUPERSEDE_REASON_REQUIRED',
                'A supersede reason is required.',
                ['bank_export_id' => $export->id],
            );
        }

        $allowedDispositions = [
            SupplierSettlementBankExport::DISPOSITION_NOT_SUBMITTED,
            SupplierSettlementBankExport::DISPOSITION_BANK_REJECTED,
        ];
        if (! in_array($previousFileDisposition, $allowedDispositions, true)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_EXPORT_INVALID_DISPOSITION',
                'Unsupported previous file disposition.',
                ['previous_file_disposition' => $previousFileDisposition],
            );
        }

        return DB::transaction(function () use ($export, $actor, $reason, $previousFileDisposition) {
            $batchId = (int) ($export->batch_id
                ?? SupplierSettlementBankExport::query()->whereKey($export->id)->value('batch_id'));

            $marketId = (int) SupplierSettlementBatch::query()->whereKey($batchId)->value('market_id');

            Market::query()->whereKey($marketId)->lockForUpdate()->firstOrFail();

            /** @var SupplierSettlementBatch $lockedBatch */
            $lockedBatch = SupplierSettlementBatch::query()
                ->whereKey($batchId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var SupplierSettlementBankExport $lockedExport */
            $lockedExport = SupplierSettlementBankExport::query()
                ->whereKey($export->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedExport->batch_id !== (int) $lockedBatch->id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_EXPORT_BATCH_MISMATCH',
                    'Bank export batch changed during supersede locking.',
                    ['bank_export_id' => $lockedExport->id],
                );
            }

            if (
                $lockedExport->status !== SupplierSettlementBankExport::STATUS_READY
                || $lockedExport->superseded_at !== null
            ) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_EXPORT_NOT_ACTIVE_READY',
                    'Only a non-superseded READY bank export can be superseded.',
                    [
                        'bank_export_id' => $lockedExport->id,
                        'status' => $lockedExport->status,
                    ],
                );
            }

            $lockedExport->forceFill([
                'status' => SupplierSettlementBankExport::STATUS_SUPERSEDED,
                'superseded_at' => now(),
                'superseded_by_user_id' => $actor->id,
                'supersede_reason' => $reason,
                'previous_file_disposition' => $previousFileDisposition,
            ])->save();

            return $lockedExport->fresh(['exportLines', 'exceptions']);
        });
    }

    private function resolveValueDate(
        SupplierSettlementBatch $batch,
        ?CarbonInterface $valueDate,
    ): CarbonImmutable {
        if ($valueDate !== null) {
            return CarbonImmutable::instance($valueDate)->startOfDay();
        }

        $settlementDate = $batch->settlement_date;
        if ($settlementDate === null) {
            throw new SupplierSettlementException(
                'SETTLEMENT_BATCH_DATE_MISSING',
                'Settlement batch has no settlement date for value-date defaulting.',
                ['batch_id' => $batch->id],
            );
        }

        return CarbonImmutable::parse($settlementDate->toDateString())->startOfDay();
    }

    /**
     * Resolve the supplier company's registered bank account (registration / company_bank_accounts).
     *
     * Does not use SUPPLIER_BENEFICIARY designations. Requires exactly one account for the
     * invoice's supplier company — never another company's account and never a first-available
     * pick among multiple rows.
     *
     * @return array{account: ?CompanyBankAccount, error: ?string, detail: ?string}
     */
    private function resolveBeneficiaryAccount(SupplierSettlementInvoice $invoice): array
    {
        $supplierCompanyId = (int) $invoice->supplier_company_id;
        if ($supplierCompanyId <= 0) {
            return [
                'account' => null,
                'error' => SupplierSettlementBankExportException::REASON_MISSING_BENEFICIARY_ACCOUNT,
                'detail' => 'Settlement invoice has no supplier company for beneficiary resolution.',
            ];
        }

        $accounts = CompanyBankAccount::query()
            ->where('company_id', $supplierCompanyId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($accounts->isEmpty()) {
            return [
                'account' => null,
                'error' => SupplierSettlementBankExportException::REASON_MISSING_BENEFICIARY_ACCOUNT,
                'detail' => 'Supplier company has no registered bank account.',
            ];
        }

        if ($accounts->count() > 1) {
            return [
                'account' => null,
                'error' => SupplierSettlementBankExportException::REASON_AMBIGUOUS_BENEFICIARY_ACCOUNT,
                'detail' => 'Supplier company has multiple registered bank accounts; exactly one is required.',
            ];
        }

        /** @var CompanyBankAccount $account */
        $account = $accounts->first();

        if ((int) $account->company_id !== $supplierCompanyId) {
            return [
                'account' => null,
                'error' => SupplierSettlementBankExportException::REASON_BENEFICIARY_ACCOUNT_COMPANY_MISMATCH,
                'detail' => 'Beneficiary bank account does not belong to the settlement invoice supplier company.',
            ];
        }

        return ['account' => $account, 'error' => null, 'detail' => null];
    }

    /**
     * @param  list<int>  $alreadyExportedInvoiceIds
     * @return array{
     *     line: ?array<string, mixed>,
     *     exception: ?array<string, mixed>
     * }
     */
    private function evaluateInvoiceForExport(
        SupplierSettlementInvoice $invoice,
        Currency $currency,
        array $alreadyExportedInvoiceIds,
        string $valueDateText,
        string $particulars,
    ): array {
        if ((int) $invoice->currency_id !== (int) $currency->id) {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    SupplierSettlementBankExportException::REASON_CURRENCY_MISMATCH,
                    'Invoice currency does not match the export currency.',
                ),
            ];
        }

        if ($invoice->instruction_status !== SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE) {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    SupplierSettlementBankExportException::REASON_INVOICE_NOT_ACTIVE,
                    'Invoice instruction status must be ACTIVE for bank export.',
                ),
            ];
        }

        if ($invoice->payment_status === SupplierSettlementInvoice::PAYMENT_STATUS_PAID) {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    SupplierSettlementBankExportException::REASON_INVOICE_ALREADY_PAID,
                    'Paid invoices cannot be included in a bank export.',
                ),
            ];
        }

        if (in_array((int) $invoice->id, $alreadyExportedInvoiceIds, true)) {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    SupplierSettlementBankExportException::REASON_INVOICE_ALREADY_EXPORTED,
                    'Invoice is already assigned to an active READY bank export.',
                ),
            ];
        }

        $netPayable = (string) ($invoice->net_payable ?? $invoice->payable_total);
        if (! $this->money->isPositive($netPayable)) {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    SupplierSettlementBankExportException::REASON_INVALID_AMOUNT,
                    'Invoice net payable must be positive for bank export.',
                ),
            ];
        }

        $beneficiaryResolution = $this->resolveBeneficiaryAccount($invoice);
        if ($beneficiaryResolution['error'] !== null) {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    $beneficiaryResolution['error'],
                    $beneficiaryResolution['detail'] ?? 'Supplier beneficiary bank account could not be resolved.',
                ),
            ];
        }

        /** @var CompanyBankAccount $beneficiaryAccount */
        $beneficiaryAccount = $beneficiaryResolution['account'];

        $bankCode = $this->asIdentifierString($beneficiaryAccount->bank_code ?? null);
        $branchCode = $this->asIdentifierString($beneficiaryAccount->branch_code ?? null);
        $accountNumber = $this->asIdentifierString($beneficiaryAccount->account_number ?? null);
        $accountName = trim((string) ($beneficiaryAccount->account_name ?? ''));

        if ($bankCode === '') {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    SupplierSettlementBankExportException::REASON_MISSING_BENEFICIARY_BANK_CODE,
                    'Beneficiary bank code is required.',
                ),
            ];
        }

        if ($branchCode === '') {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    SupplierSettlementBankExportException::REASON_MISSING_BENEFICIARY_BRANCH_CODE,
                    'Beneficiary branch code is required.',
                ),
            ];
        }

        if ($accountNumber === '') {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    SupplierSettlementBankExportException::REASON_MISSING_BENEFICIARY_ACCOUNT_NUMBER,
                    'Beneficiary account number is required.',
                ),
            ];
        }

        if ($accountName === '') {
            return [
                'line' => null,
                'exception' => $this->exceptionPayload(
                    $invoice,
                    SupplierSettlementBankExportException::REASON_MISSING_BENEFICIARY_ACCOUNT_NAME,
                    'Beneficiary account name is required.',
                ),
            ];
        }

        $amount = number_format((float) ($invoice->net_payable ?? $invoice->payable_total), 2, '.', '');

        return [
            'line' => [
                'invoice_id' => $invoice->id,
                'supplier_company_id' => $invoice->supplier_company_id,
                'currency_id' => $currency->id,
                'currency_code_snapshot' => strtoupper((string) $currency->code),
                'origin_company_bank_account_id' => null,
                'beneficiary_company_bank_account_id' => (int) $beneficiaryAccount->id,
                'origin_account_number' => null,
                'origin_account_name' => null,
                'value_date_text' => $valueDateText,
                'beneficiary_bank_code' => $bankCode,
                'beneficiary_branch_code' => $branchCode,
                'beneficiary_account_number' => $accountNumber,
                'beneficiary_account_name' => $accountName,
                'amount' => $amount,
                'particulars' => $particulars,
                'reference_text' => (string) $invoice->invoice_number,
            ],
            'exception' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $exceptionPayloads
     */
    private function persistFailedAttempt(
        SupplierSettlementBatch $batch,
        Currency $currency,
        int $attemptNumber,
        CarbonImmutable $valueDate,
        bool $valueDateOverridden,
        ?User $valueDateOverriddenBy,
        ?string $valueDateOverrideReason,
        User $actor,
        string $failureReason,
        array $exceptionPayloads,
    ): SupplierSettlementBankExport {
        $export = SupplierSettlementBankExport::query()->create([
            'batch_id' => $batch->id,
            'currency_id' => $currency->id,
            'currency_code_snapshot' => strtoupper((string) $currency->code),
            'purpose' => SupplierSettlementBankExport::PURPOSE_SETTLEMENT,
            'attempt_number' => $attemptNumber,
            'ready_version' => null,
            'status' => SupplierSettlementBankExport::STATUS_FAILED,
            'value_date' => $valueDate->toDateString(),
            'value_date_overridden' => $valueDateOverridden,
            'value_date_overridden_by' => $valueDateOverridden ? $valueDateOverriddenBy?->id : null,
            'value_date_override_reason' => $valueDateOverridden ? $valueDateOverrideReason : null,
            'failure_reason' => $failureReason,
            'generated_by' => $actor->id,
            'generated_at' => now(),
        ]);

        foreach ($exceptionPayloads as $payload) {
            SupplierSettlementBankExportException::query()->create($payload + [
                'bank_export_id' => $export->id,
            ]);
        }

        return $export->fresh(['exportLines', 'exceptions']);
    }

    private function writeWorkbookFile(SupplierSettlementBankExport $export): void
    {
        $export->loadMissing('batch');

        $fileUuid = UuidService::generate();
        $batchToken = preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '_',
            (string) ($export->batch?->batch_number ?: $export->batch_id),
        ) ?: (string) $export->batch_id;
        $filename = mb_substr(sprintf(
            'supplier-settlement-%s-%s-v%s.xlsx',
            $batchToken,
            strtoupper((string) $export->currency_code_snapshot),
            (string) $export->ready_version,
        ), 0, 255);

        $relativePath = self::STORAGE_DIRECTORY.'/'.$export->batch_id.'/'.$fileUuid.'.xlsx';

        $absolutePath = Storage::disk('local')->path($relativePath);
        $directory = dirname($absolutePath);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create bank-export storage directory.');
        }

        $sheets = $this->workbookBuilder->buildSheets($export->exportLines->sortBy('row_number')->values());
        $this->xlsxWriter->write($absolutePath, $sheets);

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Bank-export workbook was not written.');
        }

        $hash = hash_file('sha256', $absolutePath);
        if (! is_string($hash) || strlen($hash) !== 64) {
            throw new RuntimeException('Unable to fingerprint bank-export workbook.');
        }

        $export->forceFill([
            'file_uuid' => $fileUuid,
            'content_sha256' => $hash,
            'byte_size' => filesize($absolutePath) ?: 0,
            'original_filename' => $filename,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function exceptionPayload(
        SupplierSettlementInvoice $invoice,
        string $reasonCode,
        string $reasonDetail,
    ): array {
        return [
            'invoice_id' => $invoice->id,
            'supplier_company_id' => $invoice->supplier_company_id,
            'currency_id' => $invoice->currency_id,
            'reason_code' => $reasonCode,
            'reason_detail' => mb_substr($reasonDetail, 0, 255),
        ];
    }

    private function asIdentifierString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        // Preserve leading zeros; never cast through int/float.
        return trim((string) $value);
    }
}
