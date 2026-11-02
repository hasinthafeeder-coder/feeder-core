<?php

namespace Feeder\Core\Services\Settlement;

use Carbon\CarbonImmutable;
use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Models\CourierReceiptImportRow;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderItem;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoiceLine;
use Feeder\Core\Models\SupplierSettlementReturnedReference;
use Feeder\Core\Models\User;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Feeder\Core\Support\MoneyNormalizer;
use Feeder\Core\Support\SupplierSettlementGenerationExceptionReport;
use Illuminate\Support\Collection;

/**
 * Evaluates payable eligibility from confirmed courier receipt evidence.
 *
 * Supplier line payables use order_items.unit_cost_snapshot × quantity only.
 * Does not invent COD totals from orders.customer_payable_amount, does not use
 * current product_variants.cost or selling price, and does not treat status-only
 * parsers as money evidence for supplier payables.
 *
 * Invalid historical cost snapshots are skipped with a structured exception report;
 * they never fall back to live product_variants.cost and never abort sibling lines.
 */
class SupplierSettlementPayableEligibilityEvaluator
{
    public function __construct(
        private readonly MarketBusinessDateResolver $businessDates,
        private readonly MoneyNormalizer $money,
    ) {}

    /**
     * @return array{
     *     candidates: list<array{
     *         order: Order,
     *         order_item: OrderItem,
     *         shipment: Shipment,
     *         supplier_user: User,
     *         supplier_company_id: int,
     *         supplier_company_name: string,
     *         currency_id: int,
     *         currency_code: string,
     *         product_id: int,
     *         product_variant_id: int,
     *         product_name: string,
     *         variant_name: string,
     *         quantity: int,
     *         unit_cost: string,
     *         line_payable: string,
     *         courier_receipt_import_id: int,
     *         courier_receipt_import_row_id: int,
     *         tracking_number: string,
     *         courier_service_id: int
     *     }>,
     *     exceptions: list<SupplierSettlementGenerationExceptionReport>
     * }
     */
    public function collectPayableCandidates(SupplierSettlementBatch $batch, Market $market): array
    {
        $settlementDate = $batch->settlement_date?->toDateString()
            ?? CarbonImmutable::parse((string) $batch->settlement_date)->toDateString();

        return $this->collectCandidates($batch, $market, $settlementDate);
    }

    /**
     * Eligible payables that are not yet on a supplier settlement batch.
     *
     * Uses the same receipt, delivery, supplier, currency, historical cost,
     * returned-reference, and invoice-line rules as batch generation. The
     * receipt business date is attached so callers can group batches without
     * choosing a date. Duplicate receipt rows for one order item count once.
     *
     * @return array{
     *     candidates: list<array<string, mixed>>,
     *     exceptions: list<SupplierSettlementGenerationExceptionReport>
     * }
     */
    public function collectOutstandingPayableCandidates(Market $market): array
    {
        $probe = new SupplierSettlementBatch([
            'market_id' => $market->id,
            'settlement_date' => $this->businessDates->businessDateString($market),
        ]);

        $collected = $this->collectCandidates($probe, $market, null);
        $seenItemIds = [];
        $candidates = [];

        foreach ($collected['candidates'] as $candidate) {
            $orderItemId = (int) $candidate['order_item']->id;
            if (isset($seenItemIds[$orderItemId])) {
                continue;
            }

            $seenItemIds[$orderItemId] = true;
            $candidates[] = $candidate;
        }

        return [
            'candidates' => $candidates,
            'exceptions' => $collected['exceptions'],
        ];
    }

