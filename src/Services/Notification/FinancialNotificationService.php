<?php

namespace Feeder\Core\Services\Notification;

use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\CourierRemittanceApplication;
use Feeder\Core\Models\Notification;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ResellerEarningObligation;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Models\ResellerSettlementInvoiceLine;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes automated financial notifications without altering accounting rules.
 *
 * Failures are logged and never rethrown into order, remittance, or settlement
 * writers. Idempotency relies on stable source_event_key values.
 */
class FinancialNotificationService
{
    public function __construct(
        private readonly NotificationPublicationService $publisher,
    ) {}

    /**
     * Notify reseller and supplier after an order is remittance-confirmed DELIVERED.
     *
     * Returned / undelivered orders must not call this. Courier remittance edits
     * do not exist; retries reuse the same source_event_key.
     *
     * @return array{reseller: Notification|null, supplier: Notification|null}
     */
    public function notifyOrderDelivered(
        User $actor,
        Order $order,
        ?ResellerEarningObligation $obligation = null,
        ?CourierRemittanceApplication $application = null,
    ): array {
        return $this->safe('order_delivered', [
            'order_id' => $order->id,
        ], function () use ($actor, $order, $obligation, $application): array {
            $status = $order->status instanceof OrderStatus
                ? $order->status
                : OrderStatus::tryFrom((string) $order->status);

            if ($status !== OrderStatus::DELIVERED) {
                return ['reseller' => null, 'supplier' => null];
            }

            $order->loadMissing(['reseller', 'supplier']);

            $resellerNotification = null;
            $supplierNotification = null;

            if ($order->reseller_id) {
                $resellerNotification = $this->publisher->publishFinancialEvent(
                    $actor,
                    new PublishNotificationData(
                        type: NotificationType::ORDER_DELIVERED,
                        title: 'Order '.$order->order_number.' delivered',
                        body: $this->resellerDeliveryBody($order, $obligation, $application),
                        audienceType: NotificationAudienceType::SELECTED_RESELLER_USERS,
                        recipientUserIds: [(int) $order->reseller_id],
                        sourceEventType: 'finance.order_delivered.reseller',
                        sourceEventKey: 'finance.order_delivered.reseller:'.$order->id,
                    ),
                );
            }

            if ($order->supplier_id) {
                $supplierNotification = $this->publisher->publishFinancialEvent(
                    $actor,
                    new PublishNotificationData(
                        type: NotificationType::ORDER_DELIVERED,
                        title: 'Order '.$order->order_number.' delivered',
                        body: $this->supplierDeliveryBody($order, $obligation, $application),
                        audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
                        recipientUserIds: [(int) $order->supplier_id],
                        sourceEventType: 'finance.order_delivered.supplier',
                        sourceEventKey: 'finance.order_delivered.supplier:'.$order->id,
                    ),
                );
            }

            return [
                'reseller' => $resellerNotification,
                'supplier' => $supplierNotification,
            ];
        }) ?? ['reseller' => null, 'supplier' => null];
    }

    /**
     * When accounting refuses a silent rewrite, notify the reseller with an
     * explicit correction/review notice. Does not mutate earnings.
     */
    public function notifyUnsupportedDeliveryCorrection(
        User $actor,
        Order $order,
        ResellerEarningObligation $obligation,
        string $message,
    ): ?Notification {
        return $this->safe('financial_correction', [
            'order_id' => $order->id,
            'obligation_id' => $obligation->id,
        ], function () use ($actor, $order, $obligation, $message): ?Notification {
            if (! $order->reseller_id) {
                return null;
            }

            $fingerprint = substr(hash('sha256', $message.'|'.$obligation->gross_reseller_profit), 0, 12);

            return $this->publisher->publishFinancialEvent(
                $actor,
                new PublishNotificationData(
                    type: NotificationType::FINANCIAL_CORRECTION,
                    title: 'Financial review required for order '.$order->order_number,
                    body: "CORRECTION NOTICE (manual review required).\n"
                        ."Order: {$order->order_number}\n"
                        ."Preserved earning: {$obligation->gross_reseller_profit} {$obligation->currency_code_snapshot}\n"
                        .$message,
                    audienceType: NotificationAudienceType::SELECTED_RESELLER_USERS,
                    recipientUserIds: [(int) $order->reseller_id],
                    sourceEventType: 'finance.order_delivered.correction',
                    sourceEventKey: 'finance.order_delivered.correction:'.$order->id.':'.$fingerprint,
                ),
            );
        });
    }

