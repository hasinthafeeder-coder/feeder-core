<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\ResellerSupplierAssignment;
use Feeder\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResellerSupplierAssignmentService
{
    public const SELECT_ALL = 'all';

    /**
     * @return Collection<int, User>
     */
    public function listAssignedSuppliers(User $reseller): Collection
    {
        return $reseller->assignedSuppliers()
            ->with('company')
            ->orderBy('users.id')
            ->get();
    }

    /**
     * Active supplier owners that are not already assigned to this reseller.
     *
     * @return Collection<int, User>
     */
    public function listAssignableSuppliers(User $reseller): Collection
    {
        return $this->eligibleSupplierQuery()
            ->whereNotIn('id', $this->assignedSupplierIdQuery($reseller))
            ->with('company')
            ->orderBy('users.id')
            ->get();
    }

    /**
     * Assign one eligible supplier, or every remaining eligible supplier.
     *
     * @return int Number of assignments created
     */
    public function assign(User $reseller, string $supplierKey, ?User $assignedBy = null): int
    {
        $supplierKey = trim($supplierKey);

        if ($supplierKey === '') {
            throw ValidationException::withMessages([
                'supplier' => 'Please select a supplier.',
            ]);
        }

        if ($supplierKey === self::SELECT_ALL) {
            return $this->assignAll($reseller, $assignedBy);
        }

        $this->assignOne($reseller, $supplierKey, $assignedBy);

        return 1;
    }

    public function unassign(User $reseller, User $supplier): void
    {
        $deleted = ResellerSupplierAssignment::query()
            ->where('reseller_id', $reseller->id)
            ->where('supplier_id', $supplier->id)
            ->delete();

        if ($deleted === 0) {
            throw ValidationException::withMessages([
                'supplier' => 'This supplier is not assigned to the reseller.',
            ]);
        }
    }

    /**
     * Supplier user IDs assigned to the reseller.
     * Intended for later product availability queries:
     * Product::query()->where('status', ACTIVE)->forReseller($reseller->id)
     *
     * @return Collection<int, int>
     */
    public function assignedSupplierIds(User $reseller): Collection
    {
        return $this->assignedSupplierIdQuery($reseller)->pluck('supplier_id');
    }

    protected function assignOne(User $reseller, string $supplierUuid, ?User $assignedBy): ResellerSupplierAssignment
    {
        $supplier = $this->eligibleSupplierQuery()
            ->where('uuid', $supplierUuid)
            ->first();

        if (! $supplier) {
            throw ValidationException::withMessages([
                'supplier' => 'The selected supplier is not available for assignment.',
            ]);
        }

        if ($supplier->id === $reseller->id) {
            throw ValidationException::withMessages([
                'supplier' => 'A reseller cannot be assigned as their own supplier.',
            ]);
        }

        try {
            return ResellerSupplierAssignment::query()->create([
                'reseller_id' => $reseller->id,
                'supplier_id' => $supplier->id,
                'assigned_by' => $assignedBy?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'supplier' => 'This supplier is already assigned to the reseller.',
            ]);
        }
    }

    protected function assignAll(User $reseller, ?User $assignedBy): int
    {
        $suppliers = $this->listAssignableSuppliers($reseller)
            ->reject(fn (User $supplier) => $supplier->id === $reseller->id);

        if ($suppliers->isEmpty()) {
            return 0;
        }

        $now = now();

        $rows = $suppliers->map(fn (User $supplier) => [
            'uuid' => (string) Str::uuid(),
            'reseller_id' => $reseller->id,
            'supplier_id' => $supplier->id,
            'assigned_by' => $assignedBy?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        return DB::transaction(function () use ($rows): int {
            ResellerSupplierAssignment::query()->insertOrIgnore($rows);

            return count($rows);
        });
    }

    protected function eligibleSupplierQuery(): Builder
    {
        return User::query()
            ->where('user_type', UserType::OWNER->value)
            ->where('status', UserStatus::ACTIVE->value)
            ->whereHas('company', function (Builder $query) {
                $query->where('status', CompanyStatus::ACTIVE->value)
                    ->whereHas('portal', function (Builder $portalQuery) {
                        $portalQuery->where('code', PortalCode::SUPPLIER->value);
                    });
            });
    }

    protected function assignedSupplierIdQuery(User $reseller): Builder
    {
        return ResellerSupplierAssignment::query()
            ->select('supplier_id')
            ->where('reseller_id', $reseller->id);
    }
}
