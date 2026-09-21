<?php

namespace Feeder\Core\Enums;

enum OrderType: string
{
    case NEW = 'NEW';
    case EXCHANGE = 'EXCHANGE';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'New',
            self::EXCHANGE => 'Exchange',
        };
    }
}
