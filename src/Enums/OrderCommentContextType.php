<?php

namespace Feeder\Core\Enums;

enum OrderCommentContextType: string
{
    case ORDER = 'ORDER';
    case CCA = 'CCA';
    case CUSTOMER = 'CUSTOMER';
    case SHIPMENT = 'SHIPMENT';
    case SYSTEM = 'SYSTEM';

    public function label(): string
    {
        return match ($this) {
            self::ORDER => 'Order',
            self::CCA => 'CCA',
            self::CUSTOMER => 'Customer',
            self::SHIPMENT => 'Shipment',
            self::SYSTEM => 'System',
        };
    }
}
