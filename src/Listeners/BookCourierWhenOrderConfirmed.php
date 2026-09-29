<?php

namespace Feeder\Core\Listeners;

use Feeder\Core\Events\OrderConfirmed;

/**
 * Former post-confirmation courier booking listener.
 *
 * Courier booking now runs before OrderStatus::CONFIRMED in:
 * - Manual Create confirmation
 * - Call Center status → CONFIRMED
 * - Bank-transfer payment approval
 *
 * This listener remains registered nowhere. Kept as an empty handler so any
 * residual wiring cannot reintroduce book-after-confirm behaviour.
 */
class BookCourierWhenOrderConfirmed
{
    public function handle(OrderConfirmed $event): void
    {
        // Intentionally empty — waybill must exist before CONFIRMED.
    }
}
