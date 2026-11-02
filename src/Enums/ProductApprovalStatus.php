<?php

namespace Feeder\Core\Enums;

enum ProductApprovalStatus: string
{
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_REVIEW => 'Pending Review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }
}