    /**
     * Settlement "ready" maps to an existing LOCKED batch (no separate ready enum).
     *
     * @return list<Notification>
     */
    public function notifyResellerSettlementReady(User $actor, ResellerSettlementBatch $batch): array
    {
        return $this->safe('settlement_ready_reseller', [
            'batch_id' => $batch->id,
        ], function () use ($actor, $batch): array {
            if ($batch->status !== ResellerSettlementBatch::STATUS_LOCKED) {
                return [];
            }

            $batch->loadMissing('invoices');
            $created = [];

            foreach ($batch->invoices as $invoice) {
                if (! $invoice->reseller_id) {
                    continue;
                }

                $created[] = $this->publisher->publishFinancialEvent(
                    $actor,
                    new PublishNotificationData(
                        type: NotificationType::SETTLEMENT_READY,
                        title: 'Settlement ready: '.$invoice->invoice_number,
                        body: $this->resellerSettlementReadyBody($batch, $invoice),
                        audienceType: NotificationAudienceType::SELECTED_RESELLER_USERS,
                        recipientUserIds: [(int) $invoice->reseller_id],
                        sourceEventType: 'finance.settlement_ready.reseller_invoice',
                        sourceEventKey: 'finance.settlement_ready.reseller_invoice:'.$invoice->id,
                    ),
                );
            }

            return $created;
        }) ?? [];
    }

    /**
     * @return list<Notification>
     */
    public function notifySupplierSettlementReady(User $actor, SupplierSettlementBatch $batch): array
    {
        return $this->safe('settlement_ready_supplier', [
            'batch_id' => $batch->id,
        ], function () use ($actor, $batch): array {
            if ($batch->status !== SupplierSettlementBatch::STATUS_LOCKED) {
                return [];
            }

            $batch->loadMissing('invoices');
            $created = [];

            foreach ($batch->invoices as $invoice) {
                $supplierUser = $this->resolveSupplierCompanyOwner((int) $invoice->supplier_company_id);
                if ($supplierUser === null) {
                    continue;
                }

                $created[] = $this->publisher->publishFinancialEvent(
                    $actor,
                    new PublishNotificationData(
                        type: NotificationType::SETTLEMENT_READY,
                        title: 'Settlement ready: '.$invoice->invoice_number,
                        body: $this->supplierSettlementReadyBody($batch, $invoice),
                        audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
                        recipientUserIds: [(int) $supplierUser->id],
                        sourceEventType: 'finance.settlement_ready.supplier_invoice',
                        sourceEventKey: 'finance.settlement_ready.supplier_invoice:'.$invoice->id,
                    ),
                );
            }

            return $created;
        }) ?? [];
    }

    public function notifyResellerSettlementCompleted(
        User $actor,
        ResellerSettlementInvoice $invoice,
    ): ?Notification {
        return $this->safe('settlement_completed_reseller', [
            'invoice_id' => $invoice->id,
        ], function () use ($actor, $invoice): ?Notification {
            if (! in_array((string) $invoice->payment_status, ResellerSettlementInvoice::finalizedPaymentStatuses(), true)) {
                return null;
            }

            if (! $invoice->reseller_id) {
                return null;
            }

            return $this->publisher->publishFinancialEvent(
                $actor,
                new PublishNotificationData(
                    type: NotificationType::SETTLEMENT_COMPLETED,
                    title: 'Settlement completed: '.$invoice->invoice_number,
                    body: $this->resellerSettlementCompletedBody($invoice),
                    audienceType: NotificationAudienceType::SELECTED_RESELLER_USERS,
                    recipientUserIds: [(int) $invoice->reseller_id],
                    sourceEventType: 'finance.settlement_completed.reseller_invoice',
                    sourceEventKey: 'finance.settlement_completed.reseller_invoice:'.$invoice->id,
                ),
            );
        });
    }

    public function notifySupplierSettlementCompleted(
        User $actor,
        SupplierSettlementInvoice $invoice,
    ): ?Notification {
        return $this->safe('settlement_completed_supplier', [
            'invoice_id' => $invoice->id,
        ], function () use ($actor, $invoice): ?Notification {
            if ((string) $invoice->payment_status !== SupplierSettlementInvoice::PAYMENT_STATUS_PAID) {
                return null;
            }

            $supplierUser = $this->resolveSupplierCompanyOwner((int) $invoice->supplier_company_id);
            if ($supplierUser === null) {
                return null;
            }

            return $this->publisher->publishFinancialEvent(
                $actor,
                new PublishNotificationData(
                    type: NotificationType::SETTLEMENT_COMPLETED,
                    title: 'Settlement completed: '.$invoice->invoice_number,
                    body: $this->supplierSettlementCompletedBody($invoice),
                    audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
                    recipientUserIds: [(int) $supplierUser->id],
                    sourceEventType: 'finance.settlement_completed.supplier_invoice',
                    sourceEventKey: 'finance.settlement_completed.supplier_invoice:'.$invoice->id,
                ),
            );
        });
    }

