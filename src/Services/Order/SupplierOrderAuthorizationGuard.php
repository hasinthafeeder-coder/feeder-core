<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Domain authorization boundary for supplier fulfillment operations.
 *
 * Controllers must still enforce permission slugs; this guard enforces
 * that the authenticated supplier owns the order via orders.supplier_id.
 */
class SupplierOrderAuthorizationGuard
{
    public function assertSupplierActor(int $supplierId): User
    {
        $supplier = User::query()->find($supplierId);

        if ($supplier === null || $supplier->trashed()) {
            throw ValidationException::withMessages([
                'supplier_id' => ['The selected supplier is invalid.'],
            ]);
        }

        $status = $supplier->status instanceof UserStatus
            ? $supplier->status
            : UserStatus::tryFrom((string) $supplier->status);

        if ($status !== UserStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'supplier_id' => ['The supplier account is not active.'],
            ]);
        }

        return $supplier;
    }

    public function assertOwnsOrder(Order $order, int $supplierId): void
    {
        if ((int) $order->supplier_id !== $supplierId) {
            throw ValidationException::withMessages([
                'order' => ['You are not authorized to fulfill this order.'],
            ]);
        }
    }

    /**
     * @return array{supplier: User, order: Order}
     */
    public function assertSupplierOrderAccess(Order $order, int $supplierId): array
    {
        $supplier = $this->assertSupplierActor($supplierId);
        $this->assertOwnsOrder($order, $supplierId);

        return [
            'supplier' => $supplier,
            'order' => $order,
        ];
    }
}