    /**
     * @return array{
     *     candidates: list<array<string, mixed>>,
     *     exceptions: list<SupplierSettlementGenerationExceptionReport>
     * }
     */
    private function collectCandidates(
        SupplierSettlementBatch $batch,
        Market $market,
        ?string $requiredReceiptBusinessDate,
    ): array {
        $market->loadMissing('currency');

        $candidates = [];
        $exceptions = [];

        // Chunk eager-loaded receipt graphs. Loading every matched delivered row
        // for a market in one get() exhausted the 128MB Admin request limit and
        // aborted generation with a FatalError before the overlap lock released.
        CourierReceiptImportRow::query()
            ->with([
                'import',
                'matchedOrder.supplier.company',
                'matchedOrder.currency',
                'matchedOrder.items.variant.product',
                'matchedOrder.items.product',
                'matchedShipment',
            ])
            ->where('match_result', CourierReceiptImportRowMatchResult::MATCHED->value)
            ->whereNotNull('matched_order_id')
            ->whereNotNull('matched_shipment_id')
            ->whereHas('import', static function ($query): void {
                $query->where('status', CourierReceiptImportStatus::CONFIRMED->value)
                    ->whereNotNull('confirmed_at');
            })
            ->whereHas('matchedOrder', static function ($query) use ($batch): void {
                $query->where('market_id', $batch->market_id)
                    ->where('status', OrderStatus::DELIVERED->value);
            })
            ->orderBy('id')
            ->chunkById(100, function (Collection $rows) use (
                $batch,
                $market,
                $requiredReceiptBusinessDate,
                &$candidates,
                &$exceptions,
            ): void {
                foreach ($rows as $row) {
                    $order = $row->matchedOrder;
                    $shipment = $row->matchedShipment;
                    $import = $row->import;

                    if ($order === null || $shipment === null || $import === null || $import->confirmed_at === null) {
                        continue;
                    }

                    $receiptBusinessDate = $this->businessDates->businessDateString($market, $import->confirmed_at);
                    if ($requiredReceiptBusinessDate !== null && $receiptBusinessDate !== $requiredReceiptBusinessDate) {
                        continue;
                    }

                    if (! $this->receiptLinksAreConsistent($row, $order, $shipment, $import->courier_service_id)) {
                        continue;
                    }

                    $supplierContext = $this->resolveSupplierCompany($order);
                    if ($supplierContext === null) {
                        continue;
                    }

                    $currency = $this->resolveCurrency($order, $market);
                    if ($currency === null) {
                        continue;
                    }

                    foreach ($order->items as $item) {
                        $built = $this->buildPayableLineCandidate(
                            $batch,
                            $order,
                            $item,
                            $shipment,
                            $row,
                            $supplierContext,
                            $currency,
                        );

                        if ($built === null) {
                            continue;
                        }

                        if (isset($built['exception'])) {
                            $exceptions[] = $built['exception'];

                            continue;
                        }

                        $built['candidate']['receipt_business_date'] = $receiptBusinessDate;
                        $candidates[] = $built['candidate'];
                    }
                }
            });

        return [
            'candidates' => $candidates,
            'exceptions' => $exceptions,
        ];
    }

    /**
     * Revalidate a previously snapshotted payable line against live evidence.
     */
    public function assertLineStillPayable(
        SupplierSettlementBatch $batch,
        Market $market,
        SupplierSettlementInvoiceLine $line,
    ): void {
        $line->loadMissing([
            'order.supplier.company',
            'order.currency',
            'orderItem.variant',
            'shipment',
            'courierReceiptImport',
            'courierReceiptImportRow',
            'productVariant',
        ]);

        $order = $line->order;
        $item = $line->orderItem;
        $shipment = $line->shipment;
        $import = $line->courierReceiptImport;
        $row = $line->courierReceiptImportRow;

        if ($order === null || $item === null || $shipment === null || $import === null || $row === null) {
            throw new SupplierSettlementException(
                'SETTLEMENT_LINE_EVIDENCE_MISSING',
                'Payable line is missing order, item, shipment, or receipt evidence.',
                ['invoice_line_id' => $line->id],
            );
        }

        if ($import->status !== CourierReceiptImportStatus::CONFIRMED) {
            throw new SupplierSettlementException(
                'SETTLEMENT_IMPORT_NOT_CONFIRMED',
                'Payable line receipt import is not CONFIRMED.',
                ['invoice_line_id' => $line->id, 'import_id' => $import->id],
            );
        }

        $matchResult = $row->match_result instanceof CourierReceiptImportRowMatchResult
            ? $row->match_result
            : CourierReceiptImportRowMatchResult::tryFrom((string) $row->match_result);

        if ($matchResult !== CourierReceiptImportRowMatchResult::MATCHED) {
            throw new SupplierSettlementException(
                'SETTLEMENT_ROW_NOT_MATCHED',
                'Payable line receipt row is not MATCHED.',
                ['invoice_line_id' => $line->id, 'row_id' => $row->id],
            );
        }

        if ((int) $order->market_id !== (int) $batch->market_id) {
            throw new SupplierSettlementException(
                'SETTLEMENT_MARKET_MISMATCH',
                'Payable line order market does not match the batch market.',
                ['invoice_line_id' => $line->id],
            );
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status !== OrderStatus::DELIVERED) {
            throw new SupplierSettlementException(
                'SETTLEMENT_ORDER_NOT_DELIVERED',
                'Payable line order is not DELIVERED.',
                ['invoice_line_id' => $line->id, 'order_id' => $order->id],
            );
        }

        if ($import->confirmed_at === null) {
            throw new SupplierSettlementException(
                'SETTLEMENT_IMPORT_NOT_CONFIRMED',
                'Payable line receipt import has no confirmed_at timestamp.',
                ['invoice_line_id' => $line->id],
            );
        }

        $settlementDate = $batch->settlement_date?->toDateString()
            ?? CarbonImmutable::parse((string) $batch->settlement_date)->toDateString();
        $receiptBusinessDate = $this->businessDates->businessDateString($market, $import->confirmed_at);

        if ($receiptBusinessDate !== $settlementDate) {
            throw new SupplierSettlementException(
                'SETTLEMENT_RECEIPT_DATE_MISMATCH',
                'Payable line receipt business date does not belong to this batch.',
                ['invoice_line_id' => $line->id],
            );
        }

        if (! $this->receiptLinksAreConsistent($row, $order, $shipment, (int) $import->courier_service_id)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_SHIPMENT_INCONSISTENT',
                'Payable line shipment/tracking/courier evidence is inconsistent.',
                ['invoice_line_id' => $line->id],
            );
        }

