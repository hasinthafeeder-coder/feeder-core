<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\ResellerReturnReason;
use Feeder\Core\Exceptions\ResellerDebtObligationException;
use Feeder\Core\Exceptions\SupplierFulfillmentException;
use Feeder\Core\Models\Order;
use Feeder\Core\Services\Settlement\ResellerDebtObligationService;
use Feeder\Core\Services\Settlement\ResellerReturnFineCalculator;
use Feeder\Core\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Supplier return intake: waybill scan → DISPATCHED → RETURNED.
 *
 * Return reason is derived from persisted order flags (after_hours /
 * duplicate_warning_overridden). Suppliers do not choose a reason.
 * Normal returns restore stock via stock_restorations.
 * Damaged returns (`is_damaged`) mark RETURNED without restoring stock.
 * On successful return, records an idempotent reseller non-delivery-fine debt obligation
 * when the order market has a configured fine schedule (never FX-converted).
 * Does not alter shipment status or fulfillment. Historical consumptions stay intact.
 */
class SupplierReturnScanService
{
    public const HISTORY_REASON = 'Returned via supplier waybill scan';

    public const HISTORY_REASON_DAMAGED = 'Returned via supplier waybill scan (damaged — not restocked)';

    public const HISTORY_REASON_COURIER_REPORT = 'Returned via courier payment report';

    public function __construct(
        private readonly SupplierFulfillmentQueryService $queryService,
        private readonly OrderStatusService $orderStatusService,
        private readonly SupplierOrderAuthorizationGuard $authorizationGuard,
        private readonly StockService $stockService,
        private readonly ResellerDebtObligationService $debtObligationService,
        private readonly ResellerReturnFineCalculator $returnFineCalculator,
    ) {
    }

    /**
     * @return array{
     *     id: int,
     *     order_number: string|null,
     *     tracking_number: string,
     *     status: string,
     *     returned_at: string|null,
     *     return_reason: string,
     *     is_damaged: bool,
     *     stock_restored: bool,
     *     debt_obligation: array{
     *         recorded: bool,
     *         skipped: bool,
     *         skip_reason: string|null,
     *         uuid: string|null,
     *         original_amount: string|null,
     *         outstanding_amount: string|null,
     *         currency_code: string|null
     *     },
     *     restored_items: list<array{
     *         order_item_id: int,
     *         product_variant_id: int,
     *         quantity: int
     *     }>
     * }
     *
     * @throws SupplierFulfillmentException
     */
    public function markReturnedByTrackingNumber(
        int $supplierId,
        string $trackingNumber,
        ?int $actorUserId = null,
        bool $isDamaged = false,
    ): array {
        $this->authorizationGuard->assertSupplierActor($supplierId);

        $tracking = trim($trackingNumber);

        if ($tracking === '') {
            throw new SupplierFulfillmentException(
                'WAYBILL_REQUIRED',
                'A shipment waybill / tracking number is required.',
                ['tracking_number' => ['A shipment waybill / tracking number is required.']],
            );
        }

        $order = $this->queryService->findOwnedOrderByTrackingNumber($supplierId, $tracking);

        if ($order === null) {
            // Unknown waybill and foreign-supplier waybill share the same response (no leak).
            throw new SupplierFulfillmentException(
                'SUPPLIER_NOT_AUTHORIZED',
                'Order not found.',
                status: 404,
            );
        }

        $actorUserId ??= $supplierId;

        try {
            $result = DB::transaction(function () use ($order, $supplierId, $actorUserId, $isDamaged) {
                // Re-validate under row lock so concurrent delivery/return cannot both apply.
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->authorizationGuard->assertOwnsOrder($locked, $supplierId);

                return $this->returnLockedDispatchedOrder(
                    $locked,
                    $actorUserId,
                    $this->returnFineCalculator->resolveReturnReasonForOrder($locked),
                    $isDamaged,
                    $isDamaged ? self::HISTORY_REASON_DAMAGED : self::HISTORY_REASON,
                );
            });
        } catch (SupplierFulfillmentException $exception) {
            throw $exception;
        } catch (ResellerDebtObligationException $exception) {
            throw new SupplierFulfillmentException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
            );
        } catch (ValidationException $exception) {
            $message = (string) (collect($exception->errors())->flatten()->first()
                ?? 'Unable to mark order as returned.');

            throw new SupplierFulfillmentException(
                'ORDER_NOT_DISPATCHED',
                $message,
                $exception->errors(),
            );
        } catch (RuntimeException $exception) {
            throw new SupplierFulfillmentException(
                'STOCK_RESTORATION_FAILED',
                $exception->getMessage(),
                ['stock' => [$exception->getMessage()]],
            );
        } catch (Throwable $exception) {
            throw new SupplierFulfillmentException(
                'STOCK_RESTORATION_FAILED',
                'Unable to restore stock for the returned order.',
                ['stock' => ['Unable to restore stock for the returned order.']],
            );
        }

