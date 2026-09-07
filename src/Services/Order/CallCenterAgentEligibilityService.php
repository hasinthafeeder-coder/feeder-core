<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Shared CCA eligibility checks for order assignment.
 *
 * Mirrors feeder-reseller AgentService::ROLE_SLUG / isCallCenterAgent()
 * so feeder-core does not depend on the reseller application layer.
 */
class CallCenterAgentEligibilityService
{
    /**
     * Must stay aligned with App\Services\CallCenter\AgentService::ROLE_SLUG.
     */
    public const ROLE_SLUG = 'call-center-agent';

    public function assertEligible(int $ccaId, int $resellerCompanyId): User
    {
        $user = User::query()->with(['role.portal'])->find($ccaId);

        if ($user === null || $user->trashed()) {
            throw ValidationException::withMessages([
                'cca_id' => ['The selected call center agent is invalid.'],
            ]);
        }

        $status = $user->status instanceof UserStatus
            ? $user->status
            : UserStatus::tryFrom((string) $user->status);

        if ($status !== UserStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'cca_id' => ['The selected call center agent is not active.'],
            ]);
        }

        $userType = $user->user_type instanceof UserType
            ? $user->user_type->value
            : (string) $user->user_type;

        if ($userType !== UserType::EMPLOYEE->value) {
            throw ValidationException::withMessages([
                'cca_id' => ['The selected user is not a call center agent.'],
            ]);
        }

        if ((int) $user->company_id !== $resellerCompanyId) {
            throw ValidationException::withMessages([
                'cca_id' => ['The call center agent must belong to the order reseller company.'],
            ]);
        }

        $role = $user->role;

        if ($role === null || $role->trashed() || $role->slug !== self::ROLE_SLUG) {
            throw ValidationException::withMessages([
                'cca_id' => ['The selected user does not have the Call Center Agent role.'],
            ]);
        }

        if ($role->portal?->code !== PortalCode::RESELLER->value) {
            throw ValidationException::withMessages([
                'cca_id' => ['The selected user does not have the Call Center Agent role.'],
            ]);
        }

        return $user;
    }

    public function isEligible(User $user, int $resellerCompanyId): bool
    {
        try {
            $this->assertEligible((int) $user->id, $resellerCompanyId);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
