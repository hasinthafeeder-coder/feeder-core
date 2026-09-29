<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Exceptions\SupplierFulfillmentException;
use Feeder\Core\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Batch selection + orchestration for Send to Print / Send to Packing.
 * All-or-nothing; never silently skips invalid orders.
 */
class SupplierFulfillmentBatchService
{
    /**
     * Print / Send-to-Packing batch size. Send-to-Print has no hard cap.
     */
    public const MAX_BATCH_SIZE = 24;

    public function __construct(
        private readonly SupplierFulfillmentService $fulfillmentService,
        private readonly SupplierFulfillmentQueryService $queryService,
        private readonly SupplierOrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * @param  array{
     *     mode: 'manual'|'filter'|'count',
     *     order_ids?: list<int>,
     *     filters?: array{search?: string, product_id?: int|null, courier_id?: int|null},
     *     count?: int
     * }  $selection
     * @return Collection<int, Order>
     */
    public function sendToPrintBatch(int $supplierId, array $selection, ?int $actorUserId = null): Collection
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $orders = $this->resolveFreshSelection($supplierId, $selection);

        try {
            return DB::transaction(function () use ($orders, $supplierId, $actorUserId) {
                foreach ($orders as $order) {
                    $this->fulfillmentService->sendToPrint($order, $supplierId, $actorUserId);
                }

                return $orders->map(
                    fn (Order $order) => $order->fresh(['fulfillment', 'items', 'shipment'])
                )->values();
            });
        } catch (ValidationException $exception) {
            throw $this->mapValidationToFulfillmentException($exception);
        }
    }

    /**
     * @param  list<int>  $orderIds
     * @return Collection<int, Order>
     */
    public function sendToPackagingBatch(int $supplierId, array $orderIds, ?int $actorUserId = null): Collection
    {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            throw new SupplierFulfillmentException(
                'ORDER_NOT_PRINTABLE',
                'At least one order must be selected.',
            );
        }

        if (count($orderIds) > self::MAX_BATCH_SIZE) {
            throw new SupplierFulfillmentException(
                'BATCH_LIMIT_EXCEEDED',
                'A maximum of '.self::MAX_BATCH_SIZE.' orders can be processed at once.',
                ['max' => self::MAX_BATCH_SIZE, 'requested' => count($orderIds)],
            );
        }

        $orders = $this->loadOwnedOrdersOrFail($supplierId, $orderIds);

