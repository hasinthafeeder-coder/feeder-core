<?php

namespace Feeder\Core\Registration\CountryRegistrationRules;

use Feeder\Core\Contracts\Registration\CountryRegistrationRulesContract;
use Feeder\Core\Enums\IdentityDocumentType;

class SriLankaRegistrationRules implements CountryRegistrationRulesContract
{
    public function isoCode(): string
    {
        return 'LK';
    }

    public function identityDocumentType(): string
    {
        return IdentityDocumentType::NIC->value;
    }

    public function identityDocumentLabel(): string
    {
        return IdentityDocumentType::NIC->label();
    }

    public function normalizeIdentityDocument(string $number): string
    {
        $uppercased = strtoupper($number);

        return preg_replace('/[^0-9VX]/', '', $uppercased) ?? '';
    }

    public function isValidIdentityDocument(string $number): bool
    {
        $normalized = $this->normalizeIdentityDocument($number);

        return (bool) preg_match('/^([0-9]{9}[VX]|[0-9]{12})$/', $normalized);
    }

    public function identityDocumentValidationMessage(): string
    {
        return 'Please enter a valid Sri Lankan NIC number.';
    }

    public function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '94') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }

        if (! $this->isValidPhone($digits)) {
            return null;
        }

        return $digits;
    }

    public function isValidPhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '94') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }

        return (bool) preg_match('/^0[1-9]\d{8}$/', $digits);
    }

    public function phoneValidationMessage(): string
    {
        return 'Please enter a valid phone number for Sri Lanka.';
    }

    public function normalizeCustomerCarePhone(string $phone): ?string
    {
        return $this->normalizePhone($phone);
    }

    public function isValidCustomerCarePhone(string $phone): bool
    {
        return $this->isValidPhone($phone);
    }

    public function customerCarePhoneValidationMessage(): string
    {
        return 'Please enter a valid customer care phone number for Sri Lanka.';
    }

    public function clientValidationConfig(): array
    {
        return [
            'iso_code' => $this->isoCode(),
            'identity_document_label' => $this->identityDocumentLabel(),
            'identity_document_max_length' => 12,
            'phone_max_length' => 11,
            'customer_care_phone_max_length' => 11,
        ];
    }
}
