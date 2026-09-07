<?php

namespace Feeder\Core\Enums;

enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case FIRST_ATTEMPT = '1ST_ATTEMPT';
    case SECOND_ATTEMPT = '2ND_ATTEMPT';
    case THIRD_ATTEMPT = '3RD_ATTEMPT';
    case HOLD = 'HOLD';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::FIRST_ATTEMPT => '1st Attempt',
            self::SECOND_ATTEMPT => '2nd Attempt',
            self::THIRD_ATTEMPT => '3rd Attempt',
            self::HOLD => 'Hold',
            self::CONFIRMED => 'Confirmed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function isIncomplete(): bool
    {
        return $this !== self::CANCELLED;
    }
}
