<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\StockReservationStatus;
use Feeder\Core\Events\OrderConfirmed;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderStatusHistory;
use Feeder\Core\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStatusService
{
    /**
     * Cancelled orders remain operationally actionable for this many days.
     * After the window, reactivation is rejected and operations_hidden_at is due.
     */
    public const OPERATIONAL_VISIBILITY_DAYS = 14;

    public function __construct(
        private readonly OrderDiscountService $discountService,
        private readonly OrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * Whether the given status may legally transition to the target status.
     * Portal ownership / permission checks are separate.
     */
    public function canTransitionTo(OrderStatus|string $from, OrderStatus|string $to): bool
    {
        $fromStatus = $from instanceof OrderStatus ? $from : OrderStatus::from($from);
        $toStatus = $to instanceof OrderStatus ? $to : OrderStatus::from($to);

        return OrderStatus::canTransitionTo($fromStatus, $toStatus);
    }

    /**
     * Lifecycle rule: any status before DISPATCHED may be cancelled.
     * DISPATCHED, DELIVERED, RETURNED, and CANCELLED cannot be cancelled.
     */
    public function canCancel(OrderStatus|string|Order $statusOrOrder): bool
    {
        $status = $this->resolveStatus($statusOrOrder);

        return $status !== null && $status->canCancel();
    }

    /**
     * Whether the order/status has not reached DISPATCHED (or a later outcome).
     */
    public function isBeforeDispatch(OrderStatus|string|Order $statusOrOrder): bool
    {
        $status = $this->resolveStatus($statusOrOrder);

        return $status !== null && $status->isBeforeDispatch();
    }

    /**
     * Transition an order to a valid lifecycle status.
     *
     * On transition into CONFIRMED:
     * - recalculate customer payable from current snapshots
     * - lock discount via discount_locked_at
     *
     * Status changes after CONFIRMED do not unlock the discount.
     *
     * On transition into CANCELLED:
     * - release active stock reservations
     * - mark active supplier fulfillment as cancelled
     *
     * Reactivation (leaving CANCELLED) is allowed only within the operational visibility window.
     *
     * @param  int|null  $actorCompanyId  When provided, enforces reseller-company boundary.
     *                                    Prefer this over trusting changedByCompanyId alone.
     */
    public function transition(
        Order $order,
        OrderStatus|string $toStatus,
        ?int $changedBy = null,
        ?int $changedByCompanyId = null,
        ?string $reason = null,
        ?int $actorCompanyId = null,
    ): Order {
        $toStatus = $toStatus instanceof OrderStatus ? $toStatus : OrderStatus::from($toStatus);

        return DB::transaction(function () use ($order, $toStatus, $changedBy, $changedByCompanyId, $reason, $actorCompanyId) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            if ($order->hasPendingPaymentApproval()) {
                throw ValidationException::withMessages([
                    'order' => [
                        'This order is locked while a bank transfer payment is pending approval.',
                    ],
                ]);
            }

            $fromStatus = $order->status instanceof OrderStatus
                ? $order->status
                : OrderStatus::from((string) $order->status);

            if ($fromStatus === $toStatus) {
                return $order->fresh(['statusHistories']);
            }

            if (! OrderStatus::canTransitionTo($fromStatus, $toStatus)) {
                throw ValidationException::withMessages([
                    'status' => [
                        sprintf(
                            'Cannot transition order from %s to %s.',
                            $fromStatus->label(),
                            $toStatus->label()
                        ),
                    ],
                ]);
            }

            $attributes = [
                'status' => $toStatus,
                'updated_by' => $changedBy,
            ];

            if ($toStatus === OrderStatus::CANCELLED) {
                $attributes['cancelled_at'] = now();
                $attributes['operations_hidden_at'] = now()->addDays(self::OPERATIONAL_VISIBILITY_DAYS);
            }

            if ($toStatus === OrderStatus::CONFIRMED) {
                $attributes['confirmed_at'] = now();
            }

            if ($toStatus === OrderStatus::DELIVERED && $order->delivered_at === null) {
                $attributes['delivered_at'] = now();
            }

            if ($toStatus === OrderStatus::RETURNED && $order->returned_at === null) {
                $attributes['returned_at'] = now();
            }

            if ($fromStatus === OrderStatus::CANCELLED && $toStatus !== OrderStatus::CANCELLED) {
                $this->assertWithinReactivationWindow($order);
                $attributes['reactivated_at'] = now();
                $attributes['operations_hidden_at'] = null;
            }

            $order->update($attributes);

            if ($toStatus === OrderStatus::CONFIRMED) {
                $this->discountService->lockOnConfirmation($order->fresh());
            }

            if ($toStatus === OrderStatus::CANCELLED) {
                $this->releaseReservationsAndCancelFulfillment($order, $changedBy);
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by' => $changedBy,
                'changed_by_company_id' => $changedByCompanyId,
                'reason' => $reason,
            ]);

            if ($toStatus === OrderStatus::CONFIRMED) {
                $confirmedOrderId = (int) $order->id;
                $confirmedBy = $changedBy;

                DB::afterCommit(function () use ($confirmedOrderId, $confirmedBy): void {
                    event(new OrderConfirmed($confirmedOrderId, $confirmedBy));
                });
            }

            return $order->fresh(['statusHistories']);
        });
    }

    /**
     * Whether a cancelled order may still be reactivated under the operational window.
     */
    public function canReactivate(Order $order): bool
    {
        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status !== OrderStatus::CANCELLED) {
            return false;
        }

        try {
            $this->assertWithinReactivationWindow($order);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function assertWithinReactivationWindow(Order $order): void
    {
        if ($order->operations_hidden_at !== null && $order->operations_hidden_at->lte(now())) {
            throw ValidationException::withMessages([
                'status' => ['This cancelled order is outside the operational window and cannot be reactivated.'],
            ]);
        }

        if ($order->cancelled_at !== null
            && $order->cancelled_at->copy()->addDays(self::OPERATIONAL_VISIBILITY_DAYS)->lte(now())
        ) {
            throw ValidationException::withMessages([
                'status' => ['This cancelled order is outside the operational window and cannot be reactivated.'],
            ]);
        }
    }

    /**
     * Shared cancellation side-effects for reseller and supplier portals.
     */
    private function releaseReservationsAndCancelFulfillment(Order $order, ?int $actorUserId): void
    {
        $reservations = StockReservation::query()
            ->where('order_id', $order->id)
            ->active()
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $reservation->update([
                'status' => StockReservationStatus::RELEASED,
                'released_at' => now(),
                'released_by' => $actorUserId,
            ]);
        }

        $fulfillment = $order->fulfillment()->lockForUpdate()->first();

        if ($fulfillment !== null && $fulfillment->cancelled_at === null) {
            $fulfillment->update([
                'cancelled_at' => now(),
                'cancelled_by' => $actorUserId,
                'updated_by' => $actorUserId,
            ]);
        }
    }

    private function resolveStatus(OrderStatus|string|Order $statusOrOrder): ?OrderStatus
    {
        if ($statusOrOrder instanceof OrderStatus) {
            return $statusOrOrder;
        }

        if ($statusOrOrder instanceof Order) {
            return $statusOrOrder->status instanceof OrderStatus
                ? $statusOrOrder->status
                : OrderStatus::tryFrom((string) $statusOrOrder->status);
        }

        return OrderStatus::tryFrom((string) $statusOrOrder);
    }
}