        if ($this->resolveSupplierCompany($order) === null) {
            throw new SupplierSettlementException(
                'SETTLEMENT_SUPPLIER_UNRESOLVED',
                'Payable line supplier company cannot be resolved.',
                ['invoice_line_id' => $line->id],
            );
        }

        if ($this->resolveCurrency($order, $market) === null) {
            throw new SupplierSettlementException(
                'SETTLEMENT_CURRENCY_MISMATCH',
                'Payable line currency does not match the market currency.',
                ['invoice_line_id' => $line->id],
            );
        }

        if ((int) $item->quantity < 1 || $item->product_variant_id === null) {
            throw new SupplierSettlementException(
                'SETTLEMENT_ITEM_INVALID',
                'Payable line order item quantity or variant is invalid.',
                ['invoice_line_id' => $line->id],
            );
        }

        if ($this->hasReturnedReference((int) $item->id)) {
            throw new SupplierSettlementException(
                'SETTLEMENT_ITEM_RETURNED_REFERENCE',
                'Payable line order item is already recorded as a returned reference.',
                ['invoice_line_id' => $line->id, 'order_item_id' => $item->id],
            );
        }
    }

    /**
     * @param  array{
     *     supplier_company_id: int,
     *     supplier_company_name: string,
     *     supplier_user: User
     * }  $supplierContext
     * @param  array{currency_id: int, currency_code: string}  $currency
     * @return array{candidate: array<string, mixed>}|array{exception: SupplierSettlementGenerationExceptionReport}|null
     */
    private function buildPayableLineCandidate(
        SupplierSettlementBatch $batch,
        Order $order,
        OrderItem $item,
        Shipment $shipment,
        CourierReceiptImportRow $row,
        array $supplierContext,
        array $currency,
    ): ?array {
        if ((int) $item->quantity < 1 || $item->product_variant_id === null) {
            return null;
        }

        if ($this->hasInvoiceLine((int) $item->id) || $this->hasReturnedReference((int) $item->id)) {
            return null;
        }

        $variant = $item->variant;
        if (! $variant instanceof ProductVariant) {
            $variant = ProductVariant::query()->find($item->product_variant_id);
        }

        if ($variant === null) {
            return null;
        }

        $costResolution = $this->resolveHistoricalUnitCostForPayable($item);
        if ($costResolution['status'] === 'invalid') {
            return [
                'exception' => new SupplierSettlementGenerationExceptionReport(
                    SupplierSettlementGenerationExceptionReport::REASON_INVALID_UNIT_COST_SNAPSHOT,
                    'order_items.unit_cost_snapshot is null, malformed, or otherwise invalid; live product cost was not used.',
                    (int) $batch->id,
                    (int) $order->id,
                    (int) $item->id,
                    (string) $order->order_number,
                    [
                        'raw_unit_cost_snapshot' => $costResolution['raw'],
                        'product_variant_id' => (int) $variant->id,
                        'live_variant_cost_ignored' => $variant->getAttributes()['cost'] ?? null,
                        'quantity' => (int) $item->quantity,
                    ],
                ),
            ];
        }

        if ($costResolution['status'] === 'non_positive') {
            // Existing zero-cost rule: skip silently; do not create a zero-value payable.
            return null;
        }

        $unitCost = $costResolution['unit_cost'];
        $linePayable = $this->money->multiply($unitCost, (int) $item->quantity);
        if (! $this->money->isPositive($linePayable)) {
            return null;
        }

        $product = $item->product ?? $variant->product;
        $productName = (string) ($item->product_name_snapshot ?: ($product?->name ?? 'Product'));
        $variantName = (string) ($item->variant_name_snapshot ?: ($variant->name ?? 'Variant'));

        return [
            'candidate' => [
                'order' => $order,
                'order_item' => $item,
                'shipment' => $shipment,
                'supplier_user' => $supplierContext['supplier_user'],
                'supplier_company_id' => $supplierContext['supplier_company_id'],
                'supplier_company_name' => $supplierContext['supplier_company_name'],
                'currency_id' => $currency['currency_id'],
                'currency_code' => $currency['currency_code'],
                'product_id' => (int) ($item->product_id ?: $variant->product_id),
                'product_variant_id' => (int) $variant->id,
                'product_name' => $productName,
                'variant_name' => $variantName,
                'quantity' => (int) $item->quantity,
                'unit_cost' => $unitCost,
                'line_payable' => $linePayable,
                'courier_receipt_import_id' => (int) $row->courier_receipt_import_id,
                'courier_receipt_import_row_id' => (int) $row->id,
                'tracking_number' => (string) $shipment->tracking_number,
                'courier_service_id' => (int) $shipment->courier_service_id,
                'settlement_date' => $batch->settlement_date?->toDateString()
                    ?? CarbonImmutable::parse((string) $batch->settlement_date)->toDateString(),
            ],
        ];
    }

    /**
     * Resolve payable unit cost from the historical order-item snapshot only.
     *
     * @return array{status: 'valid', unit_cost: string, raw: mixed}
     *         |array{status: 'non_positive', unit_cost: string, raw: mixed}
     *         |array{status: 'invalid', raw: mixed}
     */
    public function resolveHistoricalUnitCostForPayable(OrderItem $item): array
    {
        $raw = array_key_exists('unit_cost_snapshot', $item->getAttributes())
            ? $item->getAttributes()['unit_cost_snapshot']
            : $item->getRawOriginal('unit_cost_snapshot');

        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return ['status' => 'invalid', 'raw' => $raw];
        }

        $normalized = $this->money->tryNormalizeNonNegative($raw);
        if ($normalized === null) {
            return ['status' => 'invalid', 'raw' => $raw];
        }

        if (! $this->money->isPositive($normalized)) {
            return ['status' => 'non_positive', 'unit_cost' => $normalized, 'raw' => $raw];
        }

        return ['status' => 'valid', 'unit_cost' => $normalized, 'raw' => $raw];
    }

    private function receiptLinksAreConsistent(
        CourierReceiptImportRow $row,
        Order $order,
        Shipment $shipment,
        int $importCourierServiceId,
    ): bool {
        if ((int) $row->matched_order_id !== (int) $order->id) {
            return false;
        }

        if ((int) $row->matched_shipment_id !== (int) $shipment->id) {
            return false;
        }

        if ((int) $shipment->order_id !== (int) $order->id) {
            return false;
        }

        if ((int) $shipment->courier_service_id !== $importCourierServiceId) {
            return false;
        }

        $normalizedTracking = trim((string) ($row->normalized_tracking_number ?? ''));
        $shipmentTracking = trim((string) ($shipment->tracking_number ?? ''));

        if ($normalizedTracking === '' || $shipmentTracking === '') {
            return false;
        }

        if ($normalizedTracking !== $shipmentTracking) {
            return false;
        }

        if ($row->matched_supplier_id !== null
            && (int) $row->matched_supplier_id !== (int) $order->supplier_id) {
            return false;
        }

        if ((int) $shipment->supplier_id !== (int) $order->supplier_id) {
            return false;
        }

        return true;
    }

    /**
     * @return array{supplier_company_id: int, supplier_company_name: string, supplier_user: User}|null
     */
    private function resolveSupplierCompany(Order $order): ?array
    {
        $supplier = $order->supplier;
        if (! $supplier instanceof User || $supplier->company_id === null) {
            return null;
        }

        $company = $supplier->company;
        if ($company === null || trim((string) $company->name) === '') {
            return null;
        }

        return [
            'supplier_company_id' => (int) $company->id,
            'supplier_company_name' => (string) $company->name,
            'supplier_user' => $supplier,
        ];
    }

    /**
     * @return array{currency_id: int, currency_code: string}|null
     */
    private function resolveCurrency(Order $order, Market $market): ?array
    {
        $market->loadMissing('currency');

        if ($market->currency_id === null || (int) $order->currency_id !== (int) $market->currency_id) {
            return null;
        }

        $code = (string) ($order->currency?->code
            ?? $market->currency?->code
            ?? $order->currency_code_snapshot
            ?? '');

        if (strlen($code) !== 3) {
            return null;
        }

        return [
            'currency_id' => (int) $order->currency_id,
            'currency_code' => strtoupper($code),
        ];
    }

    public function hasInvoiceLine(int $orderItemId): bool
    {
        return SupplierSettlementInvoiceLine::query()
            ->where('order_item_id', $orderItemId)
            ->exists();
    }

    public function hasReturnedReference(int $orderItemId): bool
    {
        return SupplierSettlementReturnedReference::query()
            ->where('order_item_id', $orderItemId)
            ->exists();
    }

    /**
     * @return Collection<int, SupplierSettlementInvoiceLine>
     */
    public function invoiceLinesForOrderItem(int $orderItemId): Collection
    {
        return SupplierSettlementInvoiceLine::query()
            ->with('invoice.batch')
            ->where('order_item_id', $orderItemId)
            ->get();
    }
}
