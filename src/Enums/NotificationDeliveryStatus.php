<?php

namespace Feeder\Core\Enums;

enum NotificationDeliveryStatus: string
{
    case PENDING = 'PENDING';
    case DELIVERED = 'DELIVERED';
    case FAILED = 'FAILED';
}
