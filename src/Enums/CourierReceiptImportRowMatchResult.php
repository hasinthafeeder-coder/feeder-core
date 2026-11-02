<?php

namespace Feeder\Core\Enums;

enum CourierReceiptImportRowMatchResult: string
{
    case MATCHED = 'MATCHED';
    case UNMATCHED = 'UNMATCHED';
    case AMBIGUOUS = 'AMBIGUOUS';
    case DUPLICATE = 'DUPLICATE';
    case PREVIOUSLY_PROCESSED = 'PREVIOUSLY_PROCESSED';
    case SERVICE_MISMATCH = 'SERVICE_MISMATCH';
    case INVALID = 'INVALID';
    case SKIPPED = 'SKIPPED';
    case ALREADY_APPLIED = 'ALREADY_APPLIED';

    public function label(): string
    {
        return match ($this) {
            self::MATCHED => 'Matched',
            self::UNMATCHED => 'Unmatched',
            self::AMBIGUOUS => 'Ambiguous',
            self::DUPLICATE => 'Duplicate',
            self::PREVIOUSLY_PROCESSED => 'Previously processed',
            self::SERVICE_MISMATCH => 'Courier service mismatch',
            self::INVALID => 'Invalid',
            self::SKIPPED => 'Skipped',
            self::ALREADY_APPLIED => 'Already applied',
        };
    }
}
