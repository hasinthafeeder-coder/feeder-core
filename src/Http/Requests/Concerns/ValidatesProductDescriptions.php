<?php

namespace Feeder\Core\Http\Requests\Concerns;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\Product;
use Feeder\Core\Services\ProductMarketLanguageService;
use Illuminate\Validation\ValidationException;

trait ValidatesProductDescriptions
{
    protected function prepareProductDescriptionsForValidation(?Market $market): void
    {
        $languageService = app(ProductMarketLanguageService::class);
        $descriptions = $this->input('descriptions', []);

        if (! is_array($descriptions)) {
            $descriptions = [];
        }

        foreach ($languageService->languageCodesForMarket($market) as $languageCode) {
            if (! array_key_exists($languageCode, $descriptions)) {
                $descriptions[$languageCode] = null;
            }
        }

        $this->merge([
            'descriptions' => $descriptions,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function productDescriptionRules(?Market $market): array
    {
        return app(ProductMarketLanguageService::class)
            ->descriptionValidationRulesForMarket($market);
    }

    protected function assertSubmittedDescriptionsMatchMarket(?Market $market): void
    {
        $descriptions = $this->input('descriptions', []);

        if (! is_array($descriptions)) {
            throw ValidationException::withMessages([
                'descriptions' => 'Product descriptions must be provided as an array.',
            ]);
        }

        app(ProductMarketLanguageService::class)
            ->assertDescriptionsMatchMarket($market, $descriptions);
    }

    protected function resolveProductMarketFromRouteProduct(): ?Market
    {
        $product = $this->route('product');

        if (! $product instanceof Product) {
            return null;
        }

        $product->loadMissing('market');

        return $product->market;
    }
}
