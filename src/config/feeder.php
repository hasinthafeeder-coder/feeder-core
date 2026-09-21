<?php

return [
    'file_server' => [
        'url' => env('FILE_SERVER_URL'),
        'api_key' => env('FILE_SERVER_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Courier credentials encryption
    |--------------------------------------------------------------------------
    |
    | Supplier courier account secrets are stored encrypted in the shared DB.
    | Every host app that reads/writes those rows MUST use the same key.
    | Do not use each app's APP_KEY for this — APP_KEY is per-portal (sessions).
    |
    | FEEDER_CREDENTIALS_KEY: primary key (base64:... or raw).
    | FEEDER_CREDENTIALS_PREVIOUS_KEYS: comma-separated keys still accepted for decrypt
    | (key rotation / legacy APP_KEY ciphertext).
    |
    */
    'credentials_encryption_key' => env('FEEDER_CREDENTIALS_KEY'),
    'credentials_encryption_previous_keys' => array_values(array_filter(array_map(
        static fn (string $key): string => trim($key),
        explode(',', (string) env('FEEDER_CREDENTIALS_PREVIOUS_KEYS', ''))
    ))),

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
        'ROYAL' => \Feeder\Core\Services\Courier\Curfox\RoyalBookingAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Courier credential schemas
    |--------------------------------------------------------------------------
    |
    | Map courier codes to CourierCredentialSchema implementations.
    | Unregistered codes fall back to GenericCourierCredentialSchema.
    |
    | Example:
    | 'ROYAL' => \App\Couriers\RoyalCredentialSchema::class,
    |
    */
    'courier_credential_schemas' => [
        'ROYAL' => \Feeder\Core\Services\Courier\CredentialSchema\RoyalExpressCredentialSchema::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Courier connection testers
    |--------------------------------------------------------------------------
    |
    | Map courier codes to CourierConnectionTester implementations.
    | Missing testers return "not available" — never fake success.
    |
    | Example:
    | 'ROYAL' => \App\Couriers\RoyalConnectionTester::class,
    |
    */
    'courier_connection_testers' => [
        'ROYAL' => \Feeder\Core\Services\Courier\Curfox\CurfoxConnectionTester::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Curfox (ROYAL) location master-data sync
    |--------------------------------------------------------------------------
    |
    | Provider-level credentials for importing courier_states / courier_cities.
    | These are NOT supplier courier account credentials.
    | Prefer config('services.curfox.*') in host apps; this is the package fallback.
    |
    */
    'curfox' => [
        'base_url' => env('CURFOX_BASE_URL', 'https://v2-dashboards.api.curfox.com'),
        'token' => env('CURFOX_TOKEN'),
        'tenant' => env('CURFOX_TENANT', 'royalexpress'),
    ],

    /*
    |--------------------------------------------------------------------------
    | TransExpress location master-data sync
    |--------------------------------------------------------------------------
    |
    | Provider-level base URL for importing courier_states / courier_cities.
    | Location endpoints require no authentication.
    | Prefer config('services.transexpress.*') in host apps; this is the package fallback.
    |
    */
    'transexpress' => [
        'base_url' => env('TRANSEXPRESS_BASE_URL', 'https://portal.transexpress.lk/api'),
    ],
];