    /**
     * Publish one daily summary per reseller/supplier with delivered earnings that day.
     *
     * Uses persisted ResellerEarningObligation rows only (authoritative). Returned
     * orders never create obligations, so they are excluded automatically.
     *
     * @return array{reseller_count: int, supplier_count: int}
     */
    public function publishDailySummaries(User $actor, Carbon|string $businessDate): array
    {
        $date = $businessDate instanceof Carbon
            ? $businessDate->toDateString()
            : Carbon::parse($businessDate)->toDateString();

        return $this->safe('daily_financial_summary', [
            'business_date' => $date,
        ], function () use ($actor, $date): array {
            $obligations = ResellerEarningObligation::query()
                ->with(['order'])
                ->whereDate('business_date', $date)
                ->orderBy('id')
                ->get();

            $resellerCount = 0;
            $supplierCount = 0;

            foreach ($obligations->groupBy(fn (ResellerEarningObligation $row) => (int) $row->reseller_id) as $resellerId => $rows) {
                if ((int) $resellerId <= 0 || $rows->isEmpty()) {
                    continue;
                }

                /** @var Collection<int, ResellerEarningObligation> $rows */
                $currency = (string) $rows->first()->currency_code_snapshot;
                $earnings = $this->sumMoney($rows->pluck('gross_reseller_profit'));
                $deliveredCount = $rows->count();
                $settlementStatus = $this->resellerSettlementStatusForObligations($rows);

                $this->publisher->publishFinancialEvent(
                    $actor,
                    new PublishNotificationData(
                        type: NotificationType::DAILY_FINANCIAL_SUMMARY,
                        title: 'Daily financial summary '.$date,
                        body: "Business date: {$date}\n"
                            ."Delivered orders: {$deliveredCount}\n"
                            ."Gross reseller earnings: {$earnings} {$currency}\n"
                            ."Settlement status: {$settlementStatus}",
                        audienceType: NotificationAudienceType::SELECTED_RESELLER_USERS,
                        recipientUserIds: [(int) $resellerId],
                        sourceEventType: 'finance.daily_summary.reseller',
                        sourceEventKey: 'finance.daily_summary.reseller:'.$resellerId.':'.$date,
                    ),
                );
                $resellerCount++;
            }

            $bySupplier = $obligations->groupBy(function (ResellerEarningObligation $row): int {
                return (int) ($row->order?->supplier_id ?? 0);
            });

            foreach ($bySupplier as $supplierId => $rows) {
                if ((int) $supplierId <= 0 || $rows->isEmpty()) {
                    continue;
                }

                /** @var Collection<int, ResellerEarningObligation> $rows */
                $currency = (string) $rows->first()->currency_code_snapshot;
                $supplierCost = $this->sumMoney($rows->pluck('supplier_cost_snapshot'));
                $deliveredCount = $rows->count();

                $this->publisher->publishFinancialEvent(
                    $actor,
                    new PublishNotificationData(
                        type: NotificationType::DAILY_FINANCIAL_SUMMARY,
                        title: 'Daily financial summary '.$date,
                        body: "Business date: {$date}\n"
                            ."Delivered orders: {$deliveredCount}\n"
                            ."Supplier cost (authoritative snapshots): {$supplierCost} {$currency}\n"
                            .'Settlement status: pending settlement generation or existing supplier invoices',
                        audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
                        recipientUserIds: [(int) $supplierId],
                        sourceEventType: 'finance.daily_summary.supplier',
                        sourceEventKey: 'finance.daily_summary.supplier:'.$supplierId.':'.$date,
                    ),
                );
                $supplierCount++;
            }

            return [
                'reseller_count' => $resellerCount,
                'supplier_count' => $supplierCount,
            ];
        }) ?? ['reseller_count' => 0, 'supplier_count' => 0];
    }

