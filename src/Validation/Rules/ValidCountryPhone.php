<?php

namespace Feeder\Core\Validation\Rules;

use Closure;
use Feeder\Core\Contracts\Registration\CountryRegistrationRulesContract;
use Feeder\Core\Services\CountryRegistrationRuleService;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCountryPhone implements ValidationRule
{
    public function __construct(
        private readonly CountryRegistrationRulesContract $rules,
        private readonly string $messageAttribute = 'phone',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! $this->rules->isValidPhone($value)) {
            $fail($this->rules->phoneValidationMessage());
        }
    }

    public static function forCountryUuid(
        string $countryUuid,
        CountryRegistrationRuleService $service,
        string $messageAttribute = 'phone',
    ): self {
        return new self($service->resolveByCountryUuid($countryUuid), $messageAttribute);
    }
}
