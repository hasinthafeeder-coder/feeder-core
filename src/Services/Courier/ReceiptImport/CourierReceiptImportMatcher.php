<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\OrderStatus;
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
        $rawCod = $parsed['collected_cod'];
        $rawCharge = $parsed['delivery_charge'];

        $cod = null;
        $charge = null;
        $moneyError = null;

        if ($rawCod !== null && trim((string) $rawCod) !== '') {
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
            'matched_order_id' => null,
            'matched_shipment_id' => null,
            'matched_supplier_id' => null,
            'matched_order_number' => null,
            'matched_supplier_label' => null,
            'order_status_snapshot' => null,
        ];

        if (array_key_exists('eligible_for_matching', $parsed) && $parsed['eligible_for_matching'] === false) {
            return array_merge($base, [
                'match_result' => CourierReceiptImportRowMatchResult::SKIPPED,
                'message' => (string) ($parsed['skip_reason']
                    ?? 'Row skipped — only DELIVERED invoice rows are processed.'),
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
            return $this->classifyMatchedShipment($base, $serviceMatches->first());
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
    private function classifyMatchedShipment(array $base, Shipment $shipment): array
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

        // array_merge (not +) so matched_* values overwrite the null placeholders in $base.
        $enriched = array_merge($base, [
            'matched_order_id' => (int) $order->id,
            'matched_shipment_id' => (int) $shipment->id,
            'matched_supplier_id' => (int) $shipment->supplier_id,
            'matched_order_number' => $order->order_number,
            'matched_supplier_label' => $supplierLabel,
            'order_status_snapshot' => $status->value,
        ]);

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

        return array_merge($enriched, [
            'match_result' => CourierReceiptImportRowMatchResult::MATCHED,
            'message' => 'Matched DISPATCHED order (preview only — no status change).',
        ]);
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
