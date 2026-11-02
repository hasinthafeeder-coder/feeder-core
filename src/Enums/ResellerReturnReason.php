<?php

namespace Feeder\Core\Enums;

/**
 * Canonical return-reason labels (operational audit only).
 *
 * Persisted on orders.return_reason at return time. Derived automatically from
 * order flags (after_hours / duplicate_warning_overridden); suppliers do not choose.
 * COMBINED and BASE both store OTHER. Fine amounts use fine_condition totals.
 */
enum ResellerReturnReason: string
{
    case DUPLICATE = 'DUPLICATE';
    case OUT_OF_OFFICE = 'OUT_OF_OFFICE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::DUPLICATE => 'Duplicate Return',
            self::OUT_OF_OFFICE => 'Out-of-Office-Hours Return',
            self::OTHER => 'Other Return',
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
