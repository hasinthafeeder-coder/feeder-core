<?php

namespace Feeder\Core\Enums;

/**
 * Authoritative non-delivery fine condition totals (not additive).
 *
 * Resolved from order flags after_hours + duplicate_warning_overridden.
 */
enum ResellerNonDeliveryFineCondition: string
{
    case BASE = 'BASE';
    case AFTER_HOURS = 'AFTER_HOURS';
    case PROCESSING_OVERRIDE = 'PROCESSING_OVERRIDE';
    case COMBINED = 'COMBINED';

    public function label(): string
    {
        return match ($this) {
            self::BASE => 'Non-delivery (base)',
            self::AFTER_HOURS => 'Non-delivery + out-of-office-hours',
            self::PROCESSING_OVERRIDE => 'Non-delivery + processing-order override',
            self::COMBINED => 'Non-delivery + out-of-office-hours + processing-order override',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
