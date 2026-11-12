<?php

namespace Feeder\Core\Enums;

enum FileCategory: string
{
    case PROFILE_PHOTO = 'PROFILE_PHOTO';
    case COMPANY_LOGO = 'COMPANY_LOGO';
    case BUSINESS_REGISTRATION = 'BUSINESS_REGISTRATION';
    case PRODUCT_GUIDELINE = 'PRODUCT_GUIDELINE';
    case PRODUCT_IMAGE = 'PRODUCT_IMAGE';
    case PAYMENT_PROOF = 'PAYMENT_PROOF';
    case INVOICE = 'INVOICE';
    case EXPENSE_DOCUMENT = 'EXPENSE_DOCUMENT';
    case NOTIFICATION_BANNER = 'NOTIFICATION_BANNER';
    case MEETING_BANNER = 'MEETING_BANNER';

    public function supportsThumbnail(): bool
    {
        return match ($this) {
            self::PROFILE_PHOTO,
            self::COMPANY_LOGO,
            self::PRODUCT_IMAGE,
            self::NOTIFICATION_BANNER,
            self::MEETING_BANNER => true,
            default => false,
        };
    }
}
