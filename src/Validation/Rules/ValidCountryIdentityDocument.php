<?php

namespace Feeder\Core\Validation\Rules;

use Closure;
use Feeder\Core\Contracts\Registration\CountryRegistrationRulesContract;
use Feeder\Core\Services\CountryRegistrationRuleService;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCountryIdentityDocument implements ValidationRule
{
    public function __construct(
        private readonly CountryRegistrationRulesContract $rules,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! $this->rules->isValidIdentityDocument($value)) {
            $fail($this->rules->identityDocumentValidationMessage());
        }
    }

    public static function forCountryUuid(
        string $countryUuid,
        CountryRegistrationRuleService $service,
    ): self {
        return new self($service->resolveByCountryUuid($countryUuid));
    }
}
