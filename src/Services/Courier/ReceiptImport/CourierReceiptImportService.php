<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\CourierReceiptImport;
use Feeder\Core\Models\CourierReceiptImportRow;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Models\User;
use Feeder\Core\Exceptions\ResellerEarningObligationException;
use Feeder\Core\Models\CourierRemittanceApplication;
use Feeder\Core\Models\ResellerEarningObligation;
use Feeder\Core\Services\Notification\FinancialNotificationService;
use Feeder\Core\Services\Order\SupplierReturnScanService;
use Feeder\Core\Services\Settlement\ResellerEarningObligationService;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Admin courier invoice upload → reconciliation preview → confirmation.
 *
 * Preview never mutates order status. Confirmation processes every matched row
 * in one transaction. An explicit DELIVERED status is applied through
 * CourierRemittanceApplicationService. Any other non-empty courier status is
 * returned through SupplierReturnScanService. A blocked import changes nothing.
 * Delivered orders may receive an idempotent reseller earning obligation.
 * Confirmation does not record supplier or reseller settlement payments.
 *
 * Courier fee source rule: Feeder never uses invoice delivery-charge columns
 * (or operator-entered delivery_charge totals) as the courier fee. Preview
 * courier_charge and booking fees resolve from shipment.courier_fee_snapshot /
 * order.courier_fee_amount, which were calculated from
 * CourierMarketPricing.first_kg_fee via CourierFeeCalculator at booking time.
 */
