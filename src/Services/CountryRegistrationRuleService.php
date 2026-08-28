<?php

namespace Feeder\Core\Services;

use Feeder\Core\Contracts\Registration\CountryRegistrationRulesContract;
use Feeder\Core\Exceptions\UnsupportedRegistrationCountryException;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\Country;
use Feeder\Core\Models\User;
use Feeder\Core\Registration\CountryRegistrationRules\MalaysiaRegistrationRules;
use Feeder\Core\Registration\CountryRegistrationRules\SriLankaRegistrationRules;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CountryRegistrationRuleService
{
    /** @var array<string, class-string<CountryRegistrationRulesContract>> */
    private const RULE_CLASSES = [
        'LK' => SriLankaRegistrationRules::class,
        'MY' => MalaysiaRegistrationRules::class,
    ];

    public const RESELLER_REGISTRATION_DEFAULT_ISO = 'LK';

    /** @var array<string, CountryRegistrationRulesContract> */
    private array $resolvedRules = [];

    public function resolveByIsoCode(string $isoCode): CountryRegistrationRulesContract
    {
        $isoCode = strtoupper(trim($isoCode));

        if ($isoCode === '') {
            throw UnsupportedRegistrationCountryException::forIsoCode($isoCode);
        }

        if (isset($this->resolvedRules[$isoCode])) {
            return $this->resolvedRules[$isoCode];
        }

        $ruleClass = self::RULE_CLASSES[$isoCode] ?? null;

        if ($ruleClass === null) {
            throw UnsupportedRegistrationCountryException::forIsoCode($isoCode);
        }

        return $this->resolvedRules[$isoCode] = app($ruleClass);
    }

    public function resolveByCountryUuid(string $countryUuid): CountryRegistrationRulesContract
    {
        $country = Country::query()
            ->where('uuid', $countryUuid)
            ->where('is_active', true)
            ->first();

        if ($country === null) {
            throw ValidationException::withMessages([
                'operation_country_id' => 'The selected operation country is invalid or inactive.',
            ]);
        }

        return $this->resolveByIsoCode($country->iso_code);
    }

    public function resolveForResellerRegistration(): CountryRegistrationRulesContract
    {
        return $this->resolveByIsoCode(self::RESELLER_REGISTRATION_DEFAULT_ISO);
    }

    public function resolveForSupplierUser(User $user): ?CountryRegistrationRulesContract
    {
        $user->loadMissing('company.operationMarket.country');

        $isoCode = $user->company?->operationMarket?->country?->iso_code;

        if (! is_string($isoCode) || $isoCode === '') {
            return null;
        }

        return $this->resolveByIsoCode($isoCode);
    }

    public function resolveForSupplierCompany(?Company $company): ?CountryRegistrationRulesContract
    {
        if ($company === null) {
            return null;
        }

        $company->loadMissing('operationMarket.country');

        $isoCode = $company->operationMarket?->country?->iso_code;

        if (! is_string($isoCode) || $isoCode === '') {
            return null;
        }

        return $this->resolveByIsoCode($isoCode);
    }

    public function assertSupplierOperationCountryMatches(
        User $user,
        string $operationCountryUuid,
    ): CountryRegistrationRulesContract {
        $user->loadMissing('company.operationMarket.country');

        $submittedCountry = Country::query()
            ->where('uuid', $operationCountryUuid)
            ->where('is_active', true)
            ->first();

        if ($submittedCountry === null) {
            throw ValidationException::withMessages([
                'operation_country_id' => 'The selected operation country is invalid or inactive.',
            ]);
        }

        $assignedCountryUuid = $user->company?->operationMarket?->country?->uuid;

        if ($assignedCountryUuid !== null && $assignedCountryUuid !== $submittedCountry->uuid) {
            throw ValidationException::withMessages([
                'operation_country_id' => 'Operation country cannot be changed after it has been assigned.',
            ]);
        }

        return $this->resolveByIsoCode($submittedCountry->iso_code);
    }

    /**
     * @return Collection<int, array{
     *     country_uuid: string,
     *     iso_code: string,
     *     name: string,
     *     validation: array<string, mixed>
     * }>
     */
    public function clientValidationConfigsForCountries(Collection $countries): Collection
    {
        return $countries->map(function (Country $country): array {
            try {
                $rules = $this->resolveByIsoCode($country->iso_code);
                $validation = $rules->clientValidationConfig();
            } catch (UnsupportedRegistrationCountryException) {
                $validation = [
                    'iso_code' => $country->iso_code,
                    'identity_document_label' => 'Identity Document Number',
                    'identity_document_max_length' => 50,
                    'phone_max_length' => 15,
                    'customer_care_phone_max_length' => 15,
                ];
            }

            return [
                'country_uuid' => $country->uuid,
                'iso_code' => $country->iso_code,
                'name' => $country->name,
                'validation' => $validation,
            ];
        });
    }

    /**
     * @return list<string>
     */
    public function supportedIsoCodes(): array
    {
        return array_keys(self::RULE_CLASSES);
    }
}
