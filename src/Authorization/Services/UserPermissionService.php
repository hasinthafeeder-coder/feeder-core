<?php

namespace Feeder\Core\Authorization\Services;

use Feeder\Core\Models\User;

class UserPermissionService
{
    /**
     * Full replacement of the user's explicitly allowed overrides.
     *
     * Each listed permission becomes allowed=true.
     * Existing allowed=true overrides not in the list are removed.
     * Explicit denies (allowed=false) are preserved unless the same
     * permission id is included (in which case it becomes allowed=true).
     *
     * @param  list<int>  $permissionIds
     */
    public function sync(User $user, array $permissionIds): void
    {
        $this->syncAllowed($user, $permissionIds);
    }

    /**
     * Synchronize the user's explicitly allowed permission overrides.
     *
     * @param  list<int>  $permissionIds
     */
    public function syncAllowed(User $user, array $permissionIds): void
    {
        $permissionIds = $this->normalizePermissionIds($permissionIds);

        $currentAllowedIds = $user->directPermissions()
            ->wherePivot('allowed', true)
            ->pluck('permissions.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $idsToRemove = array_values(array_diff($currentAllowedIds, $permissionIds));

        if ($idsToRemove !== []) {
            $user->directPermissions()->detach($idsToRemove);
        }

        if ($permissionIds !== []) {
            $payload = [];

            foreach ($permissionIds as $permissionId) {
                $payload[$permissionId] = ['allowed' => true];
            }

            $user->directPermissions()->syncWithoutDetaching($payload);
        }

        $this->forgetUserPermissionCache($user);
    }

    /**
     * Full replacement of all user-level overrides.
     *
     * Map format: [permissionId => allowed(bool)]
     * Any override not present in the map is removed.
     *
     * @param  array<int, bool>  $permissionAllowedMap
     */
    public function syncOverrides(User $user, array $permissionAllowedMap): void
    {
        $payload = [];

        foreach ($permissionAllowedMap as $permissionId => $allowed) {
            $payload[(int) $permissionId] = [
                'allowed' => (bool) $allowed,
            ];
        }

        $user->directPermissions()->sync($payload);

        $this->forgetUserPermissionCache($user);
    }

    public function grant(User $user, int $permissionId): void
    {
        $user->directPermissions()->syncWithoutDetaching([
            $permissionId => ['allowed' => true],
        ]);

        $this->forgetUserPermissionCache($user);
    }

    public function deny(User $user, int $permissionId): void
    {
        $user->directPermissions()->syncWithoutDetaching([
            $permissionId => ['allowed' => false],
        ]);

        $this->forgetUserPermissionCache($user);
    }

    /**
     * Remove the user-level override and restore role inheritance.
     */
    public function revoke(User $user, int $permissionId): void
    {
        $user->directPermissions()->detach($permissionId);

        $this->forgetUserPermissionCache($user);
    }

    /**
     * @param  list<int|string>  $permissionIds
     * @return list<int>
     */
    protected function normalizePermissionIds(array $permissionIds): array
    {
        $normalized = array_map(
            static fn ($id): int => (int) $id,
            $permissionIds
        );

        return array_values(array_unique($normalized));
    }

    protected function forgetUserPermissionCache(User $user): void
    {
        app(PermissionService::class)
            ->forgetCache($user);
    }
}
