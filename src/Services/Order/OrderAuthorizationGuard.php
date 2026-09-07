<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\User;
use Feeder\Core\Services\ResellerMarketAccessService;
use Feeder\Core\Services\ResellerSupplierAssignmentService;
use Illuminate\Validation\ValidationException;

/**
 * Domain authorization boundaries for Phase 1 order write paths.
 *
 * Controllers must still enforce permission slugs; this guard enforces
 * reseller company / market / supplier ownership consistency at the domain layer.
 */
class OrderAuthorizationGuard
{
    public function __construct(
        private readonly ResellerMarketAccessService $marketAccessService,
        private readonly ResellerSupplierAssignmentService $supplierAssignmentService,
    ) {
    }

    /**
     * @return array{reseller: User, company: Company}
     */
    public function assertResellerContext(int $resellerId, int $resellerCompanyId): array
    {
        $reseller = User::query()->with(['company.portal'])->find($resellerId);

        if ($reseller === null || $reseller->trashed()) {
            throw ValidationException::withMessages([
                'reseller_id' => ['The selected reseller is invalid.'],
            ]);
        }

        $status = $reseller->status instanceof UserStatus
            ? $reseller->status
            : UserStatus::tryFrom((string) $reseller->status);

        if ($status !== UserStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'reseller_id' => ['The selected reseller is not active.'],
            ]);
        }

        $company = Company::query()->with('portal')->find($resellerCompanyId);

        if ($company === null || $company->trashed()) {
            throw ValidationException::withMessages([
                'reseller_company_id' => ['The selected reseller company is invalid.'],
            ]);
        }

        if (! $company->isResellerCompany()) {
            throw ValidationException::withMessages([
                'reseller_company_id' => ['Orders can only be created for reseller companies.'],
            ]);
        }

        $companyStatus = $company->status instanceof CompanyStatus
            ? $company->status
            : CompanyStatus::tryFrom((string) $company->status);

        if ($companyStatus !== CompanyStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'reseller_company_id' => ['The reseller company is not active.'],
            ]);
        }

        if ((int) $reseller->company_id !== (int) $company->id) {
            throw ValidationException::withMessages([
                'reseller_id' => ['The reseller does not belong to the supplied reseller company.'],
            ]);
        }

        return [
            'reseller' => $reseller,
            'company' => $company,
        ];
    }

    public function assertMarketAccess(Company $company, int $marketId): void
    {
        if (! $this->marketAccessService->hasMarketAccess($company, $marketId)) {
            throw ValidationException::withMessages([
                'market_id' => ['The reseller does not have access to the selected market.'],
            ]);
        }
    }

    public function assertSupplierAssigned(User $reseller, int $supplierId): void
    {
        if (! $this->supplierAssignmentService->isSupplierAssigned((int) $reseller->id, $supplierId)) {
            throw ValidationException::withMessages([
                'supplier_id' => ['The selected supplier is not assigned to this reseller.'],
            ]);
        }
    }

    public function assertOrderCompany(Order $order, int $resellerCompanyId): void
    {
        if ((int) $order->reseller_company_id !== $resellerCompanyId) {
            throw ValidationException::withMessages([
                'order' => ['This order belongs to a different reseller company.'],
            ]);
        }
    }
}
