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
use Feeder\Core\Services\Order\OrderStatusService;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Admin courier invoice upload → reconciliation preview → confirmation.
 *
 * Preview never mutates order status. Confirmation may only transition eligible
 * DISPATCHED orders to DELIVERED via OrderStatusService and persist import
 * processing results. It must not alter totals, courier charges, stock,
 * payment statuses, or settlements.
 */
class CourierReceiptImportService
{
    public function __construct(
        private readonly CourierReceiptInvoiceParserResolver $parserResolver,
        private readonly CourierReceiptImportMatcher $matcher,
        private readonly CourierReceiptImportReconciler $reconciler,
        private readonly MoneyNormalizer $money,
        private readonly OrderStatusService $orderStatusService,
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
            ->where('status', CourierReceiptImportStatus::PREVIEWED)
            ->first();

        if ($existing !== null) {
            return $this->serializePreview($existing->fresh(['rows', 'courierService.courier']));
        }

        // Parse before any persistence / storage write (no DB locks during parse).
        $parsed = $parser->parse($file);
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
                    'file_has_cod_amounts' => (bool) $parsed['file_has_cod_amounts'],
                    'file_has_delivery_charges' => (bool) $parsed['file_has_delivery_charges'],
                    'imported_cod_total' => $reconciliation['imported_cod_total'],
                    'imported_delivery_charge_total' => $reconciliation['imported_delivery_charge_total'],
                    'order_count_matches' => $reconciliation['order_count_matches'],
                    'cod_totals_match' => $reconciliation['cod_totals_match'],
                    'delivery_charge_totals_match' => $reconciliation['delivery_charge_totals_match'],
                    'net_income_consistent' => $reconciliation['net_income_consistent'],
                    'totals_reconcile' => $reconciliation['totals_reconcile'],
                    'reconciliation_notes' => $reconciliation['reconciliation_notes'],
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
                $skippedCount = 0;
                $ineligibleCount = 0;
                $rowOutcomes = [];

                foreach ($locked->rows->sortBy('row_number') as $row) {
                    $outcome = $this->confirmRow($admin, $locked, $row);
                    $rowOutcomes[] = $outcome;

                    match ($outcome['outcome']) {
                        'delivered' => $deliveredCount++,
                        'ineligible' => $ineligibleCount++,
                        default => $skippedCount++,
                    };
                }

                $locked->forceFill([
                    'status' => CourierReceiptImportStatus::CONFIRMED,
                    'confirmed_at' => now(),
                    'failure_reason' => null,
                ])->save();

                return [
                    'delivered_count' => $deliveredCount,
                    'skipped_count' => $skippedCount,
                    'ineligible_count' => $ineligibleCount,
                    'row_outcomes' => $rowOutcomes,
                ];
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->persistConfirmationFailure($importId, $exception);

            throw ValidationException::withMessages([
                'import' => [
                    'Confirmation failed and was rolled back. '.$this->safeFailureMessage($exception),
                ],
            ]);
        }

        $preview = $this->serializePreview(
            CourierReceiptImport::query()
                ->with(['rows', 'courierService.courier'])
                ->findOrFail($importId)
        );

        return [
            'import' => $preview['import'],
            'rows' => $preview['rows'],
            'mutated_orders' => $result['delivered_count'] > 0,
            'confirmation' => [
                'status' => CourierReceiptImportStatus::CONFIRMED->value,
                'delivered_count' => $result['delivered_count'],
                'skipped_count' => $result['skipped_count'],
                'ineligible_count' => $result['ineligible_count'],
                'row_outcomes' => $result['row_outcomes'],
            ],
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

        $base = [
            'row_number' => (int) $row->row_number,
            'match_result' => $matchValue,
            'order_id' => $row->matched_order_id !== null ? (int) $row->matched_order_id : null,
            'order_number' => $row->matched_order_number,
        ];

        if ($matchResult !== CourierReceiptImportRowMatchResult::MATCHED) {
            return array_merge($base, [
                'outcome' => 'skipped',
                'message' => 'Row match result is '.$matchValue.'; no delivery transition applied.',
            ]);
        }

        $orderId = (int) ($row->matched_order_id ?? 0);
        $shipmentId = (int) ($row->matched_shipment_id ?? 0);
        $tracking = trim((string) ($row->normalized_tracking_number ?? ''));

        if ($orderId <= 0 || $shipmentId <= 0 || $tracking === '') {
            return array_merge($base, [
                'outcome' => 'ineligible',
                'message' => 'Stored match evidence is incomplete; no delivery transition applied.',
            ]);
        }

        $order = Order::query()->find($orderId);
        if ($order === null) {
            return array_merge($base, [
                'outcome' => 'ineligible',
                'message' => 'Matched order no longer exists; no delivery transition applied.',
            ]);
        }

        $shipment = Shipment::query()->find($shipmentId);
        if ($shipment === null) {
            return array_merge($base, [
                'outcome' => 'ineligible',
                'order_number' => $order->order_number,
                'message' => 'Matched shipment no longer exists; no delivery transition applied.',
            ]);
        }

        if ((int) $shipment->order_id !== (int) $order->id) {
            return array_merge($base, [
                'outcome' => 'ineligible',
                'order_number' => $order->order_number,
                'message' => 'Shipment no longer belongs to the matched order; no delivery transition applied.',
            ]);
        }

        if ((int) $shipment->courier_service_id !== (int) $import->courier_service_id) {
            return array_merge($base, [
                'outcome' => 'ineligible',
                'order_number' => $order->order_number,
                'message' => 'Shipment courier service no longer matches this import; no delivery transition applied.',
            ]);
        }

        if (trim((string) $shipment->tracking_number) !== $tracking) {
            return array_merge($base, [
                'outcome' => 'ineligible',
                'order_number' => $order->order_number,
                'message' => 'Shipment tracking number no longer matches import evidence; no delivery transition applied.',
            ]);
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::from((string) $order->status);

        if ($status !== OrderStatus::DISPATCHED) {
            return array_merge($base, [
                'outcome' => 'ineligible',
                'order_number' => $order->order_number,
                'message' => 'Order is '.$status->value.' at confirmation; only DISPATCHED orders may be delivered.',
            ]);
        }

        $this->orderStatusService->transition(
            $order,
            OrderStatus::DELIVERED,
            (int) $admin->id,
            null,
            'Courier receipt import confirmation ('.$import->uuid.')',
            null,
        );

        return array_merge($base, [
            'outcome' => 'delivered',
            'order_number' => $order->order_number,
            'message' => 'Order transitioned DISPATCHED → DELIVERED.',
        ]);
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
        $import->loadMissing(['rows', 'courierService.courier']);

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
            ->map(function (CourierReceiptImportRow $row) use ($courierName, $orders, $shipments, $importConfirmed): array {
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

                $orderOutcome = match ($resultValue) {
                    'MATCHED' => $importConfirmed
                        ? (($order instanceof Order && (is_object($order->status) ? $order->status->value : (string) $order->status) === OrderStatus::DELIVERED->value)
                            ? 'Confirmed — DISPATCHED → DELIVERED'
                            : 'Confirmed — no delivery transition (order was not eligible at confirmation)')
                        : 'Would become DISPATCHED → DELIVERED on confirmation (not applied in preview)',
                    'PREVIOUSLY_PROCESSED' => 'Already terminal — no order change',
                    'SKIPPED' => 'Skipped non-DELIVERED status — no order change',
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
                    'skipped' => $skippedCount,
                ],
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
                ],
            ],
            'rows' => $rows,
            'mutated_orders' => false,
        ];
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
}
