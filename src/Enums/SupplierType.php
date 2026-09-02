<?php

namespace Feeder\Core\Enums;

enum SupplierType: string
{
    case STANDARD = 'STANDARD';
    case PRO = 'PRO';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard Supplier',
            self::PRO => 'PRO Supplier',
        };
    }
}
