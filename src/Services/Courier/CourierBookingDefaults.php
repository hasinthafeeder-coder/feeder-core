<?php

namespace Feeder\Core\Services\Courier;

/**
 * Shared defaults for courier booking payloads.
 *
 * Under Feeder Global courier agreements, create-order / create-parcel APIs
 * are billed at a fixed weight. Adapters must use this value for the courier
 * payload weight field — never the Feeder order or product weight.
 */
final class CourierBookingDefaults
{
    /**
     * Fixed weight (KG) sent to every courier create-order / booking API.
     */
    public const COURIER_BOOKING_WEIGHT_KG = 1;
}
