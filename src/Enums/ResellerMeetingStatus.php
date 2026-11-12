<?php

namespace Feeder\Core\Enums;

enum ResellerMeetingStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Scheduled',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function isActive(): bool
    {
        return $this === self::SCHEDULED;
    }
}
