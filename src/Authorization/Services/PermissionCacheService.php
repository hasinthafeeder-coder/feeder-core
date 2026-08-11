<?php

namespace Feeder\Core\Authorization\Services;

use Feeder\Core\Models\Role;
use Feeder\Core\Models\User;

class PermissionCacheService
{
    public function clearUser(User $user): void
    {
        app(PermissionService::class)
            ->forgetCache($user);
    }

    public function clearRoleUsers(Role $role): void
    {
        $role->users()
            ->each(function (User $user) {
                $this->clearUser($user);
            });
    }
}
