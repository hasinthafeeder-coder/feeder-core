<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Models\Role;
use Feeder\Core\Models\User;
use Illuminate\Validation\ValidationException;

class PortalOwnerRoleService
{
    public function resolveOwnerRole(PortalCode $portalCode): Role
    {
        $role = Role::query()
            ->where('slug', 'owner')
            ->whereHas('portal', fn ($query) => $query->where('code', $portalCode->value))
            ->first();

        if (! $role) {
            throw ValidationException::withMessages([
                'role' => sprintf('The %s portal owner role could not be resolved.', $portalCode->value),
            ]);
        }

        return $role;
    }

    public function assignOwnerRole(User $user, PortalCode $portalCode): void
    {
        $role = $this->resolveOwnerRole($portalCode);

        $user->update(['role_id' => $role->id]);
    }
}