class CourierReceiptImportService
{
    public function __construct(
        private readonly CourierReceiptInvoiceParserResolver $parserResolver,
        private readonly CourierReceiptInvoiceFileSummaryBuilder $fileSummaryBuilder,
        private readonly CourierReceiptImportMatcher $matcher,
        private readonly CourierReceiptImportReconciler $reconciler,
        private readonly MoneyNormalizer $money,
        private readonly ResellerEarningObligationService $earningObligationService,
        private readonly CourierRemittanceApplicationService $remittanceApplications,
        private readonly CourierRemittanceColumnExtractor $remittanceColumns,
        private readonly SupplierReturnScanService $returns,
        private readonly FinancialNotificationService $financialNotifications,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     courier_code: string,
     *     template_format: string,
     *     currency: string,
     *     format_id: string,
     *     format_label: string,
     *     template_available: bool,
     *     template_unavailable_reason: string|null
     * }>
     */
    public function listCourierServicesForUi(): array
    {
        $services = CourierService::query()
            ->with('courier')
            ->where('is_active', true)
            ->whereHas('courier', static fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        $items = [];
        foreach ($services as $service) {
            $parser = $this->parserResolver->forCourierCode((string) $service->courier?->code);
            if ($parser === null) {
                $items[] = [
                    'id' => (string) $service->uuid,
                    'name' => (string) $service->name.' ('.$service->courier?->name.')',
                    'courier_code' => (string) ($service->courier?->code ?? ''),
                    'template_format' => '',
                    'currency' => 'LKR',
                    'format_id' => '',
                    'format_label' => 'No verified invoice format',
                    'template_available' => false,
                    'template_unavailable_reason' => 'Native invoice specification missing for this courier.',
                ];

                continue;
            }

            $items[] = [
                'id' => (string) $service->uuid,
                'name' => (string) ($service->courier?->name ?? $service->name),
                'courier_code' => (string) $service->courier->code,
                'template_format' => $parser->templateFormat(),
                'currency' => 'LKR',
                'format_id' => $parser->formatId(),
                'format_label' => $parser->formatLabel(),
                'template_available' => $parser->supportsTemplateDownload(),
                'template_unavailable_reason' => $parser->templateUnavailableReason(),
            ];
        }

        return $items;
    }

    public function templateCsvForService(CourierService $service): string
    {
        $parser = $this->parserResolver->forCourierService($service);

        if (! $parser->supportsTemplateDownload()) {
            throw ValidationException::withMessages([
                'courier_service' => [
                    $parser->templateUnavailableReason()
                    ?? 'No verified template is available for this courier service.',
                ],
            ]);
        }

        return $parser->templateCsv();
    }

    /**
     * Read-only invoice totals for Step 3. Parses the selected CSV or XLSX
     * and does not store an import or change orders.
     *
     * @return array{file_summary: array<string, mixed>}
     */
    public function summarizeUploadedFile(CourierService $courierService, UploadedFile $file): array
    {
        $parser = $this->parserResolver->forCourierService($courierService);

        return [
            'file_summary' => $this->fileSummaryBuilder->fromParsed($parser->parse($file)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadAndPreview(
        User $admin,
        CourierService $courierService,
        UploadedFile $file,
        int $orderCount,
        mixed $totalCollectedCod,
        mixed $deliveryCharge,
        mixed $netIncome = null,
    ): array {
        if ($orderCount < 0) {
            throw ValidationException::withMessages([
                'order_count' => ['Order count must be a whole number greater than or equal to 0.'],
            ]);
        }

        $enteredCod = $this->money->normalizeNonNegative($totalCollectedCod, 'total_collected_cod');
        $enteredCharge = $this->money->normalizeNonNegative($deliveryCharge, 'delivery_charge');
        $computedNet = $this->money->subtract($enteredCod, $enteredCharge);

        // Net income = COD − charge (may be negative); never trust client-only calculation.
        $enteredNetIncome = $computedNet;
        if ($netIncome !== null && $netIncome !== '') {
            $provided = trim((string) $netIncome);
            if ($provided !== '' && is_numeric($provided)) {
                $providedFormatted = number_format((float) $provided, 2, '.', '');
                if (! $this->money->equals($providedFormatted, $computedNet)) {
                    throw ValidationException::withMessages([
                        'net_income' => ['Net Income must equal Total Collected COD minus Delivery Charge.'],
                    ]);
                }
            }
        }

        $parser = $this->parserResolver->forCourierService($courierService);

        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file could not be read.'],
            ]);
        }

        $contentHash = hash_file('sha256', $path);
        if (! is_string($contentHash) || $contentHash === '') {
            throw ValidationException::withMessages([
                'file' => ['Unable to fingerprint the uploaded file.'],
            ]);
        }

        $existing = CourierReceiptImport::query()
            ->where('content_hash', $contentHash)
            ->where('courier_service_id', $courierService->id)
            ->where('entered_order_count', $orderCount)
            ->where('entered_total_collected_cod', $enteredCod)
            ->where('entered_delivery_charge', $enteredCharge)
            ->first();

        if ($existing !== null) {
            return $this->serializePreview($existing->fresh(['rows', 'courierService.courier']));
        }

        // Parse before any persistence / storage write (no DB locks during parse).
        $parsed = $parser->parse($file);
        $fileSummary = $this->fileSummaryBuilder->fromParsed($parsed);
        $matchedRows = $this->matcher->matchRows($courierService, $parsed['rows']);
        $reconciliation = $this->reconciler->reconcile(
            $matchedRows,
            $orderCount,
            $enteredCod,
            $enteredCharge,
            $enteredNetIncome,
            (bool) $parsed['file_has_cod_amounts'],
            (bool) $parsed['file_has_delivery_charges'],
        );
        $reconciliationNotes = $reconciliation['reconciliation_notes'];
        $reconciliationNotes['file_summary'] = $fileSummary;
        $reconciliationNotes['remittance_totals_by_currency'] = $this->remittanceColumns->totalsByCurrency($matchedRows);

        $storedPath = null;

        try {
            $storedPath = $this->storeUpload($file, (int) $admin->id);

            $import = DB::transaction(function () use (
                $admin,
                $courierService,
                $file,
                $parsed,
                $contentHash,
                $parser,
                $orderCount,
                $enteredCod,
                $enteredCharge,
                $enteredNetIncome,
                $reconciliation,
                $reconciliationNotes,
                $matchedRows,
                $storedPath,
            ): CourierReceiptImport {
                $import = CourierReceiptImport::query()->create([
                    'courier_service_id' => $courierService->id,
                    'uploaded_by' => $admin->id,
                    'original_filename' => $this->safeOriginalFilename($file),
                    'file_type' => $parsed['file_type'],
                    'stored_path' => $storedPath,
                    'content_hash' => $contentHash,
                    'format_id' => $parser->formatId(),
                    'entered_order_count' => $orderCount,
                    'entered_total_collected_cod' => $enteredCod,
                    'entered_delivery_charge' => $enteredCharge,
                    'entered_net_income' => $enteredNetIncome,
                    'file_row_count' => $reconciliation['file_row_count'],
                    'matched_count' => $reconciliation['matched_count'],
                    'unmatched_count' => $reconciliation['unmatched_count'],
                    'ambiguous_count' => $reconciliation['ambiguous_count'],
                    'duplicate_count' => $reconciliation['duplicate_count'],
                    'previously_processed_count' => $reconciliation['previously_processed_count'],
                    'service_mismatch_count' => $reconciliation['service_mismatch_count'],
                    'invalid_count' => $reconciliation['invalid_count'],
                    'already_applied_count' => $reconciliation['already_applied_count'],
                    'file_has_cod_amounts' => (bool) $parsed['file_has_cod_amounts'],
                    'file_has_delivery_charges' => (bool) $parsed['file_has_delivery_charges'],
                    'imported_cod_total' => $reconciliation['imported_cod_total'],
                    'imported_delivery_charge_total' => $reconciliation['imported_delivery_charge_total'],
                    'order_count_matches' => $reconciliation['order_count_matches'],
                    'cod_totals_match' => $reconciliation['cod_totals_match'],
                    'delivery_charge_totals_match' => $reconciliation['delivery_charge_totals_match'],
                    'net_income_consistent' => $reconciliation['net_income_consistent'],
                    'totals_reconcile' => $reconciliation['totals_reconcile'],
                    'reconciliation_notes' => $reconciliationNotes,
                    'status' => CourierReceiptImportStatus::PREVIEWED,
                ]);

                foreach ($matchedRows as $row) {
                    CourierReceiptImportRow::query()->create([
                        'courier_receipt_import_id' => $import->id,
                        'row_number' => $row['row_number'],
                        'raw_tracking_number' => $row['raw_tracking_number'],
                        'normalized_tracking_number' => $row['normalized_tracking_number'],
                        'raw_order_number' => $row['raw_order_number'],
                        'raw_collected_cod' => $row['raw_collected_cod'],
                        'raw_delivery_charge' => $row['raw_delivery_charge'],
                        'collected_cod' => $row['collected_cod'],
                        'delivery_charge' => $row['delivery_charge'],
                        'gross_remittance_amount' => $row['gross_remittance_amount'] ?? null,
                        'courier_charge_amount' => $row['courier_charge_amount'] ?? null,
                        'net_remittance_amount' => $row['net_remittance_amount'] ?? null,
                        'remittance_currency' => $row['remittance_currency'] ?? null,
                        'remittance_business_date' => $row['remittance_business_date'] ?? null,
                        'remittance_provided_fields' => $row['remittance_provided_fields'] ?? null,
                        'match_result' => $row['match_result'],
                        'matched_order_id' => $row['matched_order_id'],
                        'matched_shipment_id' => $row['matched_shipment_id'],
                        'matched_supplier_id' => $row['matched_supplier_id'],
                        'matched_order_number' => $row['matched_order_number'],
                        'matched_supplier_label' => $row['matched_supplier_label'],
                        'order_status_snapshot' => $row['order_status_snapshot'],
                        'message' => $row['message'],
                    ]);
                }

                return $import;
            });
        } catch (Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        return $this->serializePreview($import->fresh(['rows', 'courierService.courier']));
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreview(User $admin, CourierReceiptImport $import): array
    {
        unset($admin); // Admin authorization is enforced at the HTTP layer.

        if ($import->status !== CourierReceiptImportStatus::PREVIEWED
            && $import->status !== CourierReceiptImportStatus::FAILED
        ) {
            // CONFIRMED imports may still be viewed read-only.
        }

        return $this->serializePreview($import->fresh(['rows', 'courierService.courier']));
    }

    /**
     * Confirm a PREVIEWED import: transition eligible MATCHED DISPATCHED orders
     * to DELIVERED. Atomic — order transitions and import confirmation commit together.
     *
     * Concurrent confirmations serialize on a row lock; a second confirm is rejected.
     * Hard failures roll back all transitions; failure_reason is stored afterwards
     * while status remains PREVIEWED so the admin can retry safely.
     *
     * @return array<string, mixed>
     */
    public function confirm(User $admin, CourierReceiptImport $import): array
    {
        // One confirmation applies every matched row, then records earnings.
        // PHP's default 30 second limit aborts that work and rolls the transaction back.
        set_time_limit(0);

        $importId = (int) $import->id;

        try {
            $result = DB::transaction(function () use ($admin, $importId): array {
                /** @var CourierReceiptImport $locked */
                $locked = CourierReceiptImport::query()
                    ->whereKey($importId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === CourierReceiptImportStatus::CONFIRMED) {
                    throw ValidationException::withMessages([
                        'import' => ['This courier receipt import has already been confirmed.'],
                    ]);
                }

                if ($locked->status !== CourierReceiptImportStatus::PREVIEWED) {
                    throw ValidationException::withMessages([
                        'import' => ['Only PREVIEWED courier receipt imports can be confirmed.'],
                    ]);
                }

                $blocked = $locked->reconciliation_notes['confirmation_blocked_reasons'] ?? [];
                if (! is_array($blocked)) {
                    $blocked = [];
                }
                if ($blocked !== []) {
                    throw ValidationException::withMessages([
                        'import' => [
                            'Confirmation is blocked: '.implode(', ', $blocked).'.',
                        ],
                    ]);
                }

                $locked->loadMissing(['rows', 'courierService']);

                $deliveredCount = 0;
                $returnedCount = 0;
                $skippedCount = 0;
                $ineligibleCount = 0;
                $rowOutcomes = [];

                foreach ($locked->rows->sortBy('row_number') as $row) {
                    $outcome = $this->confirmRow($admin, $locked, $row);
                    $rowOutcomes[] = $outcome;

                    match ($outcome['outcome']) {
                        'delivered' => $deliveredCount++,
                        'returned' => $returnedCount++,
                        'ineligible' => $ineligibleCount++,
                        default => $skippedCount++,
                    };
                }

                $confirmationSummary = [
                    'delivered_count' => $deliveredCount,
                    'returned_count' => $returnedCount,
                    'skipped_count' => $skippedCount,
                    'ineligible_count' => $ineligibleCount,
                ];

                $locked->forceFill([
                    'status' => CourierReceiptImportStatus::CONFIRMED,
                    'confirmed_at' => now(),
                    'confirmed_by' => (int) $admin->id,
                    'confirmation_summary' => $confirmationSummary,
                    'failure_reason' => null,
                ])->save();

                // Record earnings only after the import is CONFIRMED so eligibility
                // evidence checks see authoritative remittance confirmation.
                $locked->loadMissing('rows');
                foreach ($rowOutcomes as $outcome) {
                    if (($outcome['outcome'] ?? null) !== 'delivered' || empty($outcome['order_id'])) {
                        continue;
                    }

                    $row = $locked->rows->firstWhere('row_number', (int) $outcome['row_number']);
                    if ($row === null) {
                        continue;
                    }

                    $deliveredOrder = Order::query()
                        ->with(['items', 'market', 'currency', 'shipment'])
                        ->find((int) $outcome['order_id']);

                    if ($deliveredOrder === null) {
                        continue;
                    }

                    // Hard earning-persistence failures must abort the outer transaction.
                    // Do not catch-and-continue: a confirmed import must not commit when
                    // unexpected earning persistence fails. Expected non-calculable
                    // exclusions are returned as skipped by the earning service.
                    $this->earningObligationService->recordEarningForConfirmedDelivery(
                        $deliveredOrder,
                        $locked,
                        $row,
                        (int) $admin->id,
                    );
                }

                return [
                    'delivered_count' => $deliveredCount,
                    'returned_count' => $returnedCount,
                    'skipped_count' => $skippedCount,
                    'ineligible_count' => $ineligibleCount,
                    'row_outcomes' => $rowOutcomes,
                    'confirmation_summary' => $confirmationSummary,
                ];
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ResellerEarningObligationException $exception) {
            $this->persistConfirmationFailure($importId, $exception);

            throw ValidationException::withMessages([
                'import' => [
                    'Confirmation failed and was rolled back because reseller earning '
                    .'recording could not complete: '.$this->safeFailureMessage($exception),
                ],
            ]);
        } catch (Throwable $exception) {
            $this->persistConfirmationFailure($importId, $exception);

            throw ValidationException::withMessages([
                'import' => [
                    'Confirmation failed and was rolled back. '.$this->safeFailureMessage($exception),
                ],
            ]);
        }

        $confirmedImport = CourierReceiptImport::query()
            ->with(['rows', 'courierService.courier', 'confirmer.profile', 'uploader.profile'])
            ->findOrFail($importId);

        $this->dispatchDeliveryNotifications($admin, $result['row_outcomes'] ?? []);

        $preview = $this->serializePreview($confirmedImport);

        return [
            'import' => $preview['import'],
            'rows' => $preview['rows'],
            'mutated_orders' => $result['delivered_count'] > 0 || $result['returned_count'] > 0,
            'applied' => false,
            'confirmation' => [
                'status' => CourierReceiptImportStatus::CONFIRMED->value,
                'delivered_count' => $result['delivered_count'],
                'returned_count' => $result['returned_count'],
                'skipped_count' => $result['skipped_count'],
                'ineligible_count' => $result['ineligible_count'],
                'row_outcomes' => $result['row_outcomes'],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rowOutcomes
     */
    private function dispatchDeliveryNotifications(User $admin, array $rowOutcomes): void
    {
        foreach ($rowOutcomes as $outcome) {
            if (($outcome['outcome'] ?? null) !== 'delivered' || empty($outcome['order_id'])) {
                continue;
            }

            $order = Order::query()->find((int) $outcome['order_id']);
            if ($order === null) {
                continue;
            }

            $obligation = ResellerEarningObligation::query()
                ->where('order_id', $order->id)
                ->first();

            $application = null;
            if (! empty($outcome['application_id'])) {
                $application = CourierRemittanceApplication::query()->find((int) $outcome['application_id']);
            }

            $this->financialNotifications->notifyOrderDelivered(
                $admin,
                $order,
                $obligation,
                $application,
            );
        }
    }

    /**
     * Explicitly apply one matched remittance row.
     *
     * @return array<string, mixed>
     */
    public function applyRow(User $admin, CourierReceiptImport $import, int $rowNumber): array
    {
        unset($admin, $rowNumber);

        $import->refresh();

        throw ValidationException::withMessages([
            'import' => [
                'Individual rows cannot be applied. Confirm import '.$import->uuid
                .' to process every matched row in one transaction.',
            ],
        ]);
    }

    /**
     * Paginated courier invoice import history for the Admin payment update page.
     *
     * Newest confirmed imports first. Previewed and failed uploads stay off this list.
     * Does not expose private storage paths.
     *
     * @return LengthAwarePaginator<int, CourierReceiptImport>
     */
    public function paginateImportHistory(int $perPage = 15): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));

        return CourierReceiptImport::query()
            ->with(['courierService.courier'])
            ->where('status', CourierReceiptImportStatus::CONFIRMED)
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->fragment('courier-import-history');
    }

    /**
     * Read-only HTML summary payload for a courier receipt import.
     *
     * @return array<string, mixed>
     */
    public function getSummaryPageData(User $admin, CourierReceiptImport $import): array
    {
        unset($admin); // Admin authorization is enforced at the HTTP layer.

        $import = $import->fresh([
            'rows',
            'courierService.courier',
            'uploader.profile',
            'confirmer.profile',
        ]);

        if ($import === null) {
            throw ValidationException::withMessages([
                'import' => ['The courier receipt import was not found.'],
            ]);
        }

        $preview = $this->serializePreview($import);
        $confirmationSummary = is_array($import->confirmation_summary)
            ? $import->confirmation_summary
            : [];

        return [
            'import' => $import,
            'preview' => $preview['import'],
            'rows' => $preview['rows'],
            'confirmation' => [
                'delivered_count' => (int) ($confirmationSummary['delivered_count'] ?? 0),
                'returned_count' => (int) ($confirmationSummary['returned_count'] ?? 0),
                'skipped_count' => (int) ($confirmationSummary['skipped_count'] ?? 0),
                'ineligible_count' => (int) ($confirmationSummary['ineligible_count'] ?? 0),
            ],
            'uploader_label' => $this->formatUserLabel($import->uploader),
            'confirmer_label' => $this->formatUserLabel($import->confirmer),
        ];
    }

    /**
     * Resolve the original uploaded invoice for download from the import's stored_path.
     * Never accepts a client-provided filesystem path or filename as the storage path.
     *
     * @return array{absolute_path: string, download_filename: string}|null
     */
    public function resolveOriginalInvoiceDownload(CourierReceiptImport $import): ?array
    {
        $storedPath = trim((string) ($import->stored_path ?? ''));

        if (! $this->isSafePrivateStoredRelativePath($storedPath)) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($storedPath)) {
            return null;
        }

        $absolutePath = $disk->path($storedPath);
        $realPath = realpath($absolutePath);
        $diskRoot = realpath($disk->path(''));

        if ($realPath === false || $diskRoot === false || ! is_file($realPath)) {
            return null;
        }

        $diskRootPrefix = rtrim(str_replace('\\', '/', $diskRoot), '/').'/';
        $normalizedRealPath = str_replace('\\', '/', $realPath);

        if (! str_starts_with($normalizedRealPath, $diskRootPrefix)
            && $normalizedRealPath !== rtrim($diskRootPrefix, '/')) {
            return null;
        }

        return [
            'absolute_path' => $realPath,
            'download_filename' => $this->safeDownloadFilename(
                (string) ($import->original_filename ?? ''),
                (string) ($import->file_type ?? '')
            ),
        ];
    }

    /**
     * @return array{
     *     row_number: int,
     *     match_result: string,
     *     outcome: string,
     *     order_id: int|null,
     *     order_number: string|null,
     *     message: string
     * }
     */
    private function confirmRow(User $admin, CourierReceiptImport $import, CourierReceiptImportRow $row): array
    {
        $matchResult = $row->match_result instanceof CourierReceiptImportRowMatchResult
            ? $row->match_result
            : CourierReceiptImportRowMatchResult::tryFrom((string) $row->getRawOriginal('match_result'));

        $matchValue = $matchResult?->value ?? (string) $row->getRawOriginal('match_result');

        if ($this->reportOutcome($row) === CourierReportOutcomeClassifier::RETURNED) {
            return $this->confirmReturnRow($admin, $import, $row, $matchValue);
        }

        $application = $this->remittanceApplications->apply($admin, $import, $row);

        $outcome = match ($application['outcome']) {
            'applied', 'already_applied' => 'delivered',
            'ineligible' => 'ineligible',
            default => 'skipped',
        };

        return [
            'row_number' => (int) $row->row_number,
            'match_result' => $matchValue,
            'order_id' => $application['order_id'],
            'order_number' => $application['order_number'] ?? $row->matched_order_number,
            'application_id' => $application['application_id'] ?? null,
            'outcome' => $outcome,
            'message' => $application['message'],
        ];
    }

    /**
     * @return array{
     *     row_number: int,
     *     match_result: string,
     *     outcome: string,
     *     order_id: int|null,
     *     order_number: string|null,
     *     message: string
     * }
     */
    private function confirmReturnRow(
        User $admin,
        CourierReceiptImport $import,
        CourierReceiptImportRow $row,
        string $matchValue,
    ): array {
        $base = [
            'row_number' => (int) $row->row_number,
            'match_result' => $matchValue,
            'order_id' => $row->matched_order_id !== null ? (int) $row->matched_order_id : null,
            'order_number' => $row->matched_order_number,
        ];

        if ($matchValue !== CourierReceiptImportRowMatchResult::MATCHED->value) {
            return $base + [
                'outcome' => $matchValue === CourierReceiptImportRowMatchResult::INVALID->value ? 'ineligible' : 'skipped',
                'message' => 'Return row is '.$matchValue.' and was not processed.',
            ];
        }

        $order = Order::query()->find((int) $row->matched_order_id);
        if ($order === null) {
            return $base + [
                'outcome' => 'ineligible',
                'order_id' => null,
                'message' => 'Matched order no longer exists. The return was not processed.',
            ];
        }

        $result = $this->returns->markReturnedFromCourierReport($order, (int) $admin->id);

        $row->forceFill([
            'applied_at' => now(),
            'applied_by' => (int) $admin->id,
            'message' => $result['already_returned']
                ? 'Return already recorded. Stock and fine were not applied again. Import '.$import->uuid.'.'
                : 'Returned through the courier report on import '.$import->uuid.'.',
        ])->save();

        return $base + [
            'outcome' => 'returned',
            'order_id' => $result['id'],
            'message' => $result['already_returned']
                ? 'Order was already RETURNED. No further stock or fine change was made.'
                : 'Order transitioned DISPATCHED → RETURNED.',
        ];
    }

    private function reportOutcome(CourierReceiptImportRow $row): string
    {
        $fields = $row->remittance_provided_fields;
        $outcome = is_array($fields) ? ($fields['report_outcome'] ?? null) : null;

        return $outcome === CourierReportOutcomeClassifier::RETURNED
            ? CourierReportOutcomeClassifier::RETURNED
            : CourierReportOutcomeClassifier::DELIVERED;
    }

    private function persistConfirmationFailure(int $importId, Throwable $exception): void
    {
        try {
            CourierReceiptImport::query()
                ->whereKey($importId)
                ->where('status', CourierReceiptImportStatus::PREVIEWED->value)
                ->update([
                    'failure_reason' => mb_substr($this->safeFailureMessage($exception), 0, 2000),
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // Best-effort diagnostics only; the confirmation transaction already rolled back.
        }
    }

    private function safeFailureMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? $message : 'Unexpected confirmation failure.';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePreview(CourierReceiptImport $import): array
    {
        $import->loadMissing(['rows.remittanceApplication', 'courierService.courier']);

        $courierName = $import->courierService?->courier?->name
            ?? $import->courierService?->name
            ?? '—';

        $orderIds = $import->rows
            ->pluck('matched_order_id')
            ->filter(static fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $shipmentIds = $import->rows
            ->pluck('matched_shipment_id')
            ->filter(static fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        /** @var \Illuminate\Support\Collection<int, Order> $orders */
        $orders = $orderIds === []
            ? collect()
            : Order::query()
                ->with('resellerCompany')
                ->whereIn('id', $orderIds)
                ->get()
                ->keyBy(static fn (Order $order): int => (int) $order->id);

        /** @var \Illuminate\Support\Collection<int, Shipment> $shipments */
        $shipments = $shipmentIds === []
            ? collect()
            : Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->get()
                ->keyBy(static fn (Shipment $shipment): int => (int) $shipment->id);

        $importConfirmed = $import->status === CourierReceiptImportStatus::CONFIRMED;

        $rows = $import->rows
            ->sortBy('row_number')
            ->values()
            ->map(function (CourierReceiptImportRow $row) use ($courierName, $orders, $shipments, $import, $importConfirmed): array {
                $result = $row->match_result;
                $resultValue = $result?->value ?? (string) $row->getRawOriginal('match_result');
                $waybill = $row->normalized_tracking_number ?? $row->raw_tracking_number ?? '—';

                $order = null;
                if ($row->matched_order_id !== null && (int) $row->matched_order_id > 0) {
                    $order = $orders->get((int) $row->matched_order_id);
                }

                $shipment = null;
                if ($row->matched_shipment_id !== null && (int) $row->matched_shipment_id > 0) {
                    $shipment = $shipments->get((int) $row->matched_shipment_id);
                }

                $orderNumber = '—';
                $resellerCompanyName = '—';
                $orderAmount = null;
                $courierCharge = null;
                $courierStatus = null;
                $liveStatus = null;

                if ($order instanceof Order) {
                    $orderNumber = (string) ($order->order_number ?: ($row->matched_order_number ?? '—'));
                    $resellerName = trim((string) ($order->resellerCompany?->name ?? ''));
                    $resellerCompanyName = $resellerName !== '' ? $resellerName : '—';
                    $orderAmount = $this->formatMoney($order->customer_payable_amount);
                    // Prefer shipment snapshot saved at booking; fall back to order.courier_fee_amount.
                    // Never use invoice delivery_charge / collected_cod from the import row.
                    $chargeSource = $shipment?->courier_fee_snapshot ?? $order->courier_fee_amount;
                    $courierCharge = $this->formatMoney($chargeSource);
                    $liveStatus = is_object($order->status) ? $order->status->value : (string) $order->status;
                    $courierStatus = $importConfirmed
                        ? $liveStatus
                        : ($row->order_status_snapshot ?? $liveStatus);
                } elseif ($row->matched_order_number) {
                    $orderNumber = (string) $row->matched_order_number;
                    $courierStatus = $row->order_status_snapshot;
                }

                $application = $row->remittanceApplication;
                $reportOutcome = $this->reportOutcome($row);
                // Row apply is disabled. Confirmation is the only mutating action.
                $canApply = false;

                $orderOutcome = match ($resultValue) {
                    'MATCHED' => $importConfirmed
                        ? match ($liveStatus) {
                            OrderStatus::DELIVERED->value => 'Confirmed — DISPATCHED → DELIVERED',
                            OrderStatus::RETURNED->value => 'Confirmed — DISPATCHED → RETURNED',
                            default => 'Confirmed — no status transition (order was not eligible at confirmation)',
                        }
                        : ($reportOutcome === CourierReportOutcomeClassifier::RETURNED
                            ? 'Eligible for confirmation as RETURNED. Upload did not change the order.'
                            : 'Eligible for confirmation as DELIVERED. Upload did not change the order.'),
                    'ALREADY_APPLIED' => 'Already applied — previous remittance evidence kept',
                    'PREVIOUSLY_PROCESSED' => 'Already terminal — no order change',
                    'SKIPPED' => 'Skipped — no order change',
                    default => 'Review required — no order change',
                };

                return [
                    'row_number' => $row->row_number,
                    'waybill_number' => $waybill,
                    'tracking_number' => $waybill,
                    'order_number' => $orderNumber,
                    'reseller_company_name' => $resellerCompanyName,
                    'order_amount' => $orderAmount,
                    'courier_charge' => $courierCharge,
                    'courier_status' => $courierStatus,
                    'supplier' => $row->matched_supplier_label ?? '—',
                    'courier_service' => $courierName,
                    'result' => $result?->label() ?? $resultValue,
                    'match_result' => $resultValue,
                    'order_outcome' => $orderOutcome,
                    'message' => $row->message,
                    'can_apply' => $canApply,
                    'file_gross_remittance' => $row->gross_remittance_amount !== null
                        ? (string) $row->gross_remittance_amount
                        : null,
                    'file_courier_charge' => $row->courier_charge_amount !== null
                        ? (string) $row->courier_charge_amount
                        : null,
                    'file_net_remittance' => $row->net_remittance_amount !== null
                        ? (string) $row->net_remittance_amount
                        : null,
                    'remittance_currency' => $row->remittance_currency,
                    'remittance_business_date' => $row->remittance_business_date?->toDateString(),
                    'applied_at' => $row->applied_at?->toIso8601String(),
                    'application_uuid' => $application?->uuid,
                    // Import-row financials retained for non-delivery-status formats only;
                    // UI must not present these as Feeder order amount / courier charge.
                    'collected_cod' => $row->collected_cod,
                    'delivery_charge' => $row->delivery_charge,
                    'order_status' => $courierStatus ?? $row->order_status_snapshot,
                ];
            })
            ->all();

        $skippedCount = $import->rows
            ->filter(static fn (CourierReceiptImportRow $row): bool => $row->match_result === CourierReceiptImportRowMatchResult::SKIPPED)
            ->count();

        $isDeliveryStatusImport = in_array($import->format_id, [
            RoyalExpressInvoiceParser::FORMAT_ID,
            TransExpressInvoiceParser::FORMAT_ID,
            FardarInvoiceParser::FORMAT_ID,
        ], true)
            || (! $import->file_has_cod_amounts && ! $import->file_has_delivery_charges);

        $confirmationSummary = is_array($import->confirmation_summary)
            ? $import->confirmation_summary
            : [];

        return [
            'import' => [
                'id' => $import->uuid,
                'status' => $import->status?->value ?? (string) $import->getRawOriginal('status'),
                'format_id' => $import->format_id,
                'original_filename' => $import->original_filename,
                'file_type' => $import->file_type,
                'courier_service_id' => $import->courierService?->uuid,
                'courier_service_name' => $courierName,
                'created_at' => $import->created_at?->toIso8601String(),
                'confirmed_at' => $import->confirmed_at?->toIso8601String(),
                'confirmed_by' => $import->confirmed_by !== null ? (int) $import->confirmed_by : null,
                'confirmed_by_label' => $this->formatUserLabel($import->confirmer),
                'failure_reason' => $import->failure_reason,
                'delivery_status_import' => $isDeliveryStatusImport,
                'pagination' => [
                    'per_page' => 25,
                    'total_rows' => count($rows),
                ],
                'entered' => [
                    'order_count' => $import->entered_order_count,
                    'total_collected_cod' => (string) $import->entered_total_collected_cod,
                    'delivery_charge' => (string) $import->entered_delivery_charge,
                    'net_income' => (string) $import->entered_net_income,
                ],
                'summary' => [
                    'rows_in_file' => $import->file_row_count,
                    'matched' => $import->matched_count,
                    'unmatched' => $import->unmatched_count,
                    'ambiguous' => $import->ambiguous_count,
                    'duplicate' => $import->duplicate_count,
                    'previously_processed' => $import->previously_processed_count,
                    'service_mismatch' => $import->service_mismatch_count,
                    'invalid' => $import->invalid_count,
                    'already_applied' => $import->already_applied_count,
                    'skipped' => $skippedCount,
                ],
                'file_summary' => $this->fileSummaryBuilder->fromImport($import),
                'confirmation_summary' => $importConfirmed ? [
                    'delivered_count' => (int) ($confirmationSummary['delivered_count'] ?? 0),
                    'returned_count' => (int) ($confirmationSummary['returned_count'] ?? 0),
                    'skipped_count' => (int) ($confirmationSummary['skipped_count'] ?? 0),
                    'ineligible_count' => (int) ($confirmationSummary['ineligible_count'] ?? 0),
                ] : null,
                'reconciliation' => [
                    'file_has_cod_amounts' => $import->file_has_cod_amounts,
                    'file_has_delivery_charges' => $import->file_has_delivery_charges,
                    'imported_cod_total' => $import->imported_cod_total !== null
                        ? (string) $import->imported_cod_total
                        : null,
                    'imported_delivery_charge_total' => $import->imported_delivery_charge_total !== null
                        ? (string) $import->imported_delivery_charge_total
                        : null,
                    'order_count_matches' => $import->order_count_matches,
                    'cod_totals_match' => $import->cod_totals_match,
                    'delivery_charge_totals_match' => $import->delivery_charge_totals_match,
                    'net_income_consistent' => $import->net_income_consistent,
                    'totals_reconcile' => $import->totals_reconcile,
                    'notes' => $import->reconciliation_notes['notes'] ?? [],
                    'confirmation_blocked_reasons' => $import->reconciliation_notes['confirmation_blocked_reasons'] ?? [],
                    'confirmation_rules_next_stage' => $import->reconciliation_notes['confirmation_rules_next_stage'] ?? [],
                    'remittance_totals_by_currency' => $import->reconciliation_notes['remittance_totals_by_currency'] ?? [
                        'by_currency' => [],
                        'unspecified_currency_row_count' => 0,
                    ],
                ],
            ],
            'rows' => $rows,
            'mutated_orders' => false,
        ];
    }

    private function formatUserLabel(?User $user): string
    {
        if ($user === null) {
            return '—';
        }

        $user->loadMissing('profile');
        $first = trim((string) ($user->profile?->first_name ?? ''));
        $last = trim((string) ($user->profile?->last_name ?? ''));
        $name = trim($first.' '.$last);

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($user->email ?? ''));

        return $email !== '' ? $email : '—';
    }

    private function formatMoney(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function storeUpload(UploadedFile $file, int $adminId): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $directory = 'courier-receipt-imports/'.$adminId.'/'.now()->format('Y/m');
        $filename = (string) Str::uuid().'.'.$extension;

        return Storage::disk('local')->putFileAs($directory, $file, $filename);
    }

    private function safeOriginalFilename(UploadedFile $file): string
    {
        $name = basename(str_replace(["\0", '\\', '/'], '', (string) $file->getClientOriginalName()));
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'courier-payment-file';
        }

        return mb_substr($name, 0, 255);
    }

    /**
     * Defend against path traversal: only relative private-disk keys are allowed.
     */
    private function isSafePrivateStoredRelativePath(string $storedPath): bool
    {
        if ($storedPath === '' || str_contains($storedPath, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $storedPath);

        if (str_starts_with($normalized, '/')
            || preg_match('/^[a-zA-Z]:\//', $normalized) === 1
            || str_contains($normalized, '..')) {
            return false;
        }

        return true;
    }

    private function safeDownloadFilename(string $originalFilename, string $fileType): string
    {
        $name = basename(str_replace(["\0", '\\', '/'], '', $originalFilename));
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $extension = preg_replace('/[^a-z0-9]/', '', strtolower($fileType)) ?: 'bin';

            return 'courier-payment-invoice.'.$extension;
        }

        return mb_substr($name, 0, 255);
    }
}
