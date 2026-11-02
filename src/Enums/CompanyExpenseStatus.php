<?php

namespace Feeder\Core\Enums;

enum CompanyExpenseStatus: string
{
    case POSTED = 'POSTED';
    case VOIDED = 'VOIDED';

    public function label(): string
    {
        return match ($this) {
            self::POSTED => 'Posted',
            self::VOIDED => 'Voided',
        };
    }
}
