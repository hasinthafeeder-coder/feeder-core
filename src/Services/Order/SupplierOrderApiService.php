<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\SupplierFulfillmentStatus;
use Feeder\Core\Exceptions\SupplierFulfillmentException;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderFulfillmentItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Presentation helpers for supplier fulfillment HTTP/API responses.
 */
class SupplierOrderApiService
{
    public function __construct(
        private readonly SupplierFulfillmentQueryService $queryService,
        private readonly SupplierFulfillmentStockEvaluationService $stockEvaluationService,
        private readonly SupplierFulfillmentService $fulfillmentService,
        private readonly SupplierInvoiceService $invoiceService,
        private readonly SupplierOrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * @param  array{
     *     search?: string,
     *     product_id?: int|null,
     *     courier_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listFresh(int $supplierId, array $filters = []): array
    {
        // Fresh list shows only Fresh-eligible orders that currently have
        // sufficient stock under FIFO. Insufficient-stock orders appear in OOS.
        $evaluation = $this->stockEvaluationService
            ->evaluateInStockFreshOrders($supplierId)
            ->keyBy(fn (array $row) => $row['order']->id);

        $query = $this->derivedInStockFreshOrdersQuery($supplierId);
        $this->queryService->applyListFilters($query, $filters);

        $orders = $query->with([
            'items.variant',
            'fulfillment',
            'shipment.courier',
            'draftCourier',
            'address',
        ])->get();

        $presented = $orders->map(function (Order $order) use ($evaluation) {
            $row = $evaluation->get($order->id);

            return $this->presentListOrder($order, [
                'fulfillment_status' => SupplierFulfillmentStatus::FRESH->value,
                'is_out_of_stock_by_fifo' => false,
                'shortages' => $row['shortages'] ?? [],
            ]);
        })->values()->all();

        return $this->withNewOrdersSearchMeta(
            $this->withNewOrdersModeCounts(
                $this->paginatePresentedList(
                    $presented,
                    $filters,
                    $this->filterOptionsForList($supplierId, $filters, 'fresh'),
                ),
                $supplierId,
            ),
            $supplierId,
            $filters,
            'fresh',
            $presented,
        );
    }

    /**
     * @param  array{
     *     search?: string,
     *     product_id?: int|null,
     *     courier_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listOutOfStock(int $supplierId, array $filters = []): array
    {
        // OOS is a derived view of Fresh-eligible orders with insufficient stock
        // under FIFO — not an exclusive fulfillment status.
        $evaluation = $this->stockEvaluationService
            ->evaluateOutOfStockFreshOrders($supplierId)
            ->keyBy(fn (array $row) => $row['order']->id);

        $query = $this->derivedOutOfStockFreshOrdersQuery($supplierId);
        $this->queryService->applyListFilters($query, $filters);

        $orders = $query->with([
            'items.variant',
            'fulfillment',
            'shipment.courier',
            'draftCourier',
            'address',
        ])->get();

        $presented = $orders->map(function (Order $order) use ($evaluation) {
            $row = $evaluation->get($order->id);

            return $this->presentListOrder($order, [
                'fulfillment_status' => SupplierFulfillmentStatus::FRESH->value,
                'is_out_of_stock_by_fifo' => true,
                'shortages' => $row['shortages'] ?? [],
            ]);
        })->values()->all();

        return $this->withNewOrdersSearchMeta(
            $this->withNewOrdersModeCounts(
                $this->paginatePresentedList(
                    $presented,
                    $filters,
                    $this->filterOptionsForList($supplierId, $filters, 'out_of_stock'),
                ),
                $supplierId,
            ),
            $supplierId,
            $filters,
            'out_of_stock',
            $presented,
        );
    }

    /**
     * @param  array{
     *     search?: string,
     *     product_id?: int|null,
     *     courier_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listPrint(int $supplierId, array $filters = []): array
    {
        $query = $this->queryService->ordersInStatusQuery($supplierId, SupplierFulfillmentStatus::PRINT);
        $this->queryService->applyListFilters($query, $filters);

        $orders = $query->with([
            'items.variant',
            'fulfillment',
            'shipment.courier',
            'draftCourier',
            'address',
            'reseller.profile',
            'resellerCompany',
        ])->get();

        $presented = $orders->map(function (Order $order) {
            $resellerName = trim(
                ($order->reseller?->profile?->first_name ?? '')
                .' '
                .($order->reseller?->profile?->last_name ?? '')
            );

            return $this->presentListOrder($order, [
                'fulfillment_status' => SupplierFulfillmentStatus::PRINT->value,
                'is_print_ready' => $this->invoiceService->isPrintReady($order),
                'is_printed' => $this->fulfillmentService->hasPrintedEvent($order),
                'invoice_number' => $order->fulfillment?->invoice_number,
                'reseller_company_name' => $order->resellerCompany?->name,
                'reseller_name' => $resellerName !== '' ? $resellerName : null,
            ]);
        })->values()->all();

        return $this->paginatePresentedList(
            $presented,
            $filters,
            $this->filterOptionsForList($supplierId, $filters, 'print'),
        );
    }

    /**
     * @param  array{
     *     search?: string,
     *     product_id?: int|null,
     *     courier_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listPackaging(int $supplierId, array $filters = []): array
    {
        $query = $this->queryService->ordersInStatusQuery($supplierId, SupplierFulfillmentStatus::PACKAGING);
        $this->queryService->applyListFilters($query, $filters);

        $orders = $query->with([
            'items.variant',
            'fulfillment.items',
            'shipment.courier',
            'draftCourier',
            'address',
        ])->get();

        $presented = $orders->map(fn (Order $order) => $this->presentPackagingOrder($order))->values()->all();

        return $this->paginatePresentedList(
            $presented,
            $filters,
            $this->filterOptionsForList($supplierId, $filters, 'packaging'),
        );
    }

    /**
     * @param  array{
     *     search?: string,
     *     status?: string|null,
     *     courier_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listDispatch(int $supplierId, array $filters = []): array
    {
        $query = $this->queryService->dispatchOrdersQuery($supplierId);
        $this->queryService->applyDispatchListFilters($query, $filters);

        $orders = $query->with([
            'items',
            'fulfillment',
            'shipment.courier',
            'statusHistories',
        ])->get();

        $presented = $orders->map(fn (Order $order) => $this->presentDispatchOrder($order))->values()->all();

        $payload = $this->paginatePresentedList(
            $presented,
            $filters,
            $this->filterOptionsForList($supplierId, $filters, 'dispatch'),
        );

        $payload['meta']['summary_counts'] = $this->dispatchSummaryCounts($supplierId);

        return $payload;
    }

    /**
     * @return array{dispatched: int, returned_today: int}
     */
    public function dispatchSummaryCounts(int $supplierId): array
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $dispatched = $this->queryService->dispatchOrdersQuery($supplierId)
            ->where('status', OrderStatus::DISPATCHED->value)
            ->count();

        $returnedToday = $this->queryService->dispatchOrdersQuery($supplierId)
            ->where('status', OrderStatus::RETURNED->value)
            ->whereDate('returned_at', now()->toDateString())
            ->count();

        return [
            'dispatched' => $dispatched,
            'returned_today' => $returnedToday,
        ];
    }

    /**
     * @param  list<int>  $orderIds
     * @return list<array<string, mixed>>
     */
    public function preparePrintData(int $supplierId, array $orderIds, ?int $actorUserId = null): array
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            throw new SupplierFulfillmentException(
                'ORDER_NOT_PRINTABLE',
                'At least one order must be selected for printing.',
            );
        }