    /**
     * Run after the surrounding writer transaction commits.
     */
    public function afterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @param  array<string, mixed>  $context
     * @return T|null
     */
    private function safe(string $operation, array $context, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::error('Financial notification failed without blocking finance writers.', [
                'operation' => $operation,
                'context' => $context,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function resellerDeliveryBody(
        Order $order,
        ?ResellerEarningObligation $obligation,
        ?CourierRemittanceApplication $application,
    ): string {
        $lines = [
            'Order '.$order->order_number.' is DELIVERED.',
        ];

        if ($obligation !== null) {
            $lines[] = 'Gross reseller earning: '.$obligation->gross_reseller_profit
                .' '.$obligation->currency_code_snapshot;
            $lines[] = 'Business date: '.$obligation->business_date?->toDateString();
        }

        if ($application !== null) {
            $lines[] = 'Courier remittance net: '.$application->net_remittance_amount
                .' '.$application->currency_code;
        }

        return implode("\n", $lines);
    }

    private function supplierDeliveryBody(
        Order $order,
        ?ResellerEarningObligation $obligation,
        ?CourierRemittanceApplication $application,
    ): string {
        $lines = [
            'Order '.$order->order_number.' is DELIVERED.',
        ];

        if ($obligation !== null) {
            $lines[] = 'Supplier cost snapshot: '.$obligation->supplier_cost_snapshot
                .' '.$obligation->currency_code_snapshot;
        }

        if ($application !== null) {
            $lines[] = 'Courier remittance applied: '.$application->uuid;
        }

        return implode("\n", $lines);
    }

    private function resellerSettlementReadyBody(
        ResellerSettlementBatch $batch,
        ResellerSettlementInvoice $invoice,
    ): string {
        return "Settlement batch {$batch->batch_number} is LOCKED and ready for payment.\n"
            ."Invoice: {$invoice->invoice_number}\n"
            ."Net payable: {$invoice->net_payable} {$invoice->currency_code_snapshot}\n"
            ."Outstanding: {$invoice->outstanding_balance} {$invoice->currency_code_snapshot}";
    }

    private function supplierSettlementReadyBody(
        SupplierSettlementBatch $batch,
        SupplierSettlementInvoice $invoice,
    ): string {
        $net = $invoice->net_payable ?? $invoice->payable_total;

        return "Settlement batch {$batch->batch_number} is LOCKED and ready for payment.\n"
            ."Invoice: {$invoice->invoice_number}\n"
            ."Net payable: {$net} {$invoice->currency_code_snapshot}";
    }

    private function resellerSettlementCompletedBody(ResellerSettlementInvoice $invoice): string
    {
        return "Settlement invoice {$invoice->invoice_number} is completed.\n"
            ."Payment status: {$invoice->payment_status}\n"
            ."Amount paid: {$invoice->amount_paid} {$invoice->currency_code_snapshot}\n"
            ."Outstanding: {$invoice->outstanding_balance} {$invoice->currency_code_snapshot}";
    }

    private function supplierSettlementCompletedBody(SupplierSettlementInvoice $invoice): string
    {
        $net = $invoice->net_payable ?? $invoice->payable_total;

        return "Settlement invoice {$invoice->invoice_number} is completed.\n"
            ."Payment status: {$invoice->payment_status}\n"
            ."Net payable: {$net} {$invoice->currency_code_snapshot}";
    }

    private function resolveSupplierCompanyOwner(int $companyId): ?User
    {
        if ($companyId <= 0) {
            return null;
        }

        $company = Company::query()->find($companyId);
        if ($company === null || $company->owner_user_id === null) {
            return null;
        }

        return User::query()->find((int) $company->owner_user_id);
    }

    /**
     * @param  Collection<int, ResellerEarningObligation>  $rows
     */
    private function resellerSettlementStatusForObligations(Collection $rows): string
    {
        $obligationIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

        $line = ResellerSettlementInvoiceLine::query()
            ->with('invoice.batch')
            ->whereIn('earning_obligation_id', $obligationIds)
            ->orderByDesc('id')
            ->first();

        if ($line === null) {
            return 'not yet included in a settlement invoice';
        }

        $invoice = $line->invoice;
        if ($invoice === null) {
            return 'linked to a settlement line without invoice';
        }

        $batchStatus = $invoice->batch?->status ?? 'unknown';

        return 'invoice '.$invoice->invoice_number
            .' (payment '.$invoice->payment_status.', batch '.$batchStatus.')';
    }

    /**
     * @param  Collection<int, mixed>  $amounts
     */
    private function sumMoney(Collection $amounts): string
    {
        $total = 0.0;

        foreach ($amounts as $amount) {
            $total += (float) $amount;
        }

        return number_format($total, 2, '.', '');
    }
}
