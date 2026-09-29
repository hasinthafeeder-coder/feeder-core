<?php

namespace Feeder\Core\Enums;

enum SupplierFulfillmentEventType: string
{
    case FRESH = 'FRESH';
    case MOVED_TO_PRINT = 'MOVED_TO_PRINT';
    case STOCK_RESERVED = 'STOCK_RESERVED';
    case PRINTED = 'PRINTED';
    case MOVED_TO_PACKAGING = 'MOVED_TO_PACKAGING';
    case PRODUCT_SCANNED = 'PRODUCT_SCANNED';
    case PACKAGE_COMPLETED = 'PACKAGE_COMPLETED';
    case STOCK_CONSUMED = 'STOCK_CONSUMED';
    case DISPATCHED = 'DISPATCHED';
    case OUT_OF_STOCK = 'OUT_OF_STOCK';
    case CANCELLED_OUT_OF_STOCK = 'CANCELLED_OUT_OF_STOCK';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::FRESH => 'Entered Fresh',
            self::MOVED_TO_PRINT => 'Moved to Print',
            self::STOCK_RESERVED => 'Stock Reserved',
            self::PRINTED => 'Printed',
            self::MOVED_TO_PACKAGING => 'Moved to Packaging',
            self::PRODUCT_SCANNED => 'Product Scanned',
            self::PACKAGE_COMPLETED => 'Package Completed',
            self::STOCK_CONSUMED => 'Stock Consumed',
            self::DISPATCHED => 'Dispatched',
            self::OUT_OF_STOCK => 'Out of Stock',
            self::CANCELLED_OUT_OF_STOCK => 'Cancelled Out of Stock',
            self::CANCELLED => 'Cancelled',
        };
    }
}
