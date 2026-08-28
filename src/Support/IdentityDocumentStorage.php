<?php

namespace Feeder\Core\Support;

use Feeder\Core\Contracts\Registration\CountryRegistrationRulesContract;
use Feeder\Core\Enums\IdentityDocumentType;
use Feeder\Core\Models\UserProfile;

class IdentityDocumentStorage
{
    public static function applyToProfile(
        UserProfile $profile,
        CountryRegistrationRulesContract $rules,
        string $documentNumber,
    ): void {
        $normalizedNumber = $rules->normalizeIdentityDocument($documentNumber);
        $documentType = $rules->identityDocumentType();

        if (UserProfileSchema::hasIdentityDocumentColumns()) {
            $profile->identity_document_type = $documentType;
            $profile->identity_document_number = $normalizedNumber;
        }

        if ($documentType === IdentityDocumentType::NIC->value || ! UserProfileSchema::hasIdentityDocumentColumns()) {
            $profile->nic = $normalizedNumber;

            return;
        }

        $profile->nic = strlen($normalizedNumber) <= 12
            ? $normalizedNumber
            : substr($normalizedNumber, 0, 12);
    }

    public static function storedDocumentNumber(UserProfile $profile): ?string
    {
        if (filled($profile->identity_document_number)) {
            return $profile->identity_document_number;
        }

        return filled($profile->nic) ? $profile->nic : null;
    }

    public static function isIdentityComplete(UserProfile $profile): bool
    {
        return filled(self::storedDocumentNumber($profile));
    }
}
