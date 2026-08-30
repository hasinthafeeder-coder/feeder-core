<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductVariant;

class CompanyCommissionService
{
    public function __construct(
        protected MarketDefaultCompanyCommissionService $marketCommissionService,
    ) {
    }

    public function resolveCompanyCommission(
        Product $product,
        ?ProductVariant $existingVariant = null,
        mixed $explicitAmount = null
    ): string {
        if ($explicitAmount !== null && $explicitAmount !== '') {
            return $this->marketCommissionService->normalizeMoneyValue($explicitAmount);
        }

        if ($existingVariant !== null) {
            return number_format((float) $existingVariant->company_commission, 2, '.', '');
        }

        $product->loadMissing('market.currency');

        if ($product->market === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'market' => 'Product market is required to resolve default company commission.',
            ]);
        }

        return $this->marketCommissionService->getDefaultCompanyCommission($product->market);
    }

    public function resolveDefaultForMarket(Market|string $market): string
    {
        return $this->marketCommissionService->getDefaultCompanyCommission($market);
    }
}
