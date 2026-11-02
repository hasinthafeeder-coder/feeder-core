<?php

namespace Feeder\Core\Services\Settlement;

use Carbon\CarbonImmutable;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderItem;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\SupplierSettlementInvoiceLine;
use Feeder\Core\Models\SupplierSettlementReturnedReference;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Feeder\Core\Support\MoneyNormalizer;
use Feeder\Core\Support\SupplierSettlementGenerationExceptionReport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates or rebuilds OPEN settlement batch financial snapshots.
 *
 * Repeatable and transactional: rebuilds reconcile existing OPEN batch data
 * rather than appending duplicates. Invoice/return exclusivity is enforced
 * under order_item row locks.
 *
 * Settlement writer lock order: market → batch → order item → dependents.
 *
 * Invalid historical unit_cost_snapshot values skip that candidate only —
 * they never roll back valid sibling payables and never use live variant cost.
 */
class SupplierSettlementGenerationService
{
    public function __construct(
        private readonly SupplierSettlementPayableEligibilityEvaluator $eligibility,
        private readonly MarketBusinessDateResolver $businessDates,
        private readonly MoneyNormalizer $money,
        private readonly SupplierSettlementNumberGenerator $numbers,
        private readonly FinancialDailySummaryUpdater $financialDailySummaries,
        private readonly SupplierSettlementTransferFeeSettingsService $transferFees,
    ) {}

    /**
     * @return array{
     *     batch: SupplierSettlementBatch,
     *     invoice_count: int,
     *     payable_line_count: int,
     *     returned_reference_count: int,
     *     payable_total: string,
     *     payable_totals_by_currency: array<string, string>,
     *     skipped_candidate_count: int,
     *     generation_exceptions: list<array<string, mixed>>
     * }
     */
    public function generate(SupplierSettlementBatch $batch): array
    {
        return DB::transaction(function () use ($batch) {
            $marketId = (int) ($batch->market_id
                ?? SupplierSettlementBatch::query()->whereKey($batch->id)->value('market_id'));

            $market = Market::query()->whereKey($marketId)->lockForUpdate()->firstOrFail();
            $market->loadMissing('currency');

            /** @var SupplierSettlementBatch $locked */
            $locked = SupplierSettlementBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->market_id !== (int) $market->id) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_MARKET_MISMATCH',
                    'Settlement batch market changed during generation locking.',
                    ['batch_id' => $locked->id],
                );
            }

