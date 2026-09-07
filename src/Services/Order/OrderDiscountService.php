<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderDiscountService
{
    public function __construct(
        private readonly OrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * Apply or update discount before confirmation.
     *
     * @param  int|null  $actorCompanyId  When provided, enforces reseller-company boundary.
     */
    public function apply(
        Order $order,
        float|string $discountAmount,
        ?int $updatedBy = null,
        ?int $actorCompanyId = null,
    ): Order {
        return DB::transaction(function () use ($order, $discountAmount, $updatedBy, $actorCompanyId) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->assertDiscountMutable($order);

            $discount = round((float) $discountAmount, 2);
            $itemsSubtotal = round((float) $order->items_subtotal, 2);
            $courierFee = round((float) $order->courier_fee_amount, 2);

            if ($discount < 0) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot be negative.'],
                ]);
            }

            if ($discount > $itemsSubtotal) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot exceed the items subtotal.'],
                ]);
            }

            $customerPayable = round($itemsSubtotal - $discount + $courierFee, 2);

            if ($customerPayable < 0) {
                throw ValidationException::withMessages([
                    'customer_payable_amount' => ['Customer payable cannot be negative.'],
                ]);
            }

            $order->update([
                'discount_amount' => $discount,
                'customer_payable_amount' => $customerPayable,
                'updated_by' => $updatedBy,
            ]);

            return $order->fresh();
        });
    }

    public function assertDiscountMutable(Order $order): void
    {
        if ($order->discount_locked_at !== null) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Discount is locked after confirmation and cannot be modified.'],
            ]);
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::from((string) $order->status);

        if ($status === OrderStatus::CONFIRMED || $order->confirmed_at !== null) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Discount is locked after confirmation and cannot be modified.'],
            ]);
        }
    }

    /**
     * Lock the current discount snapshot when transitioning into CONFIRMED.
     */
    public function lockOnConfirmation(Order $order): void
    {
        $order->forceFill([
            'discount_locked_at' => $order->discount_locked_at ?? now(),
            'customer_payable_amount' => round(
                (float) $order->items_subtotal - (float) $order->discount_amount + (float) $order->courier_fee_amount,
                2
            ),
        ])->save();
    }
}
