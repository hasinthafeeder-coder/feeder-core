<?php

namespace Feeder\Core\Enums;

enum OrderPaymentMethod: string
{
    case COD = 'COD';
    case BANK_TRANSFER = 'BANK_TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::COD => 'Cash on delivery',
            self::BANK_TRANSFER => 'Bank transfer',
        };
    }
}