            if ($locked->voided_at !== null) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_VOIDED',
                    'A voided settlement batch cannot be generated.',
                    ['batch_id' => $locked->id],
                );
            }

            if ($locked->status !== SupplierSettlementBatch::STATUS_OPEN) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_BATCH_NOT_OPEN',
                    'Only OPEN settlement batches can be generated or rebuilt.',
                    [
                        'batch_id' => $locked->id,
                        'status' => $locked->status,
                    ],
                );
            }

            $this->clearOpenBatchFinancials($locked);

            $collected = $this->eligibility->collectPayableCandidates($locked, $market);
            $payableCandidates = $collected['candidates'];
            /** @var list<SupplierSettlementGenerationExceptionReport> $exceptionReports */
            $exceptionReports = $collected['exceptions'];

            $invoiceCount = $this->persistPayableInvoices($locked, $market, $payableCandidates);
            $returnedCount = $this->persistReturnedReferences($locked, $market);

            $locked->refresh();
            $locked->load(['invoices.payableLines', 'returnedReferences']);

            $payableTotal = '0.00';
            $payableTotalsByCurrency = [];
            foreach ($locked->invoices as $invoice) {
                $payableTotal = $this->money->add($payableTotal, (string) $invoice->payable_total);
                $code = strtoupper((string) $invoice->currency_code_snapshot);
                $payableTotalsByCurrency[$code] = $this->money->add(
                    $payableTotalsByCurrency[$code] ?? '0.00',
                    (string) $invoice->payable_total,
                );
            }

            $generationExceptions = array_map(
                static fn (SupplierSettlementGenerationExceptionReport $report): array => $report->toArray(),
                $exceptionReports,
            );

            foreach ($generationExceptions as $exception) {
                Log::warning('supplier_settlement.generation_exception', $exception);
            }

            $this->financialDailySummaries->recordSupplierBatchGenerated($locked);

            return [
                'batch' => $locked,
                'invoice_count' => $invoiceCount,
                'payable_line_count' => $locked->invoices->sum(static fn ($invoice) => $invoice->payableLines->count()),
                'returned_reference_count' => $returnedCount,
                'payable_total' => $payableTotal,
                'payable_totals_by_currency' => $payableTotalsByCurrency,
                'skipped_candidate_count' => count($generationExceptions),
                'generation_exceptions' => $generationExceptions,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function persistPayableInvoices(SupplierSettlementBatch $batch, Market $market, array $candidates): int
    {
        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['supplier_company_id'].':'.$candidate['currency_id'];
            $grouped[$key][] = $candidate;
        }

        $invoiceCount = 0;

        foreach ($grouped as $group) {
            $accepted = [];

            foreach ($group as $candidate) {
                $inserted = $this->tryReservePayableItem((int) $candidate['order_item']->id, $candidate);
                if ($inserted !== null) {
                    $accepted[] = $inserted;
                }
            }

            if ($accepted === []) {
                continue;
            }

            $total = '0.00';
            foreach ($accepted as $line) {
                $total = $this->money->add($total, $line['line_payable']);
            }

            if (! $this->money->isPositive($total)) {
                continue;
            }

            $first = $accepted[0];
            $invoiceNumber = $this->numbers->ensureUniqueInvoiceNumber(
                $this->numbers->invoiceNumber(
                    $batch,
                    (int) $first['supplier_company_id'],
                    (string) $first['currency_code'],
                )
            );

            // One fee per supplier invoice (batch+supplier+currency), never per line.
            // Rebuild clears and recreates invoices, so the fee is not double-deducted.
            // Generation never marks PAID — fee deduction is not a payment.
            $transferFee = $this->transferFees->resolveForInvoice(
                $market,
                (string) $first['currency_code'],
            );
            $feeAmount = (string) $transferFee['amount'];
            $netPayable = $this->money->subtract($total, $feeAmount);
            if ((float) $netPayable < 0) {
                $netPayable = '0.00';
            }

            $invoice = SupplierSettlementInvoice::query()->create([
                'invoice_number' => $invoiceNumber,
                'batch_id' => $batch->id,
                'supplier_company_id' => $first['supplier_company_id'],
                'supplier_company_name_snapshot' => $first['supplier_company_name'],
                'currency_id' => $first['currency_id'],
                'currency_code_snapshot' => $first['currency_code'],
                'payable_total' => $total,
                'transfer_fee_amount' => $feeAmount,
                'transfer_fee_currency_code_snapshot' => $transferFee['currency_code'],
                'transfer_fee_status' => $transferFee['status'],
                'net_payable' => $netPayable,
                'payment_status' => SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID,
                'instruction_status' => SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
                'issued_at' => now(),
            ]);

            foreach ($accepted as $line) {
                $this->insertPayableLine($invoice, $batch, $line);
            }

            $invoiceCount++;
        }

        return $invoiceCount;
    }

    /**
     * Lock the order item, re-check exclusivity, and return the candidate when insert is permitted.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function tryReservePayableItem(int $orderItemId, array $candidate): ?array
    {
        // Caller already holds the batch transaction; keep order_item locks until commit.
        /** @var OrderItem $orderItem */
        $orderItem = OrderItem::query()->whereKey($orderItemId)->lockForUpdate()->firstOrFail();

        if ($this->eligibility->hasInvoiceLine($orderItemId)
            || $this->eligibility->hasReturnedReference($orderItemId)) {
            return null;
        }

        $order = Order::query()->whereKey($candidate['order']->id)->lockForUpdate()->firstOrFail();
        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status !== OrderStatus::DELIVERED) {
            return null;
        }

        $variant = ProductVariant::query()
            ->whereKey($candidate['product_variant_id'])
            ->lockForUpdate()
            ->first();

        if ($variant === null) {
            return null;
        }

        // Re-read historical snapshot under the order_item lock; never use live variant cost.
        $costResolution = $this->eligibility->resolveHistoricalUnitCostForPayable($orderItem);
        if ($costResolution['status'] !== 'valid') {
            // Invalid or non-positive under lock: skip without aborting the batch.
            return null;
        }

        $unitCost = $costResolution['unit_cost'];
        $quantity = (int) $orderItem->quantity;
        if ($quantity < 1) {
            return null;
        }

        $linePayable = $this->money->multiply($unitCost, $quantity);
        if (! $this->money->isPositive($linePayable)) {
            return null;
        }

        $candidate['order_item'] = $orderItem;
        $candidate['quantity'] = $quantity;
        $candidate['unit_cost'] = $unitCost;
        $candidate['line_payable'] = $linePayable;

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function insertPayableLine(
        SupplierSettlementInvoice $invoice,
        SupplierSettlementBatch $batch,
        array $line,
    ): void {
        try {
            SupplierSettlementInvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'settlement_date' => $line['settlement_date']
                    ?? ($batch->settlement_date?->toDateString()
                        ?? CarbonImmutable::parse((string) $batch->settlement_date)->toDateString()),
                'order_id' => $line['order']->id,
                'order_item_id' => $line['order_item']->id,
                'order_number_snapshot' => (string) $line['order']->order_number,
                'order_status_snapshot' => OrderStatus::DELIVERED->value,
                'supplier_user_id' => $line['supplier_user']->id,
                'shipment_id' => $line['shipment']->id,
                'tracking_number_snapshot' => $line['tracking_number'],
                'courier_service_id' => $line['courier_service_id'],
                'courier_receipt_import_id' => $line['courier_receipt_import_id'],
                'courier_receipt_import_row_id' => $line['courier_receipt_import_row_id'],
                'product_id' => $line['product_id'],
                'product_variant_id' => $line['product_variant_id'],
                'product_name_snapshot' => $line['product_name'],
                'variant_name_snapshot' => $line['variant_name'],
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
                'line_payable' => $line['line_payable'],
                'currency_id' => $line['currency_id'],
                'currency_code_snapshot' => $line['currency_code'],
                'line_payment_status' => SupplierSettlementInvoiceLine::LINE_PAYMENT_STATUS_UNPAID,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new SupplierSettlementException(
                    'SETTLEMENT_PAYABLE_CONFLICT',
                    'Concurrent settlement generation prevented a duplicate payable line.',
                    ['order_item_id' => $line['order_item']->id],
                    409,
                );
            }

            throw $exception;
        }
    }

    private function persistReturnedReferences(SupplierSettlementBatch $batch, Market $market): int
    {
        $settlementDate = $batch->settlement_date?->toDateString()
            ?? CarbonImmutable::parse((string) $batch->settlement_date)->toDateString();

        $orders = Order::query()
            ->with([
                'supplier.company',
                'currency',
                'items.variant.product',
                'items.product',
            ])
            ->where('market_id', $batch->market_id)
            ->where('status', OrderStatus::RETURNED->value)
            ->whereNotNull('returned_at')
            ->orderBy('id')
            ->get();

        $created = 0;

        foreach ($orders as $order) {
            $returnBusinessDate = $this->businessDates->businessDateString($market, $order->returned_at);
            if ($returnBusinessDate !== $settlementDate) {
                continue;
            }

            $supplier = $order->supplier;
            if ($supplier === null || $supplier->company_id === null || $supplier->company === null) {
                continue;
            }

            if ((int) $order->currency_id !== (int) $market->currency_id) {
                continue;
            }

            $currencyCode = strtoupper((string) ($order->currency?->code
                ?? $market->currency?->code
                ?? $order->currency_code_snapshot
                ?? ''));

            if (strlen($currencyCode) !== 3) {
                continue;
            }

            foreach ($order->items as $item) {
                if ($this->insertReturnedReferenceIfAllowed(
                    $batch,
                    $order,
                    $item,
                    (int) $supplier->company_id,
                    (int) $supplier->id,
                    (int) $order->currency_id,
                    $currencyCode,
                    $returnBusinessDate,
                )) {
                    $created++;
                }
            }
        }

        return $created;
    }

    private function insertReturnedReferenceIfAllowed(
        SupplierSettlementBatch $batch,
        Order $order,
        OrderItem $item,
        int $supplierCompanyId,
        int $supplierUserId,
        int $currencyId,
        string $currencyCode,
        string $returnBusinessDate,
    ): bool {
        // Caller already holds the batch transaction; keep order_item locks until commit.
        OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

        if ($this->eligibility->hasReturnedReference((int) $item->id)) {
            return false;
        }

        $existingLines = $this->eligibility->invoiceLinesForOrderItem((int) $item->id);
        $priorInvoiceId = null;
        $source = SupplierSettlementReturnedReference::SOURCE_PRE_LOCK;

        if ($existingLines->isNotEmpty()) {
            $lockedHistorical = $existingLines->first(function (SupplierSettlementInvoiceLine $line) {
                $status = $line->invoice?->batch?->status;

                return in_array($status, [
                    SupplierSettlementBatch::STATUS_LOCKED,
                    SupplierSettlementBatch::STATUS_CLOSED,
                ], true);
            });

            if ($lockedHistorical === null) {
                // Still present on an OPEN payable — exclusivity forbids a parallel return ref.
                return false;
            }

            $priorInvoiceId = (int) $lockedHistorical->invoice_id;
            $source = SupplierSettlementReturnedReference::SOURCE_POST_LOCK_CATCH_UP;
        }

        if ((int) $item->quantity < 1 || $item->product_variant_id === null) {
            return false;
        }

        $variant = $item->variant ?? ProductVariant::query()->find($item->product_variant_id);
        if ($variant === null) {
            return false;
        }

        // Informational only — returned display_payable remains 0.00; still use historical snapshot.
        // Invalid snapshots must not abort return-reference persistence or fall back to live cost.
        $rawSnapshot = array_key_exists('unit_cost_snapshot', $item->getAttributes())
            ? $item->getAttributes()['unit_cost_snapshot']
            : $item->getRawOriginal('unit_cost_snapshot');
        $unitCost = $this->money->tryNormalizeNonNegative($rawSnapshot) ?? '0.00';
        $product = $item->product ?? $variant->product;

        try {
            SupplierSettlementReturnedReference::query()->create([
                'batch_id' => $batch->id,
                'supplier_company_id' => $supplierCompanyId,
                'currency_id' => $currencyId,
                'currency_code_snapshot' => $currencyCode,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'order_number_snapshot' => (string) $order->order_number,
                'order_status_snapshot' => OrderStatus::RETURNED->value,
                'supplier_user_id' => $supplierUserId,
                'product_id' => (int) ($item->product_id ?: $variant->product_id),
                'product_variant_id' => (int) $variant->id,
                'product_name_snapshot' => (string) ($item->product_name_snapshot ?: ($product?->name ?? 'Product')),
                'variant_name_snapshot' => (string) ($item->variant_name_snapshot ?: ($variant->name ?? 'Variant')),
                'quantity' => (int) $item->quantity,
                'unit_cost' => $unitCost,
                'display_payable' => '0.00',
                'returned_at_business_date' => $returnBusinessDate,
                'source' => $source,
                'prior_payable_invoice_id' => $priorInvoiceId,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    private function clearOpenBatchFinancials(SupplierSettlementBatch $batch): void
    {
        $invoiceIds = SupplierSettlementInvoice::query()
            ->where('batch_id', $batch->id)
            ->pluck('id');

        SupplierSettlementReturnedReference::query()
            ->where('batch_id', $batch->id)
            ->delete();

        if ($invoiceIds->isNotEmpty()) {
            SupplierSettlementInvoiceLine::query()
                ->whereIn('invoice_id', $invoiceIds)
                ->delete();

            SupplierSettlementInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->delete();
        }
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
