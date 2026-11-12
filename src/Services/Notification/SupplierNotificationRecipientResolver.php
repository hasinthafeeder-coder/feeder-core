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
 * Server-side supplier recipient eligibility.
 *
 * Eligible users are ACTIVE, non-deleted users whose company is an ACTIVE
 * SUPPLIER portal company. Client-supplied recipient lists are never trusted.
 */
class SupplierNotificationRecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function allEligibleSupplierUsers(): Collection
    {
        return $this->eligibleQuery()->orderBy('users.id')->get();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    public function resolveSelectedSupplierUsers(array $userIds): Collection
    {
        $normalized = $this->normalizeIds($userIds);

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'recipient_user_ids' => ['Select at least one eligible supplier recipient.'],
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
                    'One or more selected recipients are not eligible supplier users.',
                ],
            ]);
        }

        return $users->values();
    }

    public function isEligibleSupplierUser(User $user): bool
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

        return $company->portal?->code === PortalCode::SUPPLIER->value;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function eligibleQuery()
    {
        $supplierPortalId = DB::table('portals')
            ->where('code', PortalCode::SUPPLIER->value)
            ->value('id');

        return User::query()
            ->select('users.*')
            ->join('companies', 'companies.id', '=', 'users.company_id')
            ->whereNull('users.deleted_at')
            ->whereNull('companies.deleted_at')
            ->where('users.status', UserStatus::ACTIVE->value)
            ->where('companies.status', CompanyStatus::ACTIVE->value)
            ->where('companies.portal_id', $supplierPortalId ?: 0);
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
