<?php

return [
    'file_server' => [
        'url' => env('FILE_SERVER_URL'),
        'api_key' => env('FILE_SERVER_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Market commercial identifiers
    |--------------------------------------------------------------------------
    |
    | market_number lives on the markets table. Timezones drive YY / DDD and
    | the daily order_number sequence key (never use UTC for business dates).
    |
    */
    'markets' => [
        'timezones' => [
            'lk' => 'Asia/Colombo',
            'my' => 'Asia/Kuala_Lumpur',
        ],
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
        'TRANSEXPRESS' => \Feeder\Core\Services\Courier\TransExpress\TransExpressBookingAdapter::class,
        'FARDAR' => \Feeder\Core\Services\Courier\Fardar\FardarBookingAdapter::class,
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
        'TRANSEXPRESS' => \Feeder\Core\Services\Courier\CredentialSchema\TransExpressCredentialSchema::class,
        'FARDAR' => \Feeder\Core\Services\Courier\CredentialSchema\FardarCredentialSchema::class,
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
        'TRANSEXPRESS' => \Feeder\Core\Services\Courier\TransExpress\TransExpressConnectionTester::class,
        'FARDAR' => \Feeder\Core\Services\Courier\Fardar\FardarConnectionTester::class,
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

    /*
    |--------------------------------------------------------------------------
    | Fardar Domestic booking / create-parcel
    |--------------------------------------------------------------------------
    |
    | Provider-level base URL for form-urlencoded create-parcel requests.
    | Supplier Client ID / API Key live in supplier_courier_accounts — never here.
    | Prefer config('services.fardar.*') in host apps; this is the package fallback.
    |
    */
    'fardar' => [
        'base_url' => env('FARDAR_BASE_URL', 'https://www.fdedomestic.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fardar location cleanup (test seam)
    |--------------------------------------------------------------------------
    |
    | When true, FardarLocationCleanupService throws after deleting cities so
    | the surrounding DB transaction rolls back. Used only by feature tests.
    |
    */
    'fardar_cleanup_force_fail_after_cities' => false,
];