        if (count($orderIds) > SupplierFulfillmentBatchService::MAX_BATCH_SIZE) {
            throw new SupplierFulfillmentException(
                'BATCH_LIMIT_EXCEEDED',
                'A maximum of '.SupplierFulfillmentBatchService::MAX_BATCH_SIZE.' orders can be processed at once.',
                ['max' => SupplierFulfillmentBatchService::MAX_BATCH_SIZE, 'requested' => count($orderIds)],
            );
        }

        $payloads = [];

        try {
            foreach ($orderIds as $orderId) {
                $order = Order::query()
                    ->where('supplier_id', $supplierId)
                    ->whereKey($orderId)
                    ->with([
                        'supplier.company',
                        'resellerCompany',
                        'address',
                        'items.variant',
                        'shipment.courier',
                        'shipment.city.state',
                        'shipment.supplierCourierAccount',
                        'fulfillment',
                    ])
                    ->first();

                if ($order === null) {
                    throw new SupplierFulfillmentException(
                        'SUPPLIER_NOT_AUTHORIZED',
                        'Order not found.',
                        ['order_id' => $orderId],
                        404,
                    );
                }

                if ($order->fulfillment === null
                    || $order->fulfillment->isCancelled()
                    || $order->fulfillment->status !== SupplierFulfillmentStatus::PRINT
                    || ! in_array($order->status, [OrderStatus::PRINT, OrderStatus::PRINTED], true)
                ) {
                    throw new SupplierFulfillmentException(
                        'ORDER_NOT_PRINTABLE',
                        'Order is not in Print state.',
                        ['order_id' => $order->id, 'order_number' => $order->order_number],
                    );
                }

                $payload = $this->invoiceService->buildPrintPayload($order);
                $this->fulfillmentService->markPrinted($order, $supplierId, $actorUserId);
                $payloads[] = $payload;
            }
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Order is not printable.';

            throw new SupplierFulfillmentException(
                'ORDER_NOT_PRINTABLE',
                (string) $message,
                $exception->errors(),
            );
        }

