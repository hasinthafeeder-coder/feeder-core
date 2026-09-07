<?php

namespace Feeder\Core\Support;

use Feeder\Core\Models\Country;
use Feeder\Core\Services\CountryRegistrationRuleService;
use Feeder\Core\Exceptions\UnsupportedRegistrationCountryException;

class CustomerPhoneNormalizer
{
    public function __construct(
        private readonly CountryRegistrationRuleService $countryRegistrationRuleService,
    ) {
    }

    public function normalize(string $rawPhone, Country $country): string
    {
        $rawPhone = trim($rawPhone);

        try {
            $rules = $this->countryRegistrationRuleService->resolveByIsoCode((string) $country->iso_code);
            $normalized = $rules->normalizePhone($rawPhone);

            if (is_string($normalized) && $normalized !== '') {
                return $normalized;
            }
        } catch (UnsupportedRegistrationCountryException) {
            // Fall through to generic digit normalization.
        }

        $digits = preg_replace('/\D+/', '', $rawPhone) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException('Phone number could not be normalized.');
        }

        $countryCodeDigits = preg_replace('/\D+/', '', (string) $country->phone_country_code) ?? '';

        if ($countryCodeDigits !== '' && str_starts_with($digits, $countryCodeDigits) && strlen($digits) > strlen($countryCodeDigits)) {
            $digits = '0'.substr($digits, strlen($countryCodeDigits));
        }

        return $digits;
    }
}
