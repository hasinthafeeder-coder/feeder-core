<?php

namespace Feeder\Core\Enums;

/**
 * Operating expenses an administrator records directly.
 *
 * Courier charges, courier remittance, supplier invoices, reseller
 * settlements, and bank-transfer fees are not expense categories.
 */
enum CompanyExpenseCategory: string
{
    case RENT = 'RENT';
    case UTILITIES = 'UTILITIES';
    case PAYROLL = 'PAYROLL';
    case OFFICE = 'OFFICE';
    case MARKETING = 'MARKETING';
    case PROFESSIONAL_SERVICES = 'PROFESSIONAL_SERVICES';
    case SOFTWARE = 'SOFTWARE';
    case TRAVEL = 'TRAVEL';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::RENT => 'Rent',
            self::UTILITIES => 'Utilities',
            self::PAYROLL => 'Payroll',
            self::OFFICE => 'Office',
            self::MARKETING => 'Marketing',
            self::PROFESSIONAL_SERVICES => 'Professional services',
            self::SOFTWARE => 'Software',
            self::TRAVEL => 'Travel',
            self::OTHER => 'Other',
        };
    }
}
