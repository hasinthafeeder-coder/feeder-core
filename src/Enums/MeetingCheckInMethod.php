<?php

namespace Feeder\Core\Enums;

enum MeetingCheckInMethod: string
{
    case QR = 'QR';
    case MANUAL = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::QR => 'QR scan',
            self::MANUAL => 'Manual check-in',
        };
    }
}
