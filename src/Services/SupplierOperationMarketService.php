<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Models\Company;
use Illuminate\Validation\ValidationException;

class SupplierOperationMarketService
{
    public function __construct(
        private readonly MarketService $marketService,
    ) {}

    public function assignOnRegistration(Company $company, string $operationCountryUuid): void
    {
        $this->assertSupplierCompany($company);

        if ($company->operation_market_id !== null) {
            $this->preventMutation($company, $company->operation_market_id);

            return;
        }

        $market = $this->marketService->resolveActiveMarketForCountry($operationCountryUuid);

        $company->operation_market_id = $market->id;
    }

    public function preventMutation(Company $company, mixed $attemptedMarketId): void
    {
        if ($attemptedMarketId === null) {
            return;
        }

        $company->loadMissing('portal');

        if ($company->portal?->code !== PortalCode::SUPPLIER->value) {
            return;
        }

        $originalMarketId = $company->getOriginal('operation_market_id');

        if ($originalMarketId === null) {
            return;
        }

        if ((int) $attemptedMarketId === (int) $originalMarketId) {
            return;
        }

        throw ValidationException::withMessages([
            'operation_market_id' => 'Supplier operation market cannot be changed after company creation.',
        ]);
    }

    protected function assertSupplierCompany(Company $company): void
    {
        $company->loadMissing('portal');

        if ($company->portal?->code !== PortalCode::SUPPLIER->value) {
            throw ValidationException::withMessages([
                'company' => 'Operation market can only be assigned to supplier companies.',
            ]);
        }
    }
}
