<?php

namespace Feeder\Core\Enums;

enum OrderAssignmentState: string
{
    case ASSIGNED = 'assigned';
    case UNASSIGNED = 'unassigned';
    case POOL = 'pool';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNED => 'Assigned',
            self::UNASSIGNED => 'Unassigned',
            self::POOL => 'Order Pool',
        };
    }

    /**
     * Derive assignment state from current order columns.
     */
    public static function fromOrder(?int $ccaId, bool $availableInPool): self
    {
        if ($ccaId !== null) {
            return self::ASSIGNED;
        }

        return $availableInPool ? self::POOL : self::UNASSIGNED;
    }
}