        return $payloads;
    }

    /**
     * Re-print invoices for orders already in Packaging.
     * Does not change fulfillment status or record a new PRINTED event.
     *
     * @param  list<int>  $orderIds
     * @return list<array<string, mixed>>
     */
    public function prepareReprintData(int $supplierId, array $orderIds): array
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            throw new SupplierFulfillmentException(
                'ORDER_NOT_PRINTABLE',
                'At least one order must be selected for re-printing.',
            );
        }

        if (count($orderIds) > SupplierFulfillmentBatchService::MAX_BATCH_SIZE) {
            throw new SupplierFulfillmentException(
                'BATCH_LIMIT_EXCEEDED',
                'A maximum of '.SupplierFulfillmentBatchService::MAX_BATCH_SIZE.' orders can be processed at once.',
                ['max' => SupplierFulfillmentBatchService::MAX_BATCH_SIZE, 'requested' => count($orderIds)],
            );
        }

        $payloads = [];

        try {
            foreach ($orderIds as $orderId) {
                $order = Order::query()
                    ->where('supplier_id', $supplierId)
                    ->whereKey($orderId)
                    ->with([
                        'supplier.company',
                        'resellerCompany',
                        'address',
                        'items.variant',
                        'shipment.courier',
                        'shipment.city.state',
                        'shipment.supplierCourierAccount',
                        'fulfillment',
                    ])
                    ->first();

                if ($order === null) {
                    throw new SupplierFulfillmentException(
                        'SUPPLIER_NOT_AUTHORIZED',
                        'Order not found.',
                        ['order_id' => $orderId],
                        404,
                    );
                }

                if ($order->fulfillment === null
                    || $order->fulfillment->isCancelled()
                    || $order->fulfillment->status !== SupplierFulfillmentStatus::PACKAGING
                ) {
                    throw new SupplierFulfillmentException(
                        'ORDER_NOT_PACKAGING',
                        'Order is not in Packaging state.',
                        [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'fulfillment_status' => $order->fulfillment?->status?->value,
                        ],
                    );
                }

                $payloads[] = $this->invoiceService->buildPrintPayload($order);
            }
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Order is not printable.';

            throw new SupplierFulfillmentException(
                'ORDER_NOT_PRINTABLE',
                (string) $message,
                $exception->errors(),
            );
        }

        return $payloads;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadPackagingByOrderReference(int $supplierId, string|int $orderReference): array
    {
        // Identifier precedence: order_number / UUID / legacy first, then waybill.
        // Never OR these together into a single first() query (collision risk).
        $order = $this->queryService->findOwnedOrder($supplierId, $orderReference);

        if ($order === null) {
            $order = $this->queryService->findOwnedOrderByTrackingNumber(
                $supplierId,
                trim((string) $orderReference),
            );
        }

        if ($order === null) {
            throw new SupplierFulfillmentException(
                'SUPPLIER_NOT_AUTHORIZED',
                'Order not found.',
                status: 404,
            );
        }

        $order->load([
            'items.variant',
            'fulfillment.items',
            'shipment.courier',
            'address',
        ]);

        $fulfillment = $order->fulfillment;

        if ($fulfillment !== null
            && ! $fulfillment->isCancelled()
            && $fulfillment->status === SupplierFulfillmentStatus::DISPATCHED
        ) {
            throw new SupplierFulfillmentException(
                'ALREADY_DISPATCHED',
                'This package is already dispatched and cannot be reopened.',
                ['order_id' => $order->id, 'order_number' => $order->order_number],
            );
        }

        if ($fulfillment === null
            || $fulfillment->isCancelled()
            || $fulfillment->status !== SupplierFulfillmentStatus::PACKAGING
        ) {
            throw new SupplierFulfillmentException(
                'ORDER_NOT_PACKAGING',
                'Order is not in Packaging state.',
                [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'fulfillment_status' => $fulfillment?->status?->value,
                ],
            );
        }

        return $this->presentPackagingOrder($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function scanProductBarcode(
        int $supplierId,
        int $orderId,
        string $productBarcode,
        ?int $actorUserId = null,
    ): array {
        $order = Order::query()
            ->where('supplier_id', $supplierId)
            ->whereKey($orderId)
            ->first();

        if ($order === null) {
            throw new SupplierFulfillmentException(
                'SUPPLIER_NOT_AUTHORIZED',
                'Order not found.',
                ['order_id' => $orderId],
                404,
            );
        }

        try {
            $this->fulfillmentService->incrementPackedByBarcode(
                $order,
                $productBarcode,
                $supplierId,
                $actorUserId,
            );
        } catch (ValidationException $exception) {
            throw $this->mapPackingValidation($exception);
        }

        return $this->loadPackagingByOrderReference($supplierId, (string) $order->order_number);
    }

    /**
     * @return array<string, mixed>
     */
    public function completePackage(int $supplierId, int $orderId, ?int $actorUserId = null): array
    {
        $order = Order::query()
            ->where('supplier_id', $supplierId)
            ->whereKey($orderId)
            ->with(['fulfillment'])
            ->first();

        if ($order === null) {
            throw new SupplierFulfillmentException(
                'SUPPLIER_NOT_AUTHORIZED',
                'Order not found.',
                ['order_id' => $orderId],
                404,
            );
        }

        if ($order->fulfillment !== null
            && ! $order->fulfillment->isCancelled()
            && $order->fulfillment->status === SupplierFulfillmentStatus::DISPATCHED
        ) {
            throw new SupplierFulfillmentException(
                'ALREADY_DISPATCHED',
                'This package is already dispatched.',
                ['order_id' => $order->id, 'order_number' => $order->order_number],
            );
        }

        try {
            $fulfillment = $this->fulfillmentService->completePackaging($order, $supplierId, $actorUserId);
        } catch (ValidationException $exception) {
            throw $this->mapPackingValidation($exception);
        }

        $order = $order->fresh([
            'items.variant',
            'fulfillment.items',
            'shipment.courier',
            'address',
        ]);

        return $this->presentPackagingOrder($order, $fulfillment->status->value);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function presentListOrder(Order $order, array $extra = []): array
    {
        return array_merge([
            'id' => $order->id,
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'order_date' => $order->confirmed_at?->toDateString()
                ?? $order->created_at?->toDateString(),
            'customer_name' => $order->customer_name_snapshot,
            'primary_phone' => $order->primary_phone_snapshot,
            'secondary_phone' => $order->secondary_phone_snapshot,
            'address' => $order->address?->full_address_text,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'courier_id' => $order->shipment?->courier_id ?? $order->draft_courier_id,
            'courier' => $order->shipment?->courier?->name
                ?? $order->draftCourier?->name,
            'tracking_number' => $order->shipment?->tracking_number,
            'customer_payable_amount' => $order->customer_payable_amount !== null
                ? (string) $order->customer_payable_amount
                : null,
            'currency_code' => $order->currency_code_snapshot,
            'items' => $order->items->map(fn ($item) => [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name_snapshot,
                'variant' => $item->variant_name_snapshot,
                'barcode' => $item->variant?->barcode ?? $item->barcode_snapshot,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ], $extra);
    }

    /**
     * Unfiltered queue sizes for New Orders workspace tabs.
     *
     * @return array{fresh: int, exchange: int, out_of_stock: int}
     */
    public function newOrdersModeCounts(int $supplierId): array
    {
        $evaluation = $this->stockEvaluationService->evaluateFreshOrdersForSupplier($supplierId);

        return [
            'fresh' => $evaluation->filter(fn (array $row) => ! (bool) $row['is_out_of_stock'])->count(),
            'exchange' => 0,
            'out_of_stock' => $evaluation->filter(fn (array $row) => (bool) $row['is_out_of_stock'])->count(),
        ];
    }

    /**
     * @param  array{data: list<array<string, mixed>>, meta: array<string, mixed>}  $payload
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function withNewOrdersModeCounts(array $payload, int $supplierId): array
    {
        $payload['meta']['mode_counts'] = $this->newOrdersModeCounts($supplierId);

        return $payload;
    }

    /**
     * When an exact Feeder Order ID is searched but the order is not in the current
     * New Orders queue, surface an on-page location hint (no cross-supplier leakage).
     *
     * @param  array{data: list<array<string, mixed>>, meta: array<string, mixed>}  $payload
     * @param  array{search?: string}  $filters
     * @param  list<array<string, mixed>>  $presented
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function withNewOrdersSearchMeta(
        array $payload,
        int $supplierId,
        array $filters,
        string $currentQueue,
        array $presented,
    ): array {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return $payload;
        }

        $order = $this->queryService->findOwnedOrder($supplierId, $search);
        if ($order === null) {
            return $payload;
        }

        $order->loadMissing('fulfillment');

        $presentedIds = collect($presented)->pluck('id')->all();
        if (in_array((int) $order->id, array_map('intval', $presentedIds), true)) {
            return $payload;
        }

        $location = $this->resolveSupplierOrderQueueLocation($order);
        if ($location === null) {
            return $payload;
        }

        if ($location['queue'] === $currentQueue) {
            return $payload;
        }

        $payload['meta']['search_location'] = [
            'order_number' => $order->order_number,
            'queue' => $location['queue'],
            'label' => $location['label'],
            'message' => $location['message'],
        ];

        return $payload;
    }

    /**
     * @return array{queue: string, label: string, message: string}|null
     */
    private function resolveSupplierOrderQueueLocation(Order $order): ?array
    {
        if ($order->status === OrderStatus::CANCELLED
            || ($order->fulfillment !== null && $order->fulfillment->isCancelled())
        ) {
            return [
                'queue' => 'cancelled',
                'label' => 'Cancelled',
                'message' => 'This order is not available in New Orders. It has been cancelled.',
            ];
        }

        if ($order->status === OrderStatus::DISPATCHED
            || $order->fulfillment?->status === SupplierFulfillmentStatus::DISPATCHED
        ) {
            return [
                'queue' => 'dispatched',
                'label' => 'Dispatched',
                'message' => 'This order is not available in New Orders. It has already been dispatched.',
            ];
        }

        if ($order->status === OrderStatus::PACKING
            || $order->fulfillment?->status === SupplierFulfillmentStatus::PACKAGING
        ) {
            return [
                'queue' => 'packaging',
                'label' => 'Packing Orders',
                'message' => 'This order is not available in New Orders. It can be found in Packing Orders.',
            ];
        }

        if (in_array($order->status, [OrderStatus::PRINT, OrderStatus::PRINTED], true)
            || $order->fulfillment?->status === SupplierFulfillmentStatus::PRINT
        ) {
            return [
                'queue' => 'print',
                'label' => 'Print Orders',
                'message' => 'This order is not available in New Orders. It can be found in Print Orders.',
            ];
        }

        if ($this->queryService->isFreshEligible($order, (int) $order->supplier_id)) {
            if ($this->stockEvaluationService->isOrderOutOfStockByFifo($order, (int) $order->supplier_id)) {
                return [
                    'queue' => 'out_of_stock',
                    'label' => 'Out of Stock',
                    'message' => 'This order is not available in the current New Orders filter. It can be found in Out of Stock under New Orders.',
                ];
            }

            return [
                'queue' => 'fresh',
                'label' => 'Fresh',
                'message' => 'This order is not available in the current New Orders filter. It can be found in Fresh under New Orders.',
            ];
        }

        $status = $order->fulfillment?->status;

        return match ($status) {
            SupplierFulfillmentStatus::OUT_OF_STOCK => [
                'queue' => 'out_of_stock',
                'label' => 'Out of Stock',
                'message' => 'This order is not available in the current New Orders filter. It can be found in Out of Stock under New Orders.',
            ],
            default => null,
        };
    }

    /**
     * Supplier-owned order detail payload for the order view page.
     *
     * @return array<string, mixed>
     */
    public function showOrder(int $supplierId, Order $order): array
    {
        if ((int) $order->supplier_id !== $supplierId) {
            throw new SupplierFulfillmentException(
                'SUPPLIER_NOT_AUTHORIZED',
                'Order not found.',
                status: 404,
            );
        }

        $this->authorizationGuard->assertSupplierActor($supplierId);

        $order->load([
            'items.variant',
            'fulfillment',
            'shipment.courier',
            'draftCourier',
            'address',
        ]);

        $fulfillmentStatus = null;
        if ($this->queryService->isFreshEligible($order, $supplierId)) {
            $fulfillmentStatus = SupplierFulfillmentStatus::FRESH->value;
        } elseif ($order->fulfillment !== null && ! $order->fulfillment->isCancelled()) {
            $fulfillmentStatus = $order->fulfillment->status->value;
        }

        return $this->presentListOrder($order, [
            'fulfillment_status' => $fulfillmentStatus,
            'can_cancel' => $this->fulfillmentService->canCancel($order, $supplierId),
            'order_status' => $order->status instanceof OrderStatus
                ? $order->status->value
                : (string) $order->status,
            'order_status_label' => $order->status instanceof OrderStatus
                ? $order->status->label()
                : (string) $order->status,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $presented
     * @param  array{page?: int, per_page?: int}  $filters
     * @param  array{products: list<array{id: int, name: string}>, couriers: list<array{id: int, name: string}>}|null  $filterOptions
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function paginatePresentedList(array $presented, array $filters, ?array $filterOptions = null): array
    {
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 25;
        $perPage = max(1, min(100, $perPage));
        $total = count($presented);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        return [
            'data' => array_values(array_slice($presented, $offset, $perPage)),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'filter_options' => $filterOptions ?? $this->buildFilterOptions($presented),
            ],
        ];
    }

    /**
     * Fresh-eligible orders currently passing FIFO stock evaluation.
     * Empty result uses whereRaw('0 = 1') so callers can still apply filters safely.
     */
    private function derivedInStockFreshOrdersQuery(int $supplierId): Builder
    {
        $inStockOrderIds = $this->stockEvaluationService
            ->evaluateInStockFreshOrders($supplierId)
            ->map(fn (array $row) => (int) $row['order']->id)
            ->all();

        $query = $this->queryService->freshOrdersQuery($supplierId);

        if ($inStockOrderIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('orders.id', $inStockOrderIds);
    }

    /**
     * Fresh-eligible orders currently failing FIFO stock evaluation.
     * Empty result uses whereRaw('0 = 1') so callers can still apply filters safely.
     */
    private function derivedOutOfStockFreshOrdersQuery(int $supplierId): Builder
    {
        $oosOrderIds = $this->stockEvaluationService
            ->evaluateOutOfStockFreshOrders($supplierId)
            ->map(fn (array $row) => (int) $row['order']->id)
            ->all();

        $query = $this->queryService->freshOrdersQuery($supplierId);

        if ($oosOrderIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('orders.id', $oosOrderIds);
    }

    /**
     * Product/courier options come from the current queue scoped by search + date only,
     * so product/courier dropdowns stay usable while those filters are active.
     *
     * @param  array{
     *     search?: string,
     *     date_from?: string|null,
     *     date_to?: string|null
     * }  $filters
     * @return array{products: list<array{id: int, name: string}>, couriers: list<array{id: int, name: string}>}
     */
    private function filterOptionsForList(int $supplierId, array $filters, string $list): array
    {
        $scopedFilters = [
            'search' => $filters['search'] ?? '',
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'product_id' => null,
            'courier_id' => null,
        ];

        $query = match ($list) {
            'out_of_stock' => $this->derivedOutOfStockFreshOrdersQuery($supplierId),
            'print' => $this->queryService->ordersInStatusQuery(
                $supplierId,
                SupplierFulfillmentStatus::PRINT
            ),
            'packaging' => $this->queryService->ordersInStatusQuery(
                $supplierId,
                SupplierFulfillmentStatus::PACKAGING
            ),
            'dispatch' => $this->queryService->dispatchOrdersQuery($supplierId),
            default => $this->derivedInStockFreshOrdersQuery($supplierId),
        };

        if ($list === 'dispatch') {
            $this->queryService->applyDispatchListFilters($query, [
                'search' => $scopedFilters['search'],
                'date_from' => $scopedFilters['date_from'],
                'date_to' => $scopedFilters['date_to'],
                'status' => null,
                'courier_id' => null,
            ]);

            $orders = $query->with([
                'items',
                'fulfillment',
                'shipment.courier',
                'statusHistories',
            ])->get();

            $presented = $orders->map(fn (Order $order) => $this->presentDispatchOrder($order))->values()->all();

            return $this->buildFilterOptions($presented);
        }

        $this->queryService->applyListFilters($query, $scopedFilters);

        $orders = $query->with([
            'items',
            'shipment.courier',
            'draftCourier',
        ])->get();

        $presented = $orders->map(fn (Order $order) => $this->presentListOrder($order))->values()->all();

        return $this->buildFilterOptions($presented);
    }

    /**
     * Filter options are derived only from the current supplier order dataset.
     *
     * @param  list<array<string, mixed>>  $presented
     * @return array{products: list<array{id: int, name: string}>, couriers: list<array{id: int, name: string}>}
     */
    private function buildFilterOptions(array $presented): array
    {
        $products = [];
        $couriers = [];

        foreach ($presented as $order) {
            foreach ($order['items'] ?? [] as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $productName = trim((string) ($item['product_name'] ?? ''));

                if ($productId > 0 && $productName !== '' && ! isset($products[$productId])) {
                    $products[$productId] = [
                        'id' => $productId,
                        'name' => $productName,
                    ];
                }
            }

            $courierId = (int) ($order['courier_id'] ?? 0);
            $courierName = trim((string) ($order['courier'] ?? ''));

            if ($courierId > 0 && $courierName !== '' && ! isset($couriers[$courierId])) {
                $couriers[$courierId] = [
                    'id' => $courierId,
                    'name' => $courierName,
                ];
            }
        }

        $productList = array_values($products);
        usort($productList, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        $courierList = array_values($couriers);
        usort($courierList, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return [
            'products' => $productList,
            'couriers' => $courierList,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDispatchOrder(Order $order): array
    {
        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::from((string) $order->status);

        $dispatchedAt = $order->statusHistories
            ->filter(static function ($history) {
                $to = $history->to_status instanceof OrderStatus
                    ? $history->to_status
                    : OrderStatus::tryFrom((string) $history->to_status);

                return $to === OrderStatus::DISPATCHED;
            })
            ->sortBy('created_at')
            ->first()
            ?->created_at;

        $codAmount = $order->customer_payable_amount !== null
            ? number_format((float) $order->customer_payable_amount, 2, '.', ',')
            : null;

        return [
            'id' => $order->id,
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name_snapshot,
            'primary_phone' => $order->primary_phone_snapshot,
            'courier_id' => $order->shipment?->courier_id,
            'courier' => $order->shipment?->courier?->name,
            'waybill' => $order->shipment?->tracking_number,
            'tracking_number' => $order->shipment?->tracking_number,
            'invoice_number' => $order->fulfillment?->invoice_number,
            'cod_amount' => $codAmount,
            'currency_code' => $order->currency_code_snapshot,
            'status' => $status->value,
            'dispatched_at' => $dispatchedAt?->format('d M Y, H:i'),
            'dispatched_date' => $dispatchedAt?->toDateString(),
            'updated_at' => $order->updated_at?->format('d M Y, H:i'),
            'returned_at' => $order->returned_at?->format('d M Y, H:i'),
            'items' => $order->items->map(fn ($item) => [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name_snapshot,
                'variant' => $item->variant_name_snapshot,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPackagingOrder(Order $order, ?string $statusOverride = null): array
    {
        $fulfillment = $order->fulfillment;
        $itemsByOrderItemId = $fulfillment?->items?->keyBy('order_item_id') ?? collect();

        $items = $order->items->map(function ($item) use ($itemsByOrderItemId) {
            /** @var OrderFulfillmentItem|null $fulfillmentItem */
            $fulfillmentItem = $itemsByOrderItemId->get($item->id);
            $ordered = (int) $item->quantity;
            $packed = (int) ($fulfillmentItem?->packed_quantity ?? 0);
            $remaining = max(0, $ordered - $packed);

            return [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name_snapshot,
                'variant' => $item->variant_name_snapshot,
                'barcode' => $item->variant?->barcode ?? $item->barcode_snapshot,
                'ordered_quantity' => $ordered,
                'packed_quantity' => $packed,
                'remaining_quantity' => $remaining,
                'item_status' => $remaining === 0 ? 'PACKED' : ($packed > 0 ? 'PARTIAL' : 'PENDING'),
            ];
        })->values()->all();

        $orderedUnits = array_sum(array_map(
            static fn (array $item): int => (int) ($item['ordered_quantity'] ?? 0),
            $items,
        ));
        $packedUnits = array_sum(array_map(
            static fn (array $item): int => (int) ($item['packed_quantity'] ?? 0),
            $items,
        ));

        return [
            'id' => $order->id,
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'invoice_number' => $fulfillment?->invoice_number,
            'customer_name' => $order->customer_name_snapshot,
            'primary_phone' => $order->primary_phone_snapshot,
            'secondary_phone' => $order->secondary_phone_snapshot,
            'address' => $order->address?->full_address_text,
            'courier_id' => $order->shipment?->courier_id ?? $order->draft_courier_id,
            'courier' => $order->shipment?->courier?->name
                ?? $order->draftCourier?->name,
            'tracking_number' => $order->shipment?->tracking_number,
            'fulfillment_status' => $statusOverride
                ?? $fulfillment?->status?->value
                ?? null,
            'ordered_units' => $orderedUnits,
            'packed_units' => $packedUnits,
            'remaining_units' => max(0, $orderedUnits - $packedUnits),
            'items' => $items,
        ];
    }

    private function mapPackingValidation(ValidationException $exception): SupplierFulfillmentException
    {
        $errors = $exception->errors();
        $message = (string) (collect($errors)->flatten()->first() ?? 'Packaging validation failed.');
        $lower = strtolower($message);

        if (isset($errors['product_barcode'])) {
            return new SupplierFulfillmentException('WRONG_PRODUCT_BARCODE', $message, $errors);
        }

        if (isset($errors['packed_quantity'])) {
            if (str_contains($lower, 'over-scan') || str_contains($lower, 'exceed')) {
                return new SupplierFulfillmentException('OVER_SCAN', $message, $errors);
            }

            return new SupplierFulfillmentException('PACKAGE_INCOMPLETE', $message, $errors);
        }

        if (isset($errors['fulfillment'])) {
            if (str_contains($lower, 'dispatch')) {
                return new SupplierFulfillmentException('ALREADY_DISPATCHED', $message, $errors);
            }

            return new SupplierFulfillmentException('ORDER_NOT_PACKAGING', $message, $errors);
        }

        if (isset($errors['stock'])) {
            return new SupplierFulfillmentException('PACKAGE_INCOMPLETE', $message, $errors);
        }

        return new SupplierFulfillmentException('ORDER_NOT_PACKAGING', $message, $errors);
    }
}
