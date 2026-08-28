<?php

namespace Feeder\Core\Http\Requests\Concerns;

use Feeder\Core\Contracts\Registration\CountryRegistrationRulesContract;
use Feeder\Core\Services\CountryRegistrationRuleService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait ResolvesCountryRegistrationRules
{
    protected function countryRegistrationRules(): CountryRegistrationRuleService
    {
        return app(CountryRegistrationRuleService::class);
    }

    protected function operationCountryIdRules(bool $required = true): array
    {
        $existsRule = Rule::exists('countries', 'uuid')
            ->where(fn ($query) => $query->where('is_active', true));

        if ($required) {
            return ['required', 'string', $existsRule];
        }

        return ['nullable', 'string', $existsRule];
    }

    protected function resolveRulesFromOperationCountryId(?string $operationCountryId): CountryRegistrationRulesContract
    {
        if (! filled($operationCountryId)) {
            throw new \InvalidArgumentException('Operation country is required for country-aware validation.');
        }

        return $this->countryRegistrationRules()->resolveByCountryUuid((string) $operationCountryId);
    }

    protected function normalizePhoneForRules(
        CountryRegistrationRulesContract $rules,
        mixed $phone,
    ): ?string {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        return $rules->normalizePhone($phone);
    }
}
