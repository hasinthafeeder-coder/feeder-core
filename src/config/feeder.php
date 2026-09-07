<?php

return [
    'file_server' => [
        'url' => env('FILE_SERVER_URL'),
        'api_key' => env('FILE_SERVER_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Courier booking adapters
    |--------------------------------------------------------------------------
    |
    | Map courier codes to CourierBookingAdapter implementations.
    | Lookups for districts/cities remain local (courier_cities).
    | Only shipment booking uses these adapters.
    |
    | Example:
    | 'ROYAL' => \App\Couriers\RoyalCourierBookingAdapter::class,
    |
    */
    'courier_booking_adapters' => [
        //
    ],
];