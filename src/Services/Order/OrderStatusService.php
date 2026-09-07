<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderStatusHistory;
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
     * Transition an order to any status. Sequential transitions are intentionally not enforced.
     *
     * On transition into CONFIRMED:
     * - recalculate customer payable from current snapshots
     * - lock discount via discount_locked_at
     *
     * Status changes after CONFIRMED do not unlock the discount.
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

            $fromStatus = $order->status;

            if ($fromStatus === $toStatus) {
                return $order->fresh(['statusHistories']);
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

            if ($fromStatus === OrderStatus::CANCELLED && $toStatus !== OrderStatus::CANCELLED) {
                $this->assertWithinReactivationWindow($order);
                $attributes['reactivated_at'] = now();
                $attributes['operations_hidden_at'] = null;
            }

            $order->update($attributes);

            if ($toStatus === OrderStatus::CONFIRMED) {
                $this->discountService->lockOnConfirmation($order->fresh());
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by' => $changedBy,
                'changed_by_company_id' => $changedByCompanyId,
                'reason' => $reason,
            ]);

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
}
