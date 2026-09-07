<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Models\Order;
use Illuminate\Validation\ValidationException;

/**
 * Domain shipment mutability locks.
 *
 * Any future write path that mutates shipment-critical order data or courier
 * booking fields MUST call the appropriate assertion before persisting.
 *
 * Protected fields after successful booking:
 * - product / variant / quantity (order items)
 * - courier / courier service / courier city / tracking ID (shipment)
 */
class OrderShipmentLockService
{
    public function assertOrderItemsMutable(Order $order): void
    {
        $order->loadMissing('shipment');

        if ($order->hasBookedShipment()) {
            throw ValidationException::withMessages([
                'order' => ['Order items are locked after successful courier booking.'],
            ]);
        }
    }

    public function assertShipmentMutable(Order $order): void
    {
        $order->loadMissing('shipment');

        if ($order->hasBookedShipment()) {
            throw ValidationException::withMessages([
                'shipment' => ['Shipment courier data is locked after successful courier booking.'],
            ]);
        }
    }
}
