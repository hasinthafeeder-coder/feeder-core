<?php

namespace Feeder\Core\Authorization\Services;

use Feeder\Core\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PermissionService
{
    public function hasPermission(User $user, string $permission): bool
    {
        return $this->getEffectivePermissions($user)
            ->contains($permission);
    }

    public function hasAnyPermission(User $user, array $permissions): bool
    {
        return $this->getEffectivePermissions($user)
            ->intersect($permissions)->isNotEmpty();
    }

    public function hasAllPermissions(User $user, array $permissions): bool
    {
        return collect($permissions)->diff($this->getEffectivePermissions($user))->isEmpty();
    }

    // public function getEffectivePermissions(User $user): Collection
    // {
    //     return Cache::rememberForever(
    //         $this->getCacheKey($user),
    //         function () use ($user) {
    //             if (!$user->role) {
    //                 return collect();
    //             }

    //             $permissions = $user->role
    //                 ->permissions()
    //                 ->pluck('slug')
    //                 ->flip();

    //             $user->directPermissions()
    //                 ->select('permissions.slug', 'user_permissions.allowed')
    //                 ->get()
    //                 ->each(function ($permission) use ($permissions) {
    //                     if ($permission->pivot->allowed) {
    //                         $permissions->put($permission->slug, true);
    //                     } else {
    //                         $permissions->forget($permission->slug);
    //                     }
    //                 });

    //             return $permissions->keys()->values();
    //         }
    //     );
    // }

    public function getEffectivePermissions(User $user): Collection
    {
        $permissions = Cache::rememberForever(
            $this->getCacheKey($user),
            function () use ($user) {
                if (!$user->role) {
                    return [];
                }

                $permissions = $user->role
                    ->permissions()
                    ->pluck('slug')
                    ->flip();

                $user->directPermissions()
                    ->select('permissions.slug', 'user_permissions.allowed')
                    ->get()
                    ->each(function ($permission) use ($permissions) {
                        if ($permission->pivot->allowed) {
                            $permissions->put($permission->slug, true);
                        } else {
                            $permissions->forget($permission->slug);
                        }
                    });

                return $permissions->keys()->values()->all();
            }
        );

        return collect($permissions);
    }

    public function forgetCache(User $user): void
    {
        Cache::forget($this->getCacheKey($user));
    }

    public function rebuildCache(User $user): Collection
    {
        $this->forgetCache($user);
        return $this->getEffectivePermissions($user);
    }

    protected function getCacheKey(User $user): string
    {
        return "permissions:user:{$user->id}";
    }
}
