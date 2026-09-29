<?php

namespace Feeder\Core\Enums;

enum StockReservationStatus: string
{
    case ACTIVE = 'ACTIVE';
    case RELEASED = 'RELEASED';
    case CONSUMED = 'CONSUMED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::RELEASED => 'Released',
            self::CONSUMED => 'Consumed',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
