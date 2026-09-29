<?php

namespace Feeder\Core\Enums;

enum SupplierFulfillmentStatus: string
{
    case FRESH = 'FRESH';
    case PRINT = 'PRINT';
    case PACKAGING = 'PACKAGING';
    case DISPATCHED = 'DISPATCHED';
    case OUT_OF_STOCK = 'OUT_OF_STOCK';

    public function label(): string
    {
        return match ($this) {
            self::FRESH => 'Fresh',
            self::PRINT => 'Print',
            self::PACKAGING => 'Packaging',
            self::DISPATCHED => 'Dispatched',
            self::OUT_OF_STOCK => 'Out of Stock',
        };
    }

    /**
     * Warehouse fulfillment may be cancelled while the order is still before DISPATCHED.
     * Canonical cancellation authority is OrderStatus::canCancel() / OrderStatusService.
     */
    public function allowsCancellation(): bool
    {
        return match ($this) {
            self::DISPATCHED => false,
            default => true,
        };
    }
}
