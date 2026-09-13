<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderAssignmentState;
use Feeder\Core\Enums\OrderCcaAssignmentOrigin;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderCcaAssignment;
use Feeder\Core\Models\OrderStatusHistory;
use Feeder\Core\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderCcaAssignmentService
{
    public function __construct(
        private readonly CallCenterAgentEligibilityService $ccaEligibilityService,
        private readonly OrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * Assign or reassign a Call Center Agent (direct / manager assignment).
     *
     * @param  int|null  $actorCompanyId  When provided, enforces reseller-company boundary.
     */
    public function assign(
        Order $order,
        int $ccaId,
        ?int $assignedBy = null,
        ?string $note = null,
        ?int $actorCompanyId = null,
        OrderCcaAssignmentOrigin|string $origin = OrderCcaAssignmentOrigin::DIRECT,
    ): Order {
        $origin = $this->normalizeOrigin($origin);

        return DB::transaction(function () use ($order, $ccaId, $assignedBy, $note, $actorCompanyId, $origin) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->ccaEligibilityService->assertEligible($ccaId, (int) $order->reseller_company_id);

            $this->closeOpenAssignments((int) $order->id);

            OrderCcaAssignment::query()->create([
                'order_id' => $order->id,
                'cca_id' => $ccaId,
                'assigned_by' => $assignedBy,
                'origin' => $origin,
                'assigned_at' => now(),
                'note' => $note,
            ]);

            $order->update([
                'cca_id' => $ccaId,
                'available_in_pool' => false,
                'updated_by' => $assignedBy,
            ]);

            return $order->fresh(['cca', 'ccaAssignments']);
        });
    }

    /**
     * Remove the current CCA while leaving the order Unassigned (not in pool).
     */
    public function unassign(
        Order $order,
        ?int $actedBy = null,
        ?int $actorCompanyId = null,
    ): Order {
        return DB::transaction(function () use ($order, $actedBy, $actorCompanyId) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->closeOpenAssignments((int) $order->id);

            $order->update([
                'cca_id' => null,
                'available_in_pool' => false,
                'updated_by' => $actedBy,
            ]);

            return $order->fresh(['cca', 'ccaAssignments']);
        });
    }

    /**
     * Place the order into the company Order Pool.
     *
     * Pool state lives on orders.available_in_pool — assignment history is closed,
     * not used to encode pool membership.
     */
    public function moveToPool(
        Order $order,
        ?int $actedBy = null,
        ?int $actorCompanyId = null,
    ): Order {
        return DB::transaction(function () use ($order, $actedBy, $actorCompanyId) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->closeOpenAssignments((int) $order->id);

            $order->update([
                'cca_id' => null,
                'available_in_pool' => true,
                'updated_by' => $actedBy,
            ]);

            return $order->fresh(['cca', 'ccaAssignments']);
        });
    }

    /**
     * CCA self-claim from the Order Pool.
     *
     * Concurrency: row lock on the order + eligibility checks inside the transaction.
     * Domain rule: at most one active pool_claim without a subsequent status change.
     */
    public function claimFromPool(
        Order $order,
        User $cca,
        ?int $actorCompanyId = null,
    ): Order {
        return DB::transaction(function () use ($order, $cca, $actorCompanyId) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            $companyId = $actorCompanyId ?? (int) $cca->company_id;
            $this->authorizationGuard->assertOrderCompany($order, $companyId);

            $this->ccaEligibilityService->assertEligible((int) $cca->id, (int) $order->reseller_company_id);

            if (! $order->isInOrderPool()) {
                throw ValidationException::withMessages([
                    'order' => ['This order is not available in the Order Pool.'],
                ]);
            }

            $this->assertMayClaimAnotherPoolOrder($cca, (int) $order->reseller_company_id);

            $this->closeOpenAssignments((int) $order->id);

            OrderCcaAssignment::query()->create([
                'order_id' => $order->id,
                'cca_id' => $cca->id,
                'assigned_by' => $cca->id,
                'origin' => OrderCcaAssignmentOrigin::POOL_CLAIM,
                'assigned_at' => now(),
                'note' => null,
            ]);

            $order->update([
                'cca_id' => $cca->id,
                'available_in_pool' => false,
                'updated_by' => $cca->id,
            ]);

            return $order->fresh(['cca', 'ccaAssignments']);
        });
    }

    /**
     * @param  list<int|string>  $orderIds
     * @return Collection<int, Order>
     */
    public function bulkAssign(
        array $orderIds,
        int $ccaId,
        ?int $assignedBy = null,
        ?int $actorCompanyId = null,
        ?string $note = null,
    ): Collection {
        return DB::transaction(function () use ($orderIds, $ccaId, $assignedBy, $actorCompanyId, $note) {
            $orders = $this->lockOrdersForCompany($orderIds, $actorCompanyId);

            foreach ($orders as $order) {
                $this->assign(
                    $order,
                    $ccaId,
                    $assignedBy,
                    $note,
                    $actorCompanyId,
                    OrderCcaAssignmentOrigin::DIRECT,
                );
            }

            return $orders->map(fn (Order $order) => $order->fresh(['cca', 'ccaAssignments']))->values();
        });
    }

    /**
     * @param  list<int|string>  $orderIds
     * @return Collection<int, Order>
     */
    public function bulkMoveToPool(
        array $orderIds,
        ?int $actedBy = null,
        ?int $actorCompanyId = null,
    ): Collection {
        return DB::transaction(function () use ($orderIds, $actedBy, $actorCompanyId) {
            $orders = $this->lockOrdersForCompany($orderIds, $actorCompanyId);

            foreach ($orders as $order) {
                $this->moveToPool($order, $actedBy, $actorCompanyId);
            }

            return $orders->map(fn (Order $order) => $order->fresh(['cca', 'ccaAssignments']))->values();
        });
    }

    /**
     * @param  list<int|string>  $orderIds
     * @return Collection<int, Order>
     */
    public function bulkUnassign(
        array $orderIds,
        ?int $actedBy = null,
        ?int $actorCompanyId = null,
    ): Collection {
        return DB::transaction(function () use ($orderIds, $actedBy, $actorCompanyId) {
            $orders = $this->lockOrdersForCompany($orderIds, $actorCompanyId);

            foreach ($orders as $order) {
                $this->unassign($order, $actedBy, $actorCompanyId);
            }

            return $orders->map(fn (Order $order) => $order->fresh(['cca', 'ccaAssignments']))->values();
        });
    }

    /**
     * Whether the CCA is blocked from claiming another pool order.
     */
    public function hasBlockingActivePoolClaim(User $cca, ?int $resellerCompanyId = null): bool
    {
        return $this->blockingActivePoolClaim($cca, $resellerCompanyId) !== null;
    }

    /**
     * Open pool_claim assignment that has not yet seen a status transition after claim.
     */
    public function blockingActivePoolClaim(User $cca, ?int $resellerCompanyId = null): ?OrderCcaAssignment
    {
        $query = OrderCcaAssignment::query()
            ->where('cca_id', (int) $cca->id)
            ->where('origin', OrderCcaAssignmentOrigin::POOL_CLAIM->value)
            ->whereNull('unassigned_at')
            ->with('order')
            ->orderByDesc('id');

        if ($resellerCompanyId !== null) {
            $query->whereHas('order', fn ($q) => $q->where('reseller_company_id', $resellerCompanyId));
        }

        $assignments = $query->get();

        foreach ($assignments as $assignment) {
            if (! $this->hasStatusChangeAfterClaim($assignment)) {
                return $assignment;
            }
        }

        return null;
    }

    public function assignmentState(Order $order): OrderAssignmentState
    {
        return $order->assignmentState();
    }

    private function assertMayClaimAnotherPoolOrder(User $cca, int $resellerCompanyId): void
    {
        $blocking = $this->blockingActivePoolClaim($cca, $resellerCompanyId);

        if ($blocking === null) {
            return;
        }

        $orderNumber = $blocking->order?->order_number ?? ('#'.$blocking->order_id);

        throw ValidationException::withMessages([
            'order' => [
                'You already have an active Order Pool claim on '.$orderNumber
                .'. Update that order\'s status before claiming another pool order.',
            ],
        ]);
    }

    private function hasStatusChangeAfterClaim(OrderCcaAssignment $assignment): bool
    {
        // Same-second transitions must count; initial create rows have from_status = null.
        return OrderStatusHistory::query()
            ->where('order_id', $assignment->order_id)
            ->whereNotNull('from_status')
            ->where('created_at', '>=', $assignment->assigned_at)
            ->exists();
    }

    private function closeOpenAssignments(int $orderId): void
    {
        OrderCcaAssignment::query()
            ->where('order_id', $orderId)
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => now()]);
    }

    /**
     * @param  list<int|string>  $orderIds
     * @return Collection<int, Order>
     */
    private function lockOrdersForCompany(array $orderIds, ?int $actorCompanyId): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));

        if ($ids === []) {
            throw ValidationException::withMessages([
                'order_ids' => ['Select at least one order.'],
            ]);
        }

        $query = Order::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate();

        if ($actorCompanyId !== null) {
            $query->where('reseller_company_id', $actorCompanyId);
        }

        $orders = $query->get();

        if ($orders->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'order_ids' => ['One or more selected orders are invalid for this company.'],
            ]);
        }

        return $orders;
    }

    private function normalizeOrigin(OrderCcaAssignmentOrigin|string $origin): OrderCcaAssignmentOrigin
    {
        if ($origin instanceof OrderCcaAssignmentOrigin) {
            return $origin;
        }

        $parsed = OrderCcaAssignmentOrigin::tryFrom($origin);

        if ($parsed === null) {
            throw ValidationException::withMessages([
                'origin' => ['Invalid CCA assignment origin.'],
            ]);
        }

        return $parsed;
    }
}
