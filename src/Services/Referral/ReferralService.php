<?php

namespace Feeder\Core\Services\Referral;

use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\ReferralCode;
use Feeder\Core\Models\ReferralRelationship;
use Feeder\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function getTreeRootUser(): ?User
    {
        $masterUser = User::query()
            ->where('is_master_reseller', true)
            ->where('user_type', UserType::OWNER->value)
            ->first();

        if ($masterUser !== null) {
            return $masterUser;
        }

        $topParentUserId = ReferralRelationship::query()
            ->from('referral_relationships as rr')
            ->whereNull('rr.deleted_at')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('referral_relationships as rr_parent')
                    ->whereColumn('rr_parent.child_user_id', 'rr.parent_user_id')
                    ->whereNull('rr_parent.deleted_at');
            })
            ->orderBy('rr.parent_user_id')
            ->value('rr.parent_user_id');

        if ($topParentUserId !== null) {
            return User::query()->find($topParentUserId);
        }

        return null;
    }

    public function getNodeData(User $user): ?array
    {
        $nodes = $this->getNodeDataByUserIds([$user->id]);

        return $nodes[$user->id] ?? null;
    }

    public function getNodeDataByUserIds(array $userIds): array
    {
        $userIds = collect($userIds)
            ->map(fn($id) => (int) $id)
            ->filter(fn(int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return [];
        }

        $users = User::query()
            ->with([
                'profile:user_id,first_name,last_name',
                'company:id,name,portal_id,status',
                'company.portal:id,code',
            ])
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        if ($users->isEmpty()) {
            return [];
        }

        $existingIds = $users->keys()->map(fn($id) => (int) $id)->values()->all();
        $directCounts = $this->getDirectReferralCounts($existingIds);
        $totalCounts = $this->getTotalReferralCounts($existingIds);

        $nodes = [];

        foreach ($users as $user) {
            $directReferrals = (int) ($directCounts[$user->id] ?? 0);
            $totalReferrals = (int) ($totalCounts[$user->id] ?? 0);
            $fullName = trim((string) (($user->profile?->first_name ?? '') . ' ' . ($user->profile?->last_name ?? '')));

            $nodes[$user->id] = [
                'user_id' => (int) $user->id,
                'user_uuid' => $user->uuid,
                'user_label' => '#' . $user->id,
                'user_name' => $fullName !== '' ? $fullName : $user->email,
                'company_name' => $user->company?->name ?? 'N/A',
                'portal_code' => $user->company?->portal?->code,
                'status' => $user->status?->value ?? (string) $user->status,
                'is_master_reseller' => (bool) $user->is_master_reseller,
                'total_referrals' => $totalReferrals,
                'direct_referrals' => $directReferrals,
                'has_children' => $directReferrals > 0,
            ];
        }

        return $nodes;
    }

    public function getChildrenNodeData(User $parentUser, int $limit = 100): array
    {
        $safeLimit = max(1, min($limit, 200));

        $childUserIds = ReferralRelationship::query()
            ->where('parent_user_id', $parentUser->id)
            ->orderBy('child_user_id')
            ->limit($safeLimit)
            ->pluck('child_user_id')
            ->map(fn($id) => (int) $id)
            ->all();

        $childrenById = $this->getNodeDataByUserIds($childUserIds);

        return collect($childUserIds)
            ->map(fn(int $id) => $childrenById[$id] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    public function searchTreeUsers(string $query, int $limit = 10): array
    {
        $normalized = trim($query);

        if ($normalized === '') {
            return [];
        }

        $safeLimit = max(1, min($limit, 20));
        $term = '%' . $normalized . '%';

        $users = User::query()
            ->select('users.id', 'users.uuid', 'users.email')
            ->with([
                'profile:user_id,first_name,last_name',
                'company:id,name,portal_id',
                'company.portal:id,code',
            ])
            ->where('users.user_type', UserType::OWNER->value)
            ->where(function (Builder $queryBuilder): void {
                $queryBuilder->where('users.is_master_reseller', true)
                    ->orWhereExists(function (Builder $relationQuery): void {
                        $relationQuery->selectRaw('1')
                            ->from('referral_relationships as rr_parent')
                            ->whereColumn('rr_parent.parent_user_id', 'users.id')
                            ->whereNull('rr_parent.deleted_at');
                    })
                    ->orWhereExists(function (Builder $relationQuery): void {
                        $relationQuery->selectRaw('1')
                            ->from('referral_relationships as rr_child')
                            ->whereColumn('rr_child.child_user_id', 'users.id')
                            ->whereNull('rr_child.deleted_at');
                    });
            })
            ->where(function (Builder $queryBuilder) use ($term): void {
                $queryBuilder->whereRaw('CAST(users.id AS CHAR) LIKE ?', [$term])
                    ->orWhere('users.email', 'like', $term)
                    ->orWhere('users.phone', 'like', $term)
                    ->orWhereHas('profile', function (Builder $profileQuery) use ($term): void {
                        $profileQuery->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term);
                    })
                    ->orWhereHas('company', function (Builder $companyQuery) use ($term): void {
                        $companyQuery->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term)
                            ->orWhere('phone', 'like', $term);
                    });
            })
            ->orderByDesc('users.is_master_reseller')
            ->orderBy('users.id')
            ->limit($safeLimit)
            ->get();

        return $users->map(function (User $user): array {
            $fullName = trim((string) (($user->profile?->first_name ?? '') . ' ' . ($user->profile?->last_name ?? '')));
            $displayName = $user->company?->name
                ?? ($fullName !== '' ? $fullName : $user->email);

            return [
                'user_id' => (int) $user->id,
                'user_uuid' => $user->uuid,
                'user_label' => '#' . $user->id,
                'display_name' => $displayName,
                'company_name' => $user->company?->name,
                'portal_code' => $user->company?->portal?->code,
                'label' => '#' . $user->id . ' — ' . $displayName,
            ];
        })->values()->all();
    }

    public function getUserDepthFromRoot(User $rootUser, User $targetUser, int $maxDepth): ?int
    {
        $safeDepth = max(0, $maxDepth);

        $rows = DB::select(
            <<<'SQL'
            WITH RECURSIVE scoped_tree AS (
                SELECT ? AS user_id, 0 AS depth
                UNION ALL
                SELECT rr.child_user_id AS user_id, scoped_tree.depth + 1 AS depth
                FROM scoped_tree
                JOIN referral_relationships AS rr
                    ON rr.parent_user_id = scoped_tree.user_id
                    AND rr.deleted_at IS NULL
                WHERE scoped_tree.depth < ?
            )
            SELECT user_id, depth
            FROM scoped_tree
            WHERE user_id = ?
            LIMIT 1
            SQL,
            [$rootUser->id, $safeDepth, $targetUser->id]
        );

        if ($rows === []) {
            return null;
        }

        return (int) $rows[0]->depth;
    }

    public function getScopedChildrenNodeData(
        User $rootUser,
        User $parentUser,
        int $maxDepth,
        int $limit = 100
    ): array {
        $safeDepth = max(0, $maxDepth);
        $safeLimit = max(1, min($limit, 200));

        if ($safeDepth < 1) {
            return [];
        }

        $parentDepth = $this->getUserDepthFromRoot($rootUser, $parentUser, $safeDepth);

        if ($parentDepth === null || $parentDepth >= $safeDepth) {
            return [];
        }

        $childUserIds = ReferralRelationship::query()
            ->where('parent_user_id', $parentUser->id)
            ->orderBy('child_user_id')
            ->limit($safeLimit)
            ->pluck('child_user_id')
            ->map(fn($id) => (int) $id)
            ->all();

        if ($childUserIds === []) {
            return [];
        }

        $childrenById = $this->getNodeDataByUserIds($childUserIds);
        $childDepth = $parentDepth + 1;
        $canExpandChildren = $childDepth < $safeDepth;

        return collect($childUserIds)
            ->map(function (int $childUserId) use ($childrenById, $canExpandChildren): ?array {
                $node = $childrenById[$childUserId] ?? null;

                if ($node === null) {
                    return null;
                }

                $node['has_children'] = $canExpandChildren && ((int) $node['direct_referrals'] > 0);

                return $node;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function searchTreeUsersWithinDepth(
        User $rootUser,
        string $query,
        int $maxDepth,
        int $limit = 10
    ): array {
        $normalized = trim($query);

        if ($normalized === '') {
            return [];
        }

        $safeDepth = max(0, $maxDepth);
        $safeLimit = max(1, min($limit, 20));
        $term = '%' . $normalized . '%';

        $rows = DB::select(
            <<<'SQL'
            WITH RECURSIVE scoped_tree AS (
                SELECT ? AS user_id, 0 AS depth
                UNION ALL
                SELECT rr.child_user_id AS user_id, scoped_tree.depth + 1 AS depth
                FROM scoped_tree
                JOIN referral_relationships AS rr
                    ON rr.parent_user_id = scoped_tree.user_id
                    AND rr.deleted_at IS NULL
                WHERE scoped_tree.depth < ?
            )
            SELECT
                users.id AS user_id,
                users.uuid AS user_uuid,
                users.email AS user_email,
                users.phone AS user_phone,
                users.user_type,
                users.is_master_reseller,
                user_profiles.first_name,
                user_profiles.last_name,
                companies.name AS company_name,
                companies.email AS company_email,
                companies.phone AS company_phone,
                portals.code AS portal_code,
                scoped_tree.depth
            FROM scoped_tree
            JOIN users ON users.id = scoped_tree.user_id
            LEFT JOIN user_profiles ON user_profiles.user_id = users.id
            LEFT JOIN companies ON companies.id = users.company_id
            LEFT JOIN portals ON portals.id = companies.portal_id
            WHERE users.user_type = ?
              AND (
                CAST(users.id AS CHAR) LIKE ?
                OR users.email LIKE ?
                OR users.phone LIKE ?
                OR COALESCE(user_profiles.first_name, '') LIKE ?
                OR COALESCE(user_profiles.last_name, '') LIKE ?
                OR COALESCE(companies.name, '') LIKE ?
                OR COALESCE(companies.email, '') LIKE ?
                OR COALESCE(companies.phone, '') LIKE ?
              )
            ORDER BY scoped_tree.depth ASC, users.id ASC
            LIMIT ?
            SQL,
            [
                $rootUser->id,
                $safeDepth,
                UserType::OWNER->value,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $safeLimit,
            ]
        );

        return collect($rows)->map(function ($row): array {
            $fullName = trim((string) (($row->first_name ?? '') . ' ' . ($row->last_name ?? '')));
            $displayName = $row->company_name ?: ($fullName !== '' ? $fullName : ($row->user_email ?? 'N/A'));

            return [
                'user_id' => (int) $row->user_id,
                'user_uuid' => $row->user_uuid,
                'user_label' => '#' . $row->user_id,
                'display_name' => $displayName,
                'company_name' => $row->company_name,
                'portal_code' => $row->portal_code,
                'label' => '#' . $row->user_id . ' — ' . $displayName,
            ];
        })->values()->all();
    }

    public function getDepthScopedPathNodeData(User $targetUser, User $rootUser, int $maxDepth): array
    {
        $safeDepth = max(0, $maxDepth);
        $path = $this->getPathNodeData($targetUser, $rootUser);

        if ($path === []) {
            return [];
        }

        $depth = count($path) - 1;

        if ($depth > $safeDepth) {
            return [];
        }

        return collect($path)
            ->values()
            ->map(function (array $node, int $index) use ($safeDepth): array {
                $node['has_children'] = $index < $safeDepth && ((int) ($node['direct_referrals'] ?? 0) > 0);

                return $node;
            })
            ->all();
    }

    public function getPathNodeData(User $targetUser, ?User $rootUser = null): array
    {
        $rows = DB::select(
            <<<'SQL'
            WITH RECURSIVE ancestors AS (
                SELECT users.id AS user_id, rr.parent_user_id, 0 AS depth
                FROM users
                LEFT JOIN referral_relationships AS rr
                    ON rr.child_user_id = users.id
                    AND rr.deleted_at IS NULL
                WHERE users.id = ?
                UNION ALL
                SELECT parent_user.id AS user_id, parent_rr.parent_user_id, ancestors.depth + 1 AS depth
                FROM ancestors
                JOIN users AS parent_user ON parent_user.id = ancestors.parent_user_id
                LEFT JOIN referral_relationships AS parent_rr
                    ON parent_rr.child_user_id = parent_user.id
                    AND parent_rr.deleted_at IS NULL
                WHERE ancestors.parent_user_id IS NOT NULL
            )
            SELECT user_id, depth
            FROM ancestors
            ORDER BY depth DESC
            SQL,
            [$targetUser->id]
        );

        if ($rows === []) {
            return [];
        }

        $orderedPathIds = collect($rows)
            ->map(fn($row) => (int) $row->user_id)
            ->values()
            ->all();

        if ($rootUser !== null) {
            $rootIndex = array_search($rootUser->id, $orderedPathIds, true);

            if ($rootIndex === false) {
                return [];
            }

            $orderedPathIds = array_slice($orderedPathIds, $rootIndex);
        }

        $nodeMap = $this->getNodeDataByUserIds($orderedPathIds);

        return collect($orderedPathIds)
            ->map(fn(int $id) => $nodeMap[$id] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    public function generateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $code = strtoupper(Str::random(8));

            if (! ReferralCode::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique referral code.');
    }

    public function ensureUserHasReferralCode(User $user): ReferralCode
    {
        $referralCode = $user->referralCode()->first();

        if ($referralCode) {
            return $referralCode;
        }

        return DB::transaction(function () use ($user): ReferralCode {
            $existing = $user->referralCode()->first();

            if ($existing) {
                return $existing;
            }

            return $user->referralCode()->create([
                'uuid' => (string) Str::uuid(),
                'code' => $this->generateUniqueCode(),
                'is_active' => true,
                'user_id' => $user->id,
            ]);
        });
    }

    public function resolveCode(?string $code): ?ReferralCode
    {
        $normalized = trim((string) ($code ?? ''));

        if ($normalized === '') {
            return null;
        }

        return ReferralCode::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper($normalized)])
            ->first();
    }

    public function resolveReferralCode(?string $code): ?ReferralCode
    {
        return $this->resolveCode($code);
    }

    public function validateCode(?string $code, ?User $candidateUser = null): ReferralCode
    {
        $referralCode = $this->resolveCode($code);

        if ($referralCode === null) {
            throw ValidationException::withMessages([
                'ref' => 'Invalid referral code.',
            ]);
        }

        $parentUser = $referralCode->user;

        if (! $parentUser) {
            throw ValidationException::withMessages([
                'ref' => 'Invalid referral code.',
            ]);
        }

        if ($parentUser->user_type !== UserType::OWNER->value) {
            throw ValidationException::withMessages([
                'ref' => 'Referral code belongs to a non-reseller account.',
            ]);
        }

        if (! $referralCode->is_active) {
            throw ValidationException::withMessages([
                'ref' => 'This referral link is inactive.',
            ]);
        }

        if ($candidateUser && $parentUser->id === $candidateUser->id) {
            throw ValidationException::withMessages([
                'ref' => 'You cannot refer yourself.',
            ]);
        }

        return $referralCode;
    }

    public function validateReferralCode(?string $code, ?User $candidateUser = null): ReferralCode
    {
        return $this->validateCode($code, $candidateUser);
    }

    public function toggleActivation(User $user, bool $isActive, User $actor): ReferralCode
    {
        $referralCode = $user->referralCode()->firstOrFail();

        $referralCode->update([
            'is_active' => $isActive,
            'activated_by_user_id' => $isActive ? $actor->id : $referralCode->activated_by_user_id,
            'activated_at' => $isActive ? now() : $referralCode->activated_at,
            'deactivated_at' => $isActive ? $referralCode->deactivated_at : now(),
            'last_changed_by_user_id' => $actor->id,
        ]);

        return $referralCode->fresh();
    }

    public function createRelationship(User $childUser, string $code, ?User $actor = null): ReferralRelationship
    {
        $referralCode = $this->validateCode($code, $childUser);
        $parentUser = $referralCode->user;

        if ($parentUser === null) {
            throw ValidationException::withMessages([
                'ref' => 'Invalid referral code.',
            ]);
        }

        if ($parentUser->id === $childUser->id) {
            throw ValidationException::withMessages([
                'ref' => 'You cannot refer yourself.',
            ]);
        }

        return DB::transaction(function () use ($childUser, $parentUser, $referralCode, $actor): ReferralRelationship {
            if ($childUser->parentReseller()->exists()) {
                throw ValidationException::withMessages([
                    'ref' => 'This reseller already has a referral parent.',
                ]);
            }

            if ($this->wouldCreateCircularRelationship($childUser, $parentUser)) {
                throw ValidationException::withMessages([
                    'ref' => 'Circular referral relationships are not allowed.',
                ]);
            }

            $relationship = ReferralRelationship::query()->where('child_user_id', $childUser->id)->first();

            if ($relationship) {
                throw ValidationException::withMessages([
                    'ref' => 'This reseller already has a referral parent.',
                ]);
            }

            $newRelationship = ReferralRelationship::query()->create([
                'uuid' => (string) Str::uuid(),
                'parent_user_id' => $parentUser->id,
                'child_user_id' => $childUser->id,
                'source_referral_code_id' => $referralCode->id,
            ]);

            if ($actor) {
                $referralCode->update([
                    'last_changed_by_user_id' => $actor->id,
                ]);
            }

            return $newRelationship;
        });
    }

    public function createPermanentRelationship(User $childUser, string $code, ?User $actor = null): ReferralRelationship
    {
        return $this->createRelationship($childUser, $code, $actor);
    }

    public function wouldCreateCircularRelationship(User $childUser, User $parentUser): bool
    {
        $current = $parentUser;

        while ($current) {
            $ancestor = $current->parentReseller()->first();

            if ($ancestor === null) {
                return false;
            }

            $ancestorUser = $ancestor->parent;

            if ($ancestorUser && $ancestorUser->id === $childUser->id) {
                return true;
            }

            $current = $ancestorUser;
        }

        return false;
    }

    private function getDirectReferralCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return ReferralRelationship::query()
            ->selectRaw('parent_user_id, COUNT(*) as direct_count')
            ->whereIn('parent_user_id', $userIds)
            ->groupBy('parent_user_id')
            ->pluck('direct_count', 'parent_user_id')
            ->map(fn($count) => (int) $count)
            ->all();
    }

    private function getTotalReferralCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));

        $rows = DB::select(
            <<<SQL
            WITH RECURSIVE referral_tree AS (
                SELECT rr.parent_user_id AS root_user_id, rr.child_user_id
                FROM referral_relationships AS rr
                WHERE rr.deleted_at IS NULL
                  AND rr.parent_user_id IN ({$placeholders})
                UNION ALL
                SELECT referral_tree.root_user_id, rr.child_user_id
                FROM referral_tree
                JOIN referral_relationships AS rr
                    ON rr.parent_user_id = referral_tree.child_user_id
                WHERE rr.deleted_at IS NULL
            )
            SELECT root_user_id, COUNT(DISTINCT child_user_id) AS total_count
            FROM referral_tree
            GROUP BY root_user_id
            SQL,
            $userIds
        );

        return collect($rows)
            ->mapWithKeys(fn($row) => [(int) $row->root_user_id => (int) $row->total_count])
            ->all();
    }
}
