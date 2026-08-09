<?php

namespace Feeder\Core\Authorization\Traits;

use Feeder\Core\Authorization\Services\PermissionService;
use Illuminate\Support\Collection;

trait HasPermissions
{
    public function hasPermission(string $permission): bool
    {
        return app(PermissionService::class)
            ->hasPermission($this, $permission);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return app(PermissionService::class)
            ->hasAnyPermission($this, $permissions);
    }

    public function hasAllPermissions(array $permissions): bool
    {
        return app(PermissionService::class)
            ->hasAllPermissions($this, $permissions);
    }

    public function permissions(): Collection
    {
        return app(PermissionService::class)
            ->getEffectivePermissions($this);
    }
}
