<?php

namespace Feeder\Core\Authorization\Services;

use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Role;

class RolePermissionService
{
    public function sync(Role $role, array $permissionIds): void
    {
        $role->permissions()->sync($permissionIds);

        app(PermissionCacheService::class)
            ->clearRoleUsers($role);
    }

    public function grant(Role $role, int $permissionId): void
    {
        $role->permissions()->syncWithoutDetaching([
            $permissionId,
        ]);

        app(PermissionCacheService::class)
            ->clearRoleUsers($role);
    }

    public function revoke(Role $role, int $permissionId): void
    {
        $role->permissions()->detach($permissionId);

        app(PermissionCacheService::class)
            ->clearRoleUsers($role);
    }
}
