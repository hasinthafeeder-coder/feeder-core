<?php

namespace Feeder\Core\Enums;

/**
 * Reseller debt obligation kinds persisted for Minus Report carry-forward.
 */
enum ResellerDebtObligationType: string
{
    case RETURN_FINE = 'RETURN_FINE';

    public function label(): string
    {
        return match ($this) {
            self::RETURN_FINE => 'Non-Delivery Fine',
        };
    }
}
