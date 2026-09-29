<?php

namespace Feeder\Core\Events;

/**
 * Fired after an order has successfully committed a transition into CONFIRMED.
 *
 * Courier booking must already have succeeded before this event is raised.
 * Listeners must not perform booking to establish the confirmation waybill.
 */
class OrderConfirmed
{
    public function __construct(
        public readonly int $orderId,
        public readonly ?int $bookedBy = null,
    ) {
    }
}
