<?php

namespace Feeder\Core\Services\Notification;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Server-side reseller recipient eligibility for non-product audiences.
 *
 * Eligible users are ACTIVE, non-deleted users whose company is an ACTIVE
 * RESELLER portal company. Client-supplied recipient lists are never trusted.
 */
class ResellerUserNotificationRecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function allEligibleResellerUsers(): Collection
    {
        return $this->eligibleQuery()->orderBy('users.id')->get();
    }

    /**
     * Active, non-deleted users in active companies across all portals.
     * Deduplicated by user id.
     *
     * @return Collection<int, User>
     */
    public function allActiveUsersAcrossPortals(): Collection
    {
        $portalIds = DB::table('portals')
            ->whereIn('code', [
                PortalCode::ADMIN->value,
                PortalCode::RESELLER->value,
                PortalCode::SUPPLIER->value,
            ])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($portalIds === []) {
            return collect();
        }

        return User::query()
            ->whereNull('users.deleted_at')
            ->where('users.status', UserStatus::ACTIVE->value)
            ->whereHas('company', function ($query) use ($portalIds): void {
                $query->whereNull('companies.deleted_at')
                    ->where('companies.status', CompanyStatus::ACTIVE->value)
                    ->whereIn('companies.portal_id', $portalIds);
            })
            ->orderBy('users.id')
            ->get()
            ->unique(fn (User $user): int => (int) $user->id)
            ->values();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    public function resolveSelectedResellerUsers(array $userIds): Collection
    {
        $normalized = $this->normalizeIds($userIds);

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'recipient_user_ids' => ['Select at least one eligible reseller recipient.'],
            ]);
        }

        $users = $this->eligibleQuery()
            ->whereIn('users.id', $normalized)
            ->orderBy('users.id')
            ->get()
            ->keyBy(fn (User $user): int => (int) $user->id);

        $missing = array_values(array_filter(
            $normalized,
            static fn (int $id): bool => ! $users->has($id)
        ));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'recipient_user_ids' => [
                    'One or more selected recipients are not eligible reseller users.',
                ],
            ]);
        }

        return $users->values();
    }

    public function isEligibleResellerUser(User $user): bool
    {
        if ((string) $user->status !== UserStatus::ACTIVE->value) {
            return false;
        }

        if ($user->trashed()) {
            return false;
        }

        $user->loadMissing('company.portal');

        $company = $user->company;

        if ($company === null || $company->trashed()) {
            return false;
        }

        if ($company->status !== CompanyStatus::ACTIVE
            && (string) $company->status !== CompanyStatus::ACTIVE->value) {
            return false;
        }

        return $company->portal?->code === PortalCode::RESELLER->value;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function eligibleQuery()
    {
        $resellerPortalId = DB::table('portals')
            ->where('code', PortalCode::RESELLER->value)
            ->value('id');

        return User::query()
            ->whereNull('users.deleted_at')
            ->where('users.status', UserStatus::ACTIVE->value)
            ->whereHas('company', function ($query) use ($resellerPortalId): void {
                $query->whereNull('companies.deleted_at')
                    ->where('companies.status', CompanyStatus::ACTIVE->value)
                    ->where('companies.portal_id', $resellerPortalId);
            });
    }

    /**
     * @param  list<int|string>  $userIds
     * @return list<int>
     */
    private function normalizeIds(array $userIds): array
    {
        $normalized = [];

        foreach ($userIds as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }
}
