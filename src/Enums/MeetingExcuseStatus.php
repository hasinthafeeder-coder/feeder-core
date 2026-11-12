<?php

namespace Feeder\Core\Enums;

enum MeetingExcuseStatus: string
{
    case APPROVED = 'APPROVED';

    public function label(): string
    {
        return match ($this) {
            self::APPROVED => 'Approved',
        };
    }
}
