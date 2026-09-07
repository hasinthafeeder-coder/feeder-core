<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderCcaAssignment;
use Illuminate\Support\Facades\DB;

class OrderCcaAssignmentService
{
    public function __construct(
        private readonly CallCenterAgentEligibilityService $ccaEligibilityService,
        private readonly OrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * Assign or reassign a Call Center Agent.
     *
     * @param  int|null  $actorCompanyId  When provided, enforces reseller-company boundary.
     */
    public function assign(
        Order $order,
        int $ccaId,
        ?int $assignedBy = null,
        ?string $note = null,
        ?int $actorCompanyId = null,
    ): Order {
        return DB::transaction(function () use ($order, $ccaId, $assignedBy, $note, $actorCompanyId) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->ccaEligibilityService->assertEligible($ccaId, (int) $order->reseller_company_id);

            OrderCcaAssignment::query()
                ->where('order_id', $order->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            OrderCcaAssignment::query()->create([
                'order_id' => $order->id,
                'cca_id' => $ccaId,
                'assigned_by' => $assignedBy,
                'assigned_at' => now(),
                'note' => $note,
            ]);

            $order->update([
                'cca_id' => $ccaId,
                'updated_by' => $assignedBy,
            ]);

            return $order->fresh(['cca', 'ccaAssignments']);
        });
    }
}
