<?php

namespace Feeder\Core\Enums;

enum IdentityDocumentType: string
{
    case NIC = 'NIC';
    case MYKAD = 'MYKAD';

    public function label(): string
    {
        return match ($this) {
            self::NIC => 'NIC Number',
            self::MYKAD => 'MyKad Number',
        };
    }
}
