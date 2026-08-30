<?php

namespace Feeder\Core\Services;

use Feeder\Core\Contracts\Product\ProductMarketLanguageDefinition;
use Feeder\Core\Models\Market;
use Feeder\Core\Product\MarketLanguages\FallbackProductLanguages;
use Feeder\Core\Product\MarketLanguages\MalaysiaProductLanguages;
use Feeder\Core\Product\MarketLanguages\SriLankaProductLanguages;
use Illuminate\Validation\ValidationException;

class ProductMarketLanguageService
{
    /** @var array<string, class-string<ProductMarketLanguageDefinition>> */
    private const MARKET_LANGUAGE_CLASSES = [
        'lk' => SriLankaProductLanguages::class,
        'my' => MalaysiaProductLanguages::class,
    ];

    /** @var array<string, ProductMarketLanguageDefinition> */
    private array $resolvedDefinitions = [];

    /**
     * @return list<array{
     *     code: string,
     *     label: string,
     *     tab_label: string,
     *     placeholder: string
     * }>
     */
    public function languagesForMarket(Market|string|null $market): array
    {
        return $this->resolveDefinition($market)->supportedLanguages();
    }

    /**
     * @return list<string>
     */
    public function languageCodesForMarket(Market|string|null $market): array
    {
        return array_column($this->languagesForMarket($market), 'code');
    }

    /**
     * @return array<string, string>
     */
    public function descriptionValidationRulesForMarket(Market|string|null $market): array
    {
        $rules = ['descriptions' => ['required', 'array']];

        foreach ($this->languageCodesForMarket($market) as $languageCode) {
            $rules['descriptions.'.$languageCode] = ['nullable', 'string'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $descriptions
     * @return list<array{language_code: string, description: ?string}>
     */
    public function normalizeDescriptionsForMarket(Market|string|null $market, array $descriptions): array
    {
        $normalized = [];

        foreach ($this->languageCodesForMarket($market) as $languageCode) {
            if (! array_key_exists($languageCode, $descriptions)) {
                continue;
            }

            $value = $descriptions[$languageCode];

            if ($value === null || $value === '') {
                continue;
            }

            $normalized[] = [
                'language_code' => $languageCode,
                'description' => $value,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $descriptions
     */
    public function assertDescriptionsMatchMarket(Market|string|null $market, array $descriptions): void
    {
        $allowedCodes = $this->languageCodesForMarket($market);

        foreach (array_keys($descriptions) as $languageCode) {
            if (! in_array($languageCode, $allowedCodes, true)) {
                throw ValidationException::withMessages([
                    'descriptions.'.$languageCode => 'This language is not supported for the product market.',
                ]);
            }
        }
    }

    protected function resolveDefinition(Market|string|null $market): ProductMarketLanguageDefinition
    {
        $marketCode = $this->resolveMarketCode($market);

        if ($marketCode === null) {
            return new FallbackProductLanguages();
        }

        if (isset($this->resolvedDefinitions[$marketCode])) {
            return $this->resolvedDefinitions[$marketCode];
        }

        $definitionClass = self::MARKET_LANGUAGE_CLASSES[$marketCode] ?? null;

        if ($definitionClass === null) {
            return $this->resolvedDefinitions[$marketCode] = new FallbackProductLanguages();
        }

        return $this->resolvedDefinitions[$marketCode] = app($definitionClass);
    }

    protected function resolveMarketCode(Market|string|null $market): ?string
    {
        if ($market === null) {
            return null;
        }

        if ($market instanceof Market) {
            return strtolower(trim((string) $market->code));
        }

        $normalized = strtolower(trim($market));

        return $normalized !== '' ? $normalized : null;
    }
}