        try {
            return DB::transaction(function () use ($orders, $supplierId, $actorUserId) {
                foreach ($orders as $order) {
                    $this->fulfillmentService->sendToPackaging($order, $supplierId, $actorUserId);
                }

                return $orders->map(
                    fn (Order $order) => $order->fresh(['fulfillment', 'items', 'shipment'])
                )->values();
            });
        } catch (ValidationException $exception) {
            throw $this->mapValidationToFulfillmentException($exception);
        }
    }

    /**
     * @param  array{
     *     mode: 'manual'|'filter'|'count',
     *     order_ids?: list<int>,
     *     filters?: array{search?: string, product_id?: int|null, courier_id?: int|null},
     *     count?: int
     * }  $selection
     * @return Collection<int, Order>
     */
    public function resolveFreshSelection(int $supplierId, array $selection): Collection
    {
        $mode = (string) ($selection['mode'] ?? '');

        $orders = match ($mode) {
            'manual' => $this->resolveManualFreshOrders($supplierId, $selection['order_ids'] ?? []),
            'filter' => $this->resolveFilterFreshOrders($supplierId, $selection['filters'] ?? []),
            'count' => $this->resolveCountFreshOrders($supplierId, (int) ($selection['count'] ?? 0)),
            default => throw new SupplierFulfillmentException(
                'ORDER_NOT_FRESH',
                'Selection mode must be manual, filter, or count.',
            ),
        };

        if ($orders->isEmpty()) {
            throw new SupplierFulfillmentException(
                'ORDER_NOT_FRESH',
                'No eligible Fresh orders matched the selection.',
            );
        }

        return $orders->values();
    }

    /**
     * @param  list<int>  $orderIds
     * @return Collection<int, Order>
     */
    private function resolveManualFreshOrders(int $supplierId, array $orderIds): Collection
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            throw new SupplierFulfillmentException(
                'ORDER_NOT_FRESH',
                'At least one order must be selected.',
            );
        }

        $orders = $this->loadOwnedOrdersOrFail($supplierId, $orderIds);

        foreach ($orders as $order) {
            if (! $this->queryService->isFreshEligible($order, $supplierId)) {
                throw new SupplierFulfillmentException(
                    'ORDER_NOT_FRESH',
                    'One or more selected orders are not eligible Fresh orders.',
                    ['order_id' => $order->id, 'order_number' => $order->order_number],
                );
            }
        }

        return $orders;
    }

    /**
     * @param  array{search?: string, product_id?: int|null, courier_id?: int|null}  $filters
     * @return Collection<int, Order>
     */
    private function resolveFilterFreshOrders(int $supplierId, array $filters): Collection
    {
        $query = $this->queryService->freshOrdersQuery($supplierId);
        $this->queryService->applyListFilters($query, $filters);

        return $query->with(['items', 'fulfillment'])->get();
    }

    /**
     * @return Collection<int, Order>
     */
    private function resolveCountFreshOrders(int $supplierId, int $count): Collection
    {
        if ($count < 1) {
            throw new SupplierFulfillmentException(
                'ORDER_NOT_FRESH',
                'Order count must be at least 1.',
            );
        }

        return $this->queryService->freshOrdersQuery($supplierId)
            ->with(['items', 'fulfillment'])
            ->limit($count)
            ->get();
    }

    /**
     * @param  list<int>  $orderIds
     * @return Collection<int, Order>
     */
    private function loadOwnedOrdersOrFail(int $supplierId, array $orderIds): Collection
    {
        $orders = Order::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('id', $orderIds)
            ->with(['items', 'fulfillment', 'shipment'])
            ->get()
            ->keyBy('id');

        foreach ($orderIds as $orderId) {
            if (! $orders->has($orderId)) {
                // Same response whether missing or owned by another supplier.
                throw new SupplierFulfillmentException(
                    'SUPPLIER_NOT_AUTHORIZED',
                    'Order not found.',
                    ['order_id' => $orderId],
                    404,
                );
            }
        }

        // Preserve request order.
        return collect($orderIds)->map(fn (int $id) => $orders->get($id))->values();
    }

    private function mapValidationToFulfillmentException(ValidationException $exception): SupplierFulfillmentException
    {
        $errors = $exception->errors();
        $firstMessage = collect($errors)->flatten()->first() ?? 'Fulfillment validation failed.';

        if (isset($errors['stock'])) {
            $code = str_contains(strtolower((string) $firstMessage), 'already reserved')
                ? 'ORDER_NOT_FRESH'
                : 'INSUFFICIENT_STOCK';

            return new SupplierFulfillmentException($code, (string) $firstMessage, $errors);
        }

        if (isset($errors['fulfillment'])) {
            $message = strtolower((string) $firstMessage);

            if (str_contains($message, 'printed')) {
                return new SupplierFulfillmentException('ORDER_NOT_PRINTED', (string) $firstMessage, $errors);
            }

            if (str_contains($message, 'packaging')) {
                return new SupplierFulfillmentException('ORDER_NOT_PACKAGING', (string) $firstMessage, $errors);
            }

            if (str_contains($message, 'print')) {
                return new SupplierFulfillmentException('ORDER_NOT_FRESH', (string) $firstMessage, $errors);
            }

            if (str_contains($message, 'dispatch')) {
                return new SupplierFulfillmentException('ALREADY_DISPATCHED', (string) $firstMessage, $errors);
            }

            return new SupplierFulfillmentException('ORDER_NOT_FRESH', (string) $firstMessage, $errors);
        }

        if (isset($errors['shipment']) || isset($errors['tracking_number']) || isset($errors['courier_id']) || isset($errors['courier_city_id'])) {
            return new SupplierFulfillmentException('ORDER_NOT_PRINTABLE', (string) $firstMessage, $errors);
        }

        if (isset($errors['order']) || isset($errors['order_type']) || isset($errors['status'])) {
            $message = strtolower((string) $firstMessage);

            if (str_contains($message, 'printed') || str_contains($message, 'print')) {
                // Distinguish "only valid from Printed" (not yet printed) from other print-queue errors.
                if (str_contains($message, 'from printed') || str_contains($message, 'only printed')) {
                    return new SupplierFulfillmentException('ORDER_NOT_PRINTED', (string) $firstMessage, $errors);
                }

                return new SupplierFulfillmentException('ORDER_NOT_FRESH', (string) $firstMessage, $errors);
            }

            if (str_contains($message, 'dispatch')) {
                return new SupplierFulfillmentException('ALREADY_DISPATCHED', (string) $firstMessage, $errors);
            }

            return new SupplierFulfillmentException('ORDER_NOT_FRESH', (string) $firstMessage, $errors);
        }

        if (isset($errors['packed_quantity'])) {
            $message = strtolower((string) $firstMessage);

            if (str_contains($message, 'fully packed') || str_contains($message, 'incomplete')) {
                return new SupplierFulfillmentException('PACKAGE_INCOMPLETE', (string) $firstMessage, $errors);
            }

            if (str_contains($message, 'between 0') || str_contains($message, 'exceed')) {
                return new SupplierFulfillmentException('OVER_SCAN', (string) $firstMessage, $errors);
            }

            return new SupplierFulfillmentException('PACKAGE_INCOMPLETE', (string) $firstMessage, $errors);
        }

        if (isset($errors['product_barcode']) || isset($errors['barcode'])) {
            return new SupplierFulfillmentException('WRONG_PRODUCT_BARCODE', (string) $firstMessage, $errors);
        }

        return new SupplierFulfillmentException('ORDER_NOT_FRESH', (string) $firstMessage, $errors);
    }
}
