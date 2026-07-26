<?php

namespace Feeder\Core\Contracts;

use Feeder\Core\Models\User;

interface PermissionServiceInterface
{
    public function hasPermission(User $user, string $permission): bool;
}