        /** @var Order $returned */
        $returned = $result['order'];
        $isDamagedResult = (bool) $result['is_damaged'];
        /** @var ResellerReturnReason $persistedReason */
        $persistedReason = $result['return_reason'];
        $debt = $result['debt'];
        $obligation = $debt['obligation'] ?? null;

        $restoredItems = array_map(
            static fn (array $row): array => [
                'order_item_id' => $row['order_item_id'],
                'product_variant_id' => $row['product_variant_id'],
                'quantity' => $row['quantity'],
            ],
            $result['restorations'],
        );

        return [
            'id' => (int) $returned->id,
            'order_number' => $returned->order_number,
            'tracking_number' => (string) ($returned->shipment?->tracking_number ?? $tracking),
            'status' => OrderStatus::RETURNED->value,
            'returned_at' => $returned->returned_at?->toIso8601String(),
            'return_reason' => $persistedReason->value,
            'is_damaged' => $isDamagedResult,
            'stock_restored' => ! $isDamagedResult,
            'debt_obligation' => [
                'recorded' => (bool) ($debt['created'] ?? false) || $obligation !== null,
                'skipped' => (bool) ($debt['skipped'] ?? false),
                'skip_reason' => $debt['skip_reason'] ?? null,
                'uuid' => $obligation?->uuid,
                'original_amount' => $obligation !== null
                    ? number_format((float) $obligation->original_amount, 2, '.', '')
                    : null,
                'outstanding_amount' => $obligation !== null
                    ? number_format((float) $obligation->outstanding_amount, 2, '.', '')
                    : null,
                'currency_code' => $obligation?->currency_code_snapshot,
            ],
            'restored_items' => $restoredItems,
        ];
    }

    /**
     * Courier-report return. Uses the same status transition, stock restoration,
     * and non-delivery fine as a supplier scan. A delivered order is rejected.
     * An order that is already RETURNED is left unchanged.
     *
     * @return array{
     *     already_returned: bool,
     *     id: int,
     *     status: string,
     *     stock_restored: bool,
     *     restored_items: list<array{order_item_id: int, product_variant_id: int, quantity: int}>,
     *     debt_created: bool
     * }
     */
    public function markReturnedFromCourierReport(Order $order, int $actorUserId): array
    {
        try {
            $joinCallerTransaction = DB::transactionLevel() > 0;
            $run = function () use ($order, $actorUserId) {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                $status = $locked->status instanceof OrderStatus
                    ? $locked->status
                    : OrderStatus::from((string) $locked->status);

                if ($status === OrderStatus::RETURNED) {
                    return [
                        'already_returned' => true,
                        'order' => $locked,
                        'restorations' => [],
                        'debt' => ['created' => false, 'skipped' => true, 'skip_reason' => 'already_returned', 'obligation' => null],
                    ];
                }

                if ($status === OrderStatus::DELIVERED) {
                    throw new SupplierFulfillmentException(
                        'ORDER_ALREADY_DELIVERED',
                        'A delivered order cannot be returned from a courier report row.',
                        [
                            'order_id' => $locked->id,
                            'order_number' => $locked->order_number,
                            'status' => $status->value,
                        ],
                    );
                }

                $completed = $this->returnLockedDispatchedOrder(
                    $locked,
                    $actorUserId,
                    $this->returnFineCalculator->resolveReturnReasonForOrder($locked),
                    false,
                    self::HISTORY_REASON_COURIER_REPORT,
                );
                $completed['already_returned'] = false;

                return $completed;
            };
            $result = $joinCallerTransaction ? $run() : DB::transaction($run);
        } catch (SupplierFulfillmentException $exception) {
            throw $exception;
        } catch (ResellerDebtObligationException $exception) {
            throw new SupplierFulfillmentException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
            );
        } catch (ValidationException $exception) {
            $message = (string) (collect($exception->errors())->flatten()->first()
                ?? 'Unable to mark order as returned.');

            throw new SupplierFulfillmentException(
                'ORDER_NOT_DISPATCHED',
                $message,
                $exception->errors(),
            );
        } catch (RuntimeException $exception) {
            throw new SupplierFulfillmentException(
                'STOCK_RESTORATION_FAILED',
                $exception->getMessage(),
                ['stock' => [$exception->getMessage()]],
            );
        } catch (Throwable $exception) {
            throw new SupplierFulfillmentException(
                'STOCK_RESTORATION_FAILED',
                'Unable to restore stock for the returned order.',
                ['stock' => ['Unable to restore stock for the returned order.']],
            );
        }

        /** @var Order $returned */
        $returned = $result['order'];
        $debt = $result['debt'];

        return [
            'already_returned' => (bool) ($result['already_returned'] ?? false),
            'id' => (int) $returned->id,
            'status' => OrderStatus::RETURNED->value,
            'stock_restored' => ! (bool) ($result['already_returned'] ?? false),
            'restored_items' => array_map(
                static fn (array $row): array => [
                    'order_item_id' => $row['order_item_id'],
                    'product_variant_id' => $row['product_variant_id'],
                    'quantity' => $row['quantity'],
                ],
                $result['restorations'],
            ),
            'debt_created' => (bool) ($debt['created'] ?? false),
        ];
    }

    /**
     * @return array{
     *     order: Order,
     *     restorations: list<array<string, mixed>>,
     *     is_damaged: bool,
     *     return_reason: ResellerReturnReason,
     *     debt: array<string, mixed>
     * }
     */
    private function returnLockedDispatchedOrder(
        Order $locked,
        int $actorUserId,
        ResellerReturnReason $returnReason,
        bool $isDamaged,
        string $historyReason,
    ): array {
        $status = $locked->status instanceof OrderStatus
            ? $locked->status
            : OrderStatus::from((string) $locked->status);

        if ($status !== OrderStatus::DISPATCHED) {
            throw new SupplierFulfillmentException(
                'ORDER_NOT_DISPATCHED',
                'Only DISPATCHED orders can be marked as RETURNED.',
                [
                    'order_id' => $locked->id,
                    'order_number' => $locked->order_number,
                    'status' => $status->value,
                ],
            );
        }

        $returned = $this->orderStatusService->transition(
            $locked,
            OrderStatus::RETURNED,
            $actorUserId,
            null,
            $historyReason,
        );

        $returned->forceFill([
            'return_is_damaged' => $isDamaged,
            'return_reason' => $returnReason,
        ])->save();

        $restorationRows = [];

        if (! $isDamaged) {
            $restorationRows = $this->stockService->restoreConsumptionsForOrder(
                $returned,
                $actorUserId,
            );
        }

        $debtResult = $this->debtObligationService->recordReturnFineForOrder(
            $returned->fresh(['market']),
            $actorUserId,
        );

        return [
            'order' => $returned->fresh(['shipment']),
            'restorations' => $restorationRows,
            'is_damaged' => $isDamaged,
            'return_reason' => $returnReason,
            'debt' => $debtResult,
        ];
    }
}
