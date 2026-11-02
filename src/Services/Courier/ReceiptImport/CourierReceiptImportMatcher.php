<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\CourierRemittanceApplication;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Models\User;
use Feeder\Core\Support\MoneyNormalizer;

/**
 * Exact tracking_number matching scoped to the selected courier service.
 *
 * Multi-supplier rows in one file are allowed. Never fuzzy-matches.
 */
class CourierReceiptImportMatcher
{
    public function __construct(
        private readonly MoneyNormalizer $money,
    ) {}

    /**
     * @param  list<array{
     *     row_number: int,
     *     tracking_number: string,
     *     order_number: string,
     *     collected_cod: string|null,
     *     delivery_charge: string|null,
     *     notes: string,
     *     invoice_status?: string,
     *     eligible_for_matching?: bool,
     *     skip_reason?: string|null
     * }>  $parsedRows
     * @return list<array<string, mixed>>
     */
    public function matchRows(CourierService $courierService, array $parsedRows): array
    {
        $seenTracking = [];
        $results = [];

        foreach ($parsedRows as $parsed) {
            $results[] = $this->matchOne($courierService, $parsed, $seenTracking);
        }

        return $results;
    }

    /**
     * @param  array{
     *     row_number: int,
     *     tracking_number: string,
     *     order_number: string,
     *     collected_cod: string|null,
     *     delivery_charge: string|null,
     *     notes: string,
     *     invoice_status?: string,
     *     eligible_for_matching?: bool,
     *     skip_reason?: string|null
     * }  $parsed
     * @param  array<string, true>  $seenTracking
     * @return array<string, mixed>
     */
    private function matchOne(CourierService $courierService, array $parsed, array &$seenTracking): array
    {
        $rawTracking = (string) ($parsed['tracking_number'] ?? '');
        $normalized = trim($rawTracking);
        $rawCod = $parsed['collected_cod'] ?? null;
        $rawCharge = $parsed['delivery_charge'] ?? null;
        $remittance = $this->normalizeRemittance($parsed);

        $cod = null;
        $charge = null;
        $moneyError = $remittance['error'];

        if ($moneyError === null && $rawCod !== null && trim((string) $rawCod) !== '') {
            $cod = $this->money->tryNormalizeNonNegative($rawCod);
            if ($cod === null) {
                $moneyError = 'Invalid collected_cod amount.';
            }
        }

        if ($moneyError === null && $rawCharge !== null && trim((string) $rawCharge) !== '') {
            $charge = $this->money->tryNormalizeNonNegative($rawCharge);
            if ($charge === null) {
                $moneyError = 'Invalid delivery_charge amount.';
            }
        }

        $base = [
            'row_number' => (int) $parsed['row_number'],
            'raw_tracking_number' => $rawTracking !== '' ? $rawTracking : null,
            'normalized_tracking_number' => $normalized !== '' ? $normalized : null,
            'raw_order_number' => ($parsed['order_number'] ?? '') !== '' ? (string) $parsed['order_number'] : null,
            'raw_collected_cod' => $rawCod !== null && trim((string) $rawCod) !== '' ? trim((string) $rawCod) : null,
            'raw_delivery_charge' => $rawCharge !== null && trim((string) $rawCharge) !== '' ? trim((string) $rawCharge) : null,
            'collected_cod' => $cod,
            'delivery_charge' => $charge,
            'gross_remittance_amount' => $remittance['gross_remittance_amount'],
            'courier_charge_amount' => $remittance['courier_charge_amount'],
            'net_remittance_amount' => $remittance['net_remittance_amount'],
            'remittance_currency' => $remittance['remittance_currency'],
            'remittance_business_date' => $remittance['remittance_business_date'],
            'remittance_provided_fields' => $this->providedFieldsWithOutcome(
                $remittance['remittance_provided_fields'],
                $parsed['report_outcome'] ?? null,
            ),
            'report_outcome' => is_string($parsed['report_outcome'] ?? null) ? $parsed['report_outcome'] : null,
            'matched_order_id' => null,
            'matched_shipment_id' => null,
            'matched_supplier_id' => null,
            'matched_order_number' => null,
            'matched_supplier_label' => null,
            'order_status_snapshot' => null,
        ];

        if (($parsed['status_ambiguous'] ?? false) === true) {
            return array_merge($base, [
                'match_result' => CourierReceiptImportRowMatchResult::INVALID,
                'message' => (string) ($parsed['skip_reason']
                    ?? 'Courier status is empty. The row was not treated as delivered or returned.'),
            ]);
        }

        if (array_key_exists('eligible_for_matching', $parsed) && $parsed['eligible_for_matching'] === false) {
            return array_merge($base, [
                'match_result' => CourierReceiptImportRowMatchResult::SKIPPED,
                'message' => (string) ($parsed['skip_reason']
                    ?? 'Row skipped.'),
            ]);
        }

        if ($moneyError !== null) {
            return array_merge($base, [
                'match_result' => CourierReceiptImportRowMatchResult::INVALID,
                'message' => $moneyError,
            ]);
        }

        if ($normalized === '') {
            return array_merge($base, [
                'match_result' => CourierReceiptImportRowMatchResult::INVALID,
                'message' => 'Tracking number is required.',
            ]);
        }

        if (isset($seenTracking[$normalized])) {
            return array_merge($base, [
                'match_result' => CourierReceiptImportRowMatchResult::DUPLICATE,
                'message' => 'Duplicate tracking number within this file.',
            ]);
        }

        $seenTracking[$normalized] = true;

        $serviceMatches = Shipment::query()
            ->where('tracking_number', $normalized)
            ->where('courier_service_id', $courierService->id)
            ->limit(3)
            ->get(['id', 'order_id', 'supplier_id', 'courier_service_id']);

        if ($serviceMatches->count() > 1) {
            return array_merge($base, [
                'match_result' => CourierReceiptImportRowMatchResult::AMBIGUOUS,
                'message' => 'Multiple shipments share this tracking number for the selected courier service.',
            ]);
        }

        if ($serviceMatches->count() === 1) {
            return $this->classifyMatchedShipment($courierService, $base, $serviceMatches->first());
        }

        $otherServiceExists = Shipment::query()
            ->where('tracking_number', $normalized)
            ->where('courier_service_id', '!=', $courierService->id)
            ->exists();

        if ($otherServiceExists) {
            return array_merge($base, [
                'match_result' => CourierReceiptImportRowMatchResult::SERVICE_MISMATCH,
                'message' => 'Tracking number exists but belongs to a different courier service.',
            ]);
        }

        return array_merge($base, [
            'match_result' => CourierReceiptImportRowMatchResult::UNMATCHED,
            'message' => 'No shipment found for this tracking number.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function classifyMatchedShipment(CourierService $courierService, array $base, Shipment $shipment): array
    {
        $order = Order::query()->find($shipment->order_id);
        if ($order === null) {
            return array_merge($base, [
                'match_result' => CourierReceiptImportRowMatchResult::UNMATCHED,
                'message' => 'Shipment has no associated order.',
            ]);
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::from((string) $order->status);

        $supplierLabel = $this->supplierLabel((int) $shipment->supplier_id);
        $tracking = (string) ($base['normalized_tracking_number'] ?? '');
        $existingApplication = $this->existingApplication((int) $order->id, (int) $courierService->id, $tracking);

        // array_merge (not +) so matched_* values overwrite the null placeholders in $base.
        $enriched = array_merge($base, [
            'matched_order_id' => (int) $order->id,
            'matched_shipment_id' => (int) $shipment->id,
            'matched_supplier_id' => (int) $shipment->supplier_id,
            'matched_order_number' => $order->order_number,
            'matched_supplier_label' => $supplierLabel,
            'order_status_snapshot' => $status->value,
        ]);

        if ($existingApplication !== null) {
            return array_merge($enriched, [
                'match_result' => CourierReceiptImportRowMatchResult::ALREADY_APPLIED,
                'message' => 'Remittance already applied by '.$existingApplication->uuid
                    .'. Previous financial evidence was not overwritten.',
            ]);
        }

        if ($status->isPostDispatchOutcome()) {
            return array_merge($enriched, [
                'match_result' => CourierReceiptImportRowMatchResult::PREVIOUSLY_PROCESSED,
                'message' => 'Order already has a terminal post-dispatch outcome ('.$status->value.').',
            ]);
        }

        if ($status === OrderStatus::CANCELLED) {
            return array_merge($enriched, [
                'match_result' => CourierReceiptImportRowMatchResult::INVALID,
                'message' => 'Order is CANCELLED and cannot receive courier payment confirmation.',
            ]);
        }

        if ($status !== OrderStatus::DISPATCHED) {
            return array_merge($enriched, [
                'match_result' => CourierReceiptImportRowMatchResult::INVALID,
                'message' => 'Order status is '.$status->value.'; only DISPATCHED orders are eligible.',
            ]);
        }

        $outcome = ($enriched['report_outcome'] ?? null) === CourierReportOutcomeClassifier::RETURNED
            ? CourierReportOutcomeClassifier::RETURNED
            : CourierReportOutcomeClassifier::DELIVERED;

        return array_merge($enriched, [
            'match_result' => CourierReceiptImportRowMatchResult::MATCHED,
            'report_outcome' => $outcome,
            'message' => $outcome === CourierReportOutcomeClassifier::RETURNED
                ? 'Matched DISPATCHED order for return (preview only — no status change).'
                : 'Matched DISPATCHED order for delivery (preview only — no status change).',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $provided
     * @return array<string, mixed>|null
     */
    private function providedFieldsWithOutcome(mixed $provided, mixed $outcome): ?array
    {
        if (! is_array($provided)) {
            $provided = [];
        }

        if (is_string($outcome) && $outcome !== '') {
            $provided['report_outcome'] = $outcome;
        }

        return $provided === [] ? null : $provided;
    }

    private function existingApplication(int $orderId, int $courierServiceId, string $tracking): ?CourierRemittanceApplication
    {
        if ($tracking === '') {
            return null;
        }

        return CourierRemittanceApplication::query()
            ->where(function ($query) use ($orderId, $courierServiceId, $tracking): void {
                $query->where('order_id', $orderId)
                    ->orWhere(function ($trackingQuery) use ($courierServiceId, $tracking): void {
                        $trackingQuery->where('courier_service_id', $courierServiceId)
                            ->where('normalized_tracking_number', $tracking);
                    });
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{
     *     error: string|null,
     *     gross_remittance_amount: string|null,
     *     courier_charge_amount: string|null,
     *     net_remittance_amount: string|null,
     *     remittance_currency: string|null,
     *     remittance_business_date: string|null,
     *     remittance_provided_fields: array<string, mixed>|null
     * }
     */
    private function normalizeRemittance(array $parsed): array
    {
        $provided = $parsed['remittance_provided_fields'] ?? null;
        if (! is_array($provided)) {
            $provided = null;
        }

        $empty = [
            'error' => null,
            'gross_remittance_amount' => null,
            'courier_charge_amount' => null,
            'net_remittance_amount' => null,
            'remittance_currency' => null,
            'remittance_business_date' => null,
            'remittance_provided_fields' => $provided,
        ];

        if (! array_key_exists('remittance_gross', $parsed)
            && ! array_key_exists('remittance_charge', $parsed)
            && ! array_key_exists('remittance_net', $parsed)
            && ! array_key_exists('remittance_currency', $parsed)
            && ! array_key_exists('remittance_business_date', $parsed)
        ) {
            return $empty;
        }

        $grossRaw = $parsed['remittance_gross'] ?? null;
        $chargeRaw = $parsed['remittance_charge'] ?? null;
        $netRaw = $parsed['remittance_net'] ?? null;
        $netExplicit = (bool) ($parsed['remittance_net_explicit'] ?? false);
        $currencyRaw = $parsed['remittance_currency'] ?? null;
        $dateRaw = $parsed['remittance_business_date'] ?? null;

        $gross = null;
        if ($this->present($grossRaw)) {
            $gross = $this->money->tryNormalizeNonNegative($grossRaw);
            if ($gross === null) {
                $empty['error'] = 'Invalid remittance amount.';

                return $empty;
            }
        }

        $charge = null;
        if ($this->present($chargeRaw)) {
            $charge = $this->money->tryNormalizeNonNegative($chargeRaw);
            if ($charge === null) {
                $empty['error'] = 'Invalid courier charge.';

                return $empty;
            }
        }

        $net = null;
        if ($netExplicit && $this->present($netRaw)) {
            $net = $this->money->tryNormalizeAmount($netRaw, true);
            if ($net === null) {
                $empty['error'] = 'Invalid net remittance amount.';

                return $empty;
            }
        } elseif (! $netExplicit && $gross !== null && $charge !== null) {
            $net = $this->money->subtract($gross, $charge);
        }

        $currency = null;
        if ($this->present($currencyRaw)) {
            $currency = strtoupper(trim((string) $currencyRaw));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $empty['error'] = 'Invalid remittance currency.';

                return $empty;
            }
        }

        $businessDate = null;
        if ($this->present($dateRaw)) {
            $businessDate = $this->normalizeBusinessDate((string) $dateRaw);
            if ($businessDate === null) {
                $empty['error'] = 'Invalid remittance business date.';

                return $empty;
            }
        }

        $empty['gross_remittance_amount'] = $gross;
        $empty['courier_charge_amount'] = $charge;
        $empty['net_remittance_amount'] = $net;
        $empty['remittance_currency'] = $currency;
        $empty['remittance_business_date'] = $businessDate;

        return $empty;
    }

    private function present(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function normalizeBusinessDate(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-m-d', 'Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed instanceof \DateTimeImmutable && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    private function supplierLabel(int $supplierId): string
    {
        $user = User::query()->with(['company', 'profile'])->find($supplierId);
        if ($user === null) {
            return 'Supplier #'.$supplierId;
        }

        $companyName = trim((string) ($user->company?->name ?? ''));
        if ($companyName !== '') {
            return $companyName;
        }

        $display = trim(
            trim((string) ($user->profile?->first_name ?? ''))
            .' '
            .trim((string) ($user->profile?->last_name ?? ''))
        );
        if ($display !== '') {
            return $display;
        }

        return $user->email ? (string) $user->email : 'Supplier #'.$supplierId;
    }
}
