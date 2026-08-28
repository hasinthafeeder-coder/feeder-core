<?php

namespace Feeder\Core\Contracts\Registration;

interface CountryRegistrationRulesContract
{
    public function isoCode(): string;

    public function identityDocumentType(): string;

    public function identityDocumentLabel(): string;

    public function normalizeIdentityDocument(string $number): string;

    public function isValidIdentityDocument(string $number): bool;

    public function identityDocumentValidationMessage(): string;

    public function normalizePhone(string $phone): ?string;

    public function isValidPhone(string $phone): bool;

    public function phoneValidationMessage(): string;

    public function normalizeCustomerCarePhone(string $phone): ?string;

    public function isValidCustomerCarePhone(string $phone): bool;

    public function customerCarePhoneValidationMessage(): string;

    /**
     * @return array{
     *     iso_code: string,
     *     identity_document_label: string,
     *     identity_document_max_length: int,
     *     phone_max_length: int,
     *     customer_care_phone_max_length: int
     * }
     */
    public function clientValidationConfig(): array;
}
