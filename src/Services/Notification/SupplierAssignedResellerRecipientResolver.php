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
 * Active reseller users assigned to a supplier via reseller_supplier_assignments.
 *
 * Assignment is admin-managed. Unassigned resellers are never included.
 */
class SupplierAssignedResellerRecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function allAssignedResellerUsersForSupplier(User $supplier): Collection
    {
        $this->assertSupplierActor($supplier);

        $resellerPortalId = DB::table('portals')
            ->where('code', PortalCode::RESELLER->value)
            ->value('id');

        return User::query()
            ->select('users.*')
            ->join('companies', 'companies.id', '=', 'users.company_id')
            ->join(
                'reseller_supplier_assignments as rsa',
                'rsa.reseller_id',
                '=',
                'users.id'
            )
            ->whereNull('users.deleted_at')
            ->whereNull('companies.deleted_at')
            ->where('users.status', UserStatus::ACTIVE->value)
            ->where('companies.status', CompanyStatus::ACTIVE->value)
            ->where('companies.portal_id', $resellerPortalId ?: 0)
            ->where('rsa.supplier_id', (int) $supplier->id)
            ->orderBy('users.id')
            ->distinct()
            ->get()
            ->unique(fn (User $user): int => (int) $user->id)
            ->values();
    }

    private function assertSupplierActor(User $supplier): void
    {
        $supplier->loadMissing('company.portal');

        if (! $supplier->isSupplier()) {
            throw ValidationException::withMessages([
                'actor' => ['Only supplier users can notify assigned resellers.'],
            ]);
        }

        $company = $supplier->company;

        if ($company === null || $company->trashed()) {
            throw ValidationException::withMessages([
                'actor' => ['The supplier company could not be found.'],
            ]);
        }

        $status = $company->status instanceof CompanyStatus
            ? $company->status
            : CompanyStatus::tryFrom((string) $company->status);

        if ($status !== CompanyStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'actor' => ['The supplier company is not active.'],
            ]);
        }
    }
}
