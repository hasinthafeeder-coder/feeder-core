<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\CourierReceiptImport;
use Feeder\Core\Models\CourierReceiptImportRow;
use Feeder\Core\Models\CourierRemittanceApplication;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderStatusHistory;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Services\Order\OrderStatusService;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies one matched remittance row.
 *
 * Upload and waybill matching do not change order status. A successful apply
 * transitions an eligible DISPATCHED order to DELIVERED and stores immutable
 * remittance evidence. It does not mark supplier or reseller settlements paid
 * and does not calculate reseller earnings.
 */
class CourierRemittanceApplicationService
{
    public function __construct(
        private readonly OrderStatusService $orderStatusService,
        private readonly FinancialDailySummaryUpdater $financialDailySummaries,
        private readonly MarketBusinessDateResolver $businessDates = new MarketBusinessDateResolver(),
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     message: string,
     *     order_id: int|null,
     *     order_number: string|null,
     *     application_id: int|null,
     *     application_uuid: string|null,
     *     mutated: bool
     * }
     */
    public function apply(User $admin, CourierReceiptImport $import, CourierReceiptImportRow $row): array
    {
        // Confirmation already holds one transaction and the import row lock.
        // A nested transaction and a second import lock on every row is what
        // pushes large files past PHP's execution limit.
        $joinCallerTransaction = DB::transactionLevel() > 0;

        $run = function () use ($admin, $import, $row, $joinCallerTransaction): array {
            /** @var CourierReceiptImport $lockedImport */
            $lockedImport = $joinCallerTransaction
                ? $import
                : CourierReceiptImport::query()
                    ->whereKey($import->id)
                    ->lockForUpdate()
                    ->firstOrFail();

            /** @var CourierReceiptImportRow $lockedRow */
            $lockedRow = CourierReceiptImportRow::query()
                ->whereKey($row->id)
                ->where('courier_receipt_import_id', $lockedImport->id)
                ->lockForUpdate()
                ->firstOrFail();

            $matchResult = $this->matchResult($lockedRow);
            $base = [
                'order_id' => $lockedRow->matched_order_id !== null ? (int) $lockedRow->matched_order_id : null,
                'order_number' => $lockedRow->matched_order_number,
                'application_id' => null,
                'application_uuid' => null,
                'mutated' => false,
            ];

            $owned = $this->applicationForRow((int) $lockedRow->id);
            if ($owned !== null) {
                return array_merge($base, [
                    'outcome' => 'already_applied',
                    'message' => 'This remittance row was already applied ('.$owned->uuid.'). No further change was made.',
                    'order_id' => (int) $owned->order_id,
                    'application_id' => (int) $owned->id,
                    'application_uuid' => (string) $owned->uuid,
                ]);
            }

            $importStatus = $lockedImport->status instanceof CourierReceiptImportStatus
                ? $lockedImport->status
                : CourierReceiptImportStatus::tryFrom((string) $lockedImport->status);

            if ($importStatus !== CourierReceiptImportStatus::PREVIEWED) {
                throw ValidationException::withMessages([
                    'import' => ['Only a PREVIEWED import can apply remittance rows.'],
                ]);
            }

            $blocked = $lockedImport->reconciliation_notes['confirmation_blocked_reasons'] ?? [];
            if (! is_array($blocked)) {
                $blocked = [];
            }
            if ($blocked !== []) {
                throw ValidationException::withMessages([
                    'import' => [
                        'This import is blocked ('.implode(', ', $blocked).'). No order was changed.',
                    ],
                ]);
            }

            if ($this->reportOutcome($lockedRow) === CourierReportOutcomeClassifier::RETURNED) {
                return array_merge($base, [
                    'outcome' => 'ineligible',
                    'message' => 'This row is a return. Delivery remittance was not applied.',
                ]);
            }

            if ($matchResult !== CourierReceiptImportRowMatchResult::MATCHED) {
                return array_merge($base, [
                    'outcome' => $matchResult === CourierReceiptImportRowMatchResult::ALREADY_APPLIED
                        ? 'blocked'
                        : ($matchResult === CourierReceiptImportRowMatchResult::INVALID ? 'ineligible' : 'skipped'),
                    'message' => 'Row is '.($matchResult?->value ?? 'unrecognized').' and was not applied.',
                ]);
            }

            $eligibility = $this->assertEligible($lockedImport, $lockedRow);
            if ($eligibility['outcome'] !== 'eligible') {
                return array_merge($base, $eligibility, [
                    'mutated' => false,
                ]);
            }

            /** @var Order $order */
            $order = $eligibility['order'];
            /** @var Shipment $shipment */
            $shipment = $eligibility['shipment'];

            $conflict = $this->conflictingApplication(
                (int) $order->id,
                (int) $lockedImport->courier_service_id,
                trim((string) $lockedRow->normalized_tracking_number),
            );

            if ($conflict !== null) {
                $lockedRow->forceFill([
                    'match_result' => CourierReceiptImportRowMatchResult::ALREADY_APPLIED,
                    'message' => 'Remittance already applied by '.$conflict->uuid
                        .'. Previous financial evidence was not overwritten.',
                ])->save();
                $this->syncAlreadyAppliedCount($lockedImport);

                return array_merge($base, [
                    'outcome' => 'blocked',
                    'message' => 'Not applied. Remittance '.$conflict->uuid.' already records this order.',
                    'order_id' => (int) $order->id,
                    'order_number' => $order->order_number,
                    'application_id' => (int) $conflict->id,
                    'application_uuid' => (string) $conflict->uuid,
                ]);
            }

            $before = $order->status instanceof OrderStatus
                ? $order->status
                : OrderStatus::from((string) $order->status);

            $this->orderStatusService->transition(
                $order,
                OrderStatus::DELIVERED,
                (int) $admin->id,
                null,
                'Courier remittance row '.$lockedRow->id.' on import '.$lockedImport->uuid,
                null,
            );

            $history = OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('to_status', OrderStatus::DELIVERED->value)
                ->orderByDesc('id')
                ->first();

            $appliedAt = now();
            // Native courier invoices often omit a remittance business-date column.
            // Fall back to the market-local apply day (same clock basis earnings use at
            // confirmation) so daily-summary income/courier contributions are not skipped.
            $businessDate = $this->resolveRemittanceBusinessDate($order, $lockedRow, $appliedAt);

            $application = CourierRemittanceApplication::query()->create([
                'courier_receipt_import_id' => (int) $lockedImport->id,
                'courier_receipt_import_row_id' => (int) $lockedRow->id,
                'order_id' => (int) $order->id,
                'shipment_id' => (int) $shipment->id,
                'courier_service_id' => (int) $lockedImport->courier_service_id,
                'normalized_tracking_number' => trim((string) $lockedRow->normalized_tracking_number),
                'gross_remittance_amount' => $lockedRow->gross_remittance_amount,
                'courier_charge_amount' => $lockedRow->courier_charge_amount,
                'net_remittance_amount' => $lockedRow->net_remittance_amount,
                'currency_code' => $lockedRow->remittance_currency,
                'business_date' => $businessDate,
                'order_status_before' => $before->value,
                'order_status_after' => OrderStatus::DELIVERED->value,
                'order_status_history_id' => $history?->id,
                'applied_by' => (int) $admin->id,
                'applied_at' => $appliedAt,
            ]);

            $lockedRow->forceFill([
                'applied_at' => $application->applied_at,
                'applied_by' => (int) $admin->id,
                'message' => 'Applied. Order transitioned DISPATCHED → DELIVERED by remittance '.$application->uuid.'.',
            ])->save();

            $this->financialDailySummaries->recordCourierRemittanceApplication($application);

            return array_merge($base, [
                'outcome' => 'applied',
                'message' => 'Remittance applied. Order transitioned DISPATCHED → DELIVERED.',
                'order_id' => (int) $order->id,
                'order_number' => $order->order_number,
                'application_id' => (int) $application->id,
                'application_uuid' => (string) $application->uuid,
                'mutated' => true,
            ]);
        };

        return $joinCallerTransaction ? $run() : DB::transaction($run);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertEligible(CourierReceiptImport $import, CourierReceiptImportRow $row): array
    {
        $orderId = (int) ($row->matched_order_id ?? 0);
        $shipmentId = (int) ($row->matched_shipment_id ?? 0);
        $tracking = trim((string) ($row->normalized_tracking_number ?? ''));

        if ($orderId <= 0 || $shipmentId <= 0 || $tracking === '') {
            return [
                'outcome' => 'ineligible',
                'message' => 'Stored match evidence is incomplete. The row was not applied.',
                'order_id' => $orderId > 0 ? $orderId : null,
                'order_number' => $row->matched_order_number,
            ];
        }

        $order = Order::query()->lockForUpdate()->find($orderId);
        if ($order === null) {
            return [
                'outcome' => 'ineligible',
                'message' => 'Matched order no longer exists. The row was not applied.',
                'order_id' => null,
                'order_number' => $row->matched_order_number,
            ];
        }

        $shipment = Shipment::query()->lockForUpdate()->find($shipmentId);
        if ($shipment === null || (int) $shipment->order_id !== (int) $order->id) {
            return [
                'outcome' => 'ineligible',
                'message' => 'Matched shipment is no longer consistent with the order. The row was not applied.',
                'order_id' => (int) $order->id,
                'order_number' => $order->order_number,
            ];
        }

        if ((int) $shipment->courier_service_id !== (int) $import->courier_service_id
            || trim((string) $shipment->tracking_number) !== $tracking) {
            return [
                'outcome' => 'ineligible',
                'message' => 'Shipment tracking or courier service no longer matches this row. The row was not applied.',
                'order_id' => (int) $order->id,
                'order_number' => $order->order_number,
            ];
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::from((string) $order->status);

        if ($status !== OrderStatus::DISPATCHED) {
            return [
                'outcome' => 'ineligible',
                'message' => 'Order is '.$status->value.'. Only DISPATCHED orders can be marked DELIVERED from remittance.',
                'order_id' => (int) $order->id,
                'order_number' => $order->order_number,
            ];
        }

        return [
            'outcome' => 'eligible',
            'order' => $order,
            'shipment' => $shipment,
            'order_id' => (int) $order->id,
            'order_number' => $order->order_number,
        ];
    }

    /**
     * Prefer the invoice remittance business date when present; otherwise the
     * market-local calendar day of apply. Never invents amounts.
     */
    private function resolveRemittanceBusinessDate(
        Order $order,
        CourierReceiptImportRow $row,
        \DateTimeInterface $appliedAt,
    ): ?string {
        $fromFile = $row->remittance_business_date;
        if ($fromFile instanceof \DateTimeInterface) {
            return $fromFile->format('Y-m-d');
        }

        if (is_string($fromFile) && preg_match('/^\d{4}-\d{2}-\d{2}/', trim($fromFile)) === 1) {
            return substr(trim($fromFile), 0, 10);
        }

        $market = $order->relationLoaded('market') && $order->market instanceof Market
            ? $order->market
            : Market::query()->find((int) $order->market_id);

        if ($market === null) {
            return null;
        }

        return $this->businessDates->businessDateString($market, $appliedAt);
    }

    private function applicationForRow(int $rowId): ?CourierRemittanceApplication
    {
        return CourierRemittanceApplication::query()
            ->where('courier_receipt_import_row_id', $rowId)
            ->first();
    }

    private function conflictingApplication(int $orderId, int $courierServiceId, string $tracking): ?CourierRemittanceApplication
    {
        return CourierRemittanceApplication::query()
            ->where(function ($query) use ($orderId, $courierServiceId, $tracking): void {
                $query->where('order_id', $orderId)
                    ->orWhere(function ($trackingQuery) use ($courierServiceId, $tracking): void {
                        $trackingQuery->where('courier_service_id', $courierServiceId)
                            ->where('normalized_tracking_number', $tracking);
                    });
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    private function matchResult(CourierReceiptImportRow $row): ?CourierReceiptImportRowMatchResult
    {
        return $row->match_result instanceof CourierReceiptImportRowMatchResult
            ? $row->match_result
            : CourierReceiptImportRowMatchResult::tryFrom((string) $row->getRawOriginal('match_result'));
    }

    private function reportOutcome(CourierReceiptImportRow $row): string
    {
        $fields = $row->remittance_provided_fields;
        $outcome = is_array($fields) ? ($fields['report_outcome'] ?? null) : null;

        return $outcome === CourierReportOutcomeClassifier::RETURNED
            ? CourierReportOutcomeClassifier::RETURNED
            : CourierReportOutcomeClassifier::DELIVERED;
    }

    private function syncAlreadyAppliedCount(CourierReceiptImport $import): void
    {
        $count = CourierReceiptImportRow::query()
            ->where('courier_receipt_import_id', $import->id)
            ->where('match_result', CourierReceiptImportRowMatchResult::ALREADY_APPLIED->value)
            ->count();

        $import->forceFill([
            'already_applied_count' => $count,
        ])->save();
    }
}
