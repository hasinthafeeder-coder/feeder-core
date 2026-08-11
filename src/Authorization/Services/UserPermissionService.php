<?php

namespace Feeder\Core\Authorization\Services;

use Feeder\Core\Models\User;

class UserPermissionService
{
    public function sync(User $user, array $permissionIds): void
    {
        $user->directPermissions()->sync($permissionIds);

        app(PermissionService::class)
            ->forgetCache($user);
    }

    public function grant(User $user, int $permissionId): void
    {
        $user->directPermissions()->syncWithoutDetaching([
            $permissionId,
        ]);

        app(PermissionService::class)
            ->forgetCache($user);
    }

    public function revoke(User $user, int $permissionId): void
    {
        $user->directPermissions()->detach($permissionId);

        app(PermissionService::class)
            ->forgetCache($user);
    }
}
