<?php

namespace Feeder\Core\Contracts\Courier;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\SupplierCourierAccount;

interface CourierBookingAdapter
{
    /**
     * @return array{
     *     tracking_number: string,
     *     external_booking_ref?: string|null,
     *     raw_response?: array<string, mixed>|null
     * }
     */
    public function book(
        Order $order,
        Courier $courier,
        CourierService $service,
        CourierCity $city,
        ?SupplierCourierAccount $account,
        float $weightKg,
        float $courierFee,
    ): array;
}
