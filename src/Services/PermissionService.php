<?php

namespace Feeder\Core\Services;

use Feeder\Core\Contracts\PermissionServiceInterface;
use Feeder\Core\Models\User;

class PermissionService implements PermissionServiceInterface
{
    public function hasPermission(User $user, string $permission): bool
    {
        return false;
    }
}
