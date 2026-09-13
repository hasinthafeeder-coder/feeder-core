<?php

namespace Feeder\Core\Enums;

enum OrderCcaAssignmentOrigin: string
{
    case DIRECT = 'direct';
    case POOL_CLAIM = 'pool_claim';
    case MANUAL_CREATE = 'manual_create';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT => 'Direct assignment',
            self::POOL_CLAIM => 'Order pool claim',
            self::MANUAL_CREATE => 'Manual order create',
        };
    }
}
