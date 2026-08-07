<?php

namespace Feeder\Core\Enums;

enum FileCategory: string
{
    case PROFILE_PHOTO = 'PROFILE_PHOTO';
    case COMPANY_LOGO = 'COMPANY_LOGO';
    case BUSINESS_REGISTRATION = 'BUSINESS_REGISTRATION';
    case PRODUCT_IMAGE = 'PRODUCT_IMAGE';
    case PAYMENT_PROOF = 'PAYMENT_PROOF';
    case INVOICE = 'INVOICE';

    public function supportsThumbnail(): bool
    {
        return match ($this) {
            self::PROFILE_PHOTO,
            self::COMPANY_LOGO,
            self::PRODUCT_IMAGE => true,
            default => false,
        };
    }
}
