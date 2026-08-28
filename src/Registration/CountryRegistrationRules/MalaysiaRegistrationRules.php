<?php

namespace Feeder\Core\Registration\CountryRegistrationRules;

use Feeder\Core\Contracts\Registration\CountryRegistrationRulesContract;
use Feeder\Core\Enums\IdentityDocumentType;

class MalaysiaRegistrationRules implements CountryRegistrationRulesContract
{
    public function isoCode(): string
    {
        return 'MY';
    }

    public function identityDocumentType(): string
    {
        return IdentityDocumentType::MYKAD->value;
    }

    public function identityDocumentLabel(): string
    {
        return 'Identity Document Number';
    }

    public function normalizeIdentityDocument(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }

    public function isValidIdentityDocument(string $number): bool
    {
        $normalized = $this->normalizeIdentityDocument($number);

        if (! preg_match('/^\d{12}$/', $normalized)) {
            return false;
        }

        return $this->isValidMalaysianBirthDate(substr($normalized, 0, 6));
    }

    public function identityDocumentValidationMessage(): string
    {
        return 'Please enter a valid Malaysian identity document number.';
    }

    public function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '60')) {
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

        if (str_starts_with($digits, '60')) {
            $digits = '0'.substr($digits, 2);
        }

        return (bool) preg_match('/^01\d{8,9}$/', $digits);
    }

    public function phoneValidationMessage(): string
    {
        return 'Please enter a valid phone number for Malaysia.';
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
        return 'Please enter a valid customer care phone number for Malaysia.';
    }

    public function clientValidationConfig(): array
    {
        return [
            'iso_code' => $this->isoCode(),
            'identity_document_label' => $this->identityDocumentLabel(),
            'identity_document_max_length' => 12,
            'phone_max_length' => 13,
            'customer_care_phone_max_length' => 13,
        ];
    }

    private function isValidMalaysianBirthDate(string $yymmdd): bool
    {
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return false;
        }

        $year = (int) substr($yymmdd, 0, 2);
        $month = (int) substr($yymmdd, 2, 2);
        $day = (int) substr($yymmdd, 4, 2);

        $currentTwoDigitYear = (int) now()->format('y');
        $fullYear = $year <= $currentTwoDigitYear ? 2000 + $year : 1900 + $year;

        return checkdate($month, $day, $fullYear);
    }
}
