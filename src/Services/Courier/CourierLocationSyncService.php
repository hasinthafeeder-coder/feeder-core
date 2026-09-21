<?php

namespace Feeder\Core\Services\Courier;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Services\Courier\Curfox\CurfoxApiClient;
use Feeder\Core\Services\Courier\Curfox\CurfoxLocationMapper;
use Feeder\Core\Services\Courier\Curfox\MappedCourierCity;
use Feeder\Core\Services\Courier\Curfox\MappedCourierState;
use Feeder\Core\Services\Courier\Fardar\FardarLocationCsvMapper;
use Feeder\Core\Services\Courier\TransExpress\TransExpressApiClient;
use Feeder\Core\Services\Courier\TransExpress\TransExpressLocationMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Synchronizes provider location master data into local courier_states / courier_cities.
 *
 * Locations are owned by the courier provider (e.g. ROYAL, TRANSEXPRESS, FARDAR), never by a supplier account.
 *
 * Supported providers:
 * - ROYAL / Curfox: State API → states, City API → cities
 * - TRANSEXPRESS: Province → District → City (districts map to courier_states; cities to courier_cities)
 * - FARDAR: CSV import (district → courier_states, city → courier_cities)
 */
class CourierLocationSyncService
{
    public const CODE_ROYAL = 'ROYAL';

    public const CODE_TRANSEXPRESS = 'TRANSEXPRESS';

    public const CODE_FARDAR = 'FARDAR';

    public function __construct(
        private readonly CurfoxLocationMapper $curfoxMapper,
        private readonly TransExpressLocationMapper $transExpressMapper,
        private readonly FardarLocationCsvMapper $fardarMapper,
    ) {
    }

    /**
     * Resolve provider-level Curfox credentials from configuration.
     *
     * @return array{base_url: string, token: string, tenant: string}
     */
    public function credentialsFromConfig(): array
    {
        return $this->curfoxCredentialsFromConfig();
    }

    /**
     * @return array<string, string>
     */
    public function credentialsFromConfigForCourier(Courier $courier): array
    {
        $code = $this->normalizeCourierCode((string) $courier->code);

        return match ($code) {
            self::CODE_ROYAL => $this->curfoxCredentialsFromConfig(),
            self::CODE_TRANSEXPRESS => $this->transExpressCredentialsFromConfig(),
            self::CODE_FARDAR => throw new RuntimeException(
                'Fardar locations are imported from CSV via courier:import-locations, not API sync.'
            ),
            default => throw new RuntimeException(
                'Location sync is not implemented for courier code: '.$code
            ),
        };
    }

    /**
     * @param  array<string, string>|null  $credentials
     */
    public function sync(Courier $courier, ?array $credentials = null): CourierLocationSyncResult
    {
        $code = $this->normalizeCourierCode((string) $courier->code);
        $credentials ??= $this->credentialsFromConfigForCourier($courier);

        return match ($code) {
            self::CODE_ROYAL => $this->syncCurfox($courier, $credentials),
            self::CODE_TRANSEXPRESS => $this->syncTransExpress($courier, $credentials),
            self::CODE_FARDAR => throw new RuntimeException(
                'Fardar locations are imported from CSV via courier:import-locations, not API sync.'
            ),
            default => throw new RuntimeException(
                'Location sync is not implemented for courier code: '.$code
            ),
        };
    }

    /**
     * Import Fardar district/city master data from a CSV file.
     *
     * Expected columns (underscore or space variants):
     *   city_id, city_name, zone_id, zone name, district_id, district name
     *
     * Mapping:
     * - district_id / district name → courier_states (external_id / name)
     * - city_id / city_name → courier_cities (external_id / name), parented by district_id
     * - zone_id / zone name → ignored (no zone storage in shared schema)
     *
     * Identity: (courier_id, external_id) for both districts (states) and cities.
     */
    public function importFromCsv(Courier $courier, string $csvPath): CourierLocationSyncResult
    {
        $code = $this->normalizeCourierCode((string) $courier->code);

        if ($code !== self::CODE_FARDAR) {
            throw new RuntimeException(
                'CSV location import is currently implemented for FARDAR only. Received: '.$code
            );
        }

        $result = new CourierLocationSyncResult;
        $absolutePath = $this->resolveReadableCsvPath($csvPath);

        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open Fardar CSV file: '.$absolutePath);
        }

        try {
            $headerRow = fgetcsv($handle);
            if ($headerRow === false || $headerRow === [null] || $headerRow === []) {
                throw new RuntimeException('Fardar CSV file is empty or missing a header row.');
            }

            /** @var list<string|null> $headerRow */
            $columnIndex = $this->fardarMapper->validateAndIndexHeaders($headerRow);

            /** @var array<string, MappedCourierState> $districtsByExternalId */
            $districtsByExternalId = [];
            /** @var array<string, MappedCourierCity> $citiesByExternalId */
            $citiesByExternalId = [];
            /** @var array<string, true> $seenCityKeys */
            $seenCityKeys = [];

            $lineNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $lineNumber++;

                /** @var list<string|null> $row */
                $mapped = $this->fardarMapper->mapRow($row, $columnIndex, $lineNumber);

                if ($mapped['ok'] === false) {
                    if ($mapped['skip'] === true) {
                        $result->rowsSkipped++;

                        continue;
                    }

                    $result->csvRows++;
                    $this->recordError($result, (string) ($mapped['message'] ?? 'Malformed CSV row.'));

                    continue;
                }

                $result->csvRows++;

                /** @var MappedCourierState $district */
                $district = $mapped['district'];
                /** @var MappedCourierCity $city */
                $city = $mapped['city'];

                $districtsByExternalId[$district->externalId] = $district;

                $cityKey = $city->externalId;
                if (isset($seenCityKeys[$cityKey])) {
                    // Duplicate city external ID within the same file: keep last mapping, count as skip.
                    $result->rowsSkipped++;
                }
                $seenCityKeys[$cityKey] = true;
                $citiesByExternalId[$cityKey] = $city;
            }
        } finally {
            fclose($handle);
        }

        $result->districtsMapped = count($districtsByExternalId);
        $result->citiesMapped = count($citiesByExternalId);

        if ($districtsByExternalId === [] && $citiesByExternalId === []) {
            return $result;
        }

        $syncedAt = now();

        try {
            DB::transaction(function () use (
                $courier,
                $districtsByExternalId,
                $citiesByExternalId,
                $syncedAt,
                $result
            ): void {
                $stateLookup = [];

                foreach ($districtsByExternalId as $mappedDistrict) {
                    $stateLookup[$mappedDistrict->externalId] = $this->upsertState(
                        $courier,
                        $mappedDistrict,
                        $syncedAt,
                        $result
                    );
                }

                foreach ($citiesByExternalId as $mappedCity) {
                    $this->upsertCity($courier, $mappedCity, $stateLookup, $syncedAt, $result);
                }
            });
        } catch (Throwable $e) {
            $this->recordError(
                $result,
                'Failed to persist Fardar location master data: '.$e->getMessage(),
                $e
            );

            $result->statesCreated = 0;
            $result->statesUpdated = 0;
            $result->citiesCreated = 0;
            $result->citiesUpdated = 0;
            $result->citiesSkipped = 0;
        }

        return $result;
    }

    private function resolveReadableCsvPath(string $csvPath): string
    {
        $path = trim($csvPath);

        if ($path === '') {
            throw new RuntimeException('CSV file path is required.');
        }

        if (! is_file($path)) {
            throw new RuntimeException('CSV file not found: '.$path);
        }

        if (! is_readable($path)) {
            throw new RuntimeException('CSV file is not readable: '.$path);
        }

        return $path;
    }

    /**
     * @param  array{base_url: string, token: string, tenant: string}|null  $credentials
     */
    public function syncStatesOnly(Courier $courier, ?array $credentials = null): CourierLocationSyncResult
    {
        $this->assertRoyal($courier);

        $result = new CourierLocationSyncResult;
        $credentials ??= $this->curfoxCredentialsFromConfig();
        $client = $this->makeCurfoxClient($credentials);
        $syncedAt = now();

        try {
            $states = $this->curfoxMapper->mapStates($client->getStates());
        } catch (Throwable $e) {
            $this->recordError($result, 'Failed to fetch Curfox states: '.$e->getMessage(), $e);

            return $result;
        }

        try {
            DB::transaction(function () use ($courier, $states, $syncedAt, $result): void {
                foreach ($states as $mapped) {
                    $this->upsertState($courier, $mapped, $syncedAt, $result);
                }
            });
        } catch (Throwable $e) {
            $this->recordError(
                $result,
                'Failed to persist courier states: '.$e->getMessage(),
                $e
            );
            $result->statesCreated = 0;
            $result->statesUpdated = 0;
        }

        return $result;
    }

    /**
     * Import cities from the City API (requires states already present locally).
     *
     * @param  array{base_url: string, token: string, tenant: string}|null  $credentials
     */
    public function syncCitiesOnly(Courier $courier, ?array $credentials = null): CourierLocationSyncResult
    {
        $this->assertRoyal($courier);

        $result = new CourierLocationSyncResult;
        $credentials ??= $this->curfoxCredentialsFromConfig();
        $client = $this->makeCurfoxClient($credentials);
        $syncedAt = now();

        try {
            $cities = $this->curfoxMapper->mapCities($client->getCities());
        } catch (Throwable $e) {
            $this->recordError($result, 'Failed to fetch Curfox cities: '.$e->getMessage(), $e);

            return $result;
        }

        $stateLookup = CourierState::query()
            ->where('courier_id', $courier->id)
            ->get(['id', 'external_id', 'name'])
            ->keyBy(static fn (CourierState $state) => (string) $state->external_id)
            ->all();

        try {
            DB::transaction(function () use ($courier, $cities, $stateLookup, $syncedAt, $result): void {
                foreach ($cities as $mappedCity) {
                    $this->upsertCity($courier, $mappedCity, $stateLookup, $syncedAt, $result);
                }
            });
        } catch (Throwable $e) {
            $this->recordError(
                $result,
                'Failed to persist courier cities: '.$e->getMessage(),
                $e
            );
            $result->citiesCreated = 0;
            $result->citiesUpdated = 0;
            $result->citiesSkipped = 0;
        }

        return $result;
    }

    /**
     * @param  array{base_url: string, token: string, tenant: string}  $credentials
     */
    private function syncCurfox(Courier $courier, array $credentials): CourierLocationSyncResult
    {
        $result = new CourierLocationSyncResult;
        $client = $this->makeCurfoxClient($credentials);
        $syncedAt = now();

        try {
            $rawStates = $client->getStates();
            $states = $this->curfoxMapper->mapStates($rawStates);
        } catch (Throwable $e) {
            $this->recordError($result, 'Failed to fetch Curfox states: '.$e->getMessage(), $e);

            return $result;
        }

        try {
            $rawCities = $client->getCities();
            $cities = $this->curfoxMapper->mapCities($rawCities);
        } catch (Throwable $e) {
            $this->recordError($result, 'Failed to fetch Curfox cities: '.$e->getMessage(), $e);

            return $result;
        }

        try {
            DB::transaction(function () use ($courier, $states, $cities, $syncedAt, $result): void {
                $stateLookup = [];

                foreach ($states as $mappedState) {
                    /** @var MappedCourierState $mappedState */
                    $stateLookup[$mappedState->externalId] = $this->upsertState(
                        $courier,
                        $mappedState,
                        $syncedAt,
                        $result
                    );
                }

                foreach ($cities as $mappedCity) {
                    /** @var MappedCourierCity $mappedCity */
                    $this->upsertCity($courier, $mappedCity, $stateLookup, $syncedAt, $result);
                }
            });
        } catch (Throwable $e) {
            $this->recordError(
                $result,
                'Failed to persist courier location master data: '.$e->getMessage(),
                $e
            );

            // Counters from a rolled-back transaction must not be reported as success.
            $result->statesCreated = 0;
            $result->statesUpdated = 0;
            $result->citiesCreated = 0;
            $result->citiesUpdated = 0;
            $result->citiesSkipped = 0;
        }

        return $result;
    }

    /**
     * Hierarchical TransExpress sync:
     * GET /provinces → GET /districts?province_id= → GET /cities?district_id=
     *
     * Districts become courier_states (UI district dropdown).
     * Cities become courier_cities linked to those states.
     * Province ID is stored on the district state as external_ref_no.
     *
     * @param  array{base_url: string}  $credentials
     */
    private function syncTransExpress(Courier $courier, array $credentials): CourierLocationSyncResult
    {
        $result = new CourierLocationSyncResult;
        $client = $this->makeTransExpressClient($credentials);
        $syncedAt = now();

        try {
            $provinces = $this->transExpressMapper->mapProvinces($client->getProvinces());
        } catch (Throwable $e) {
            $this->recordError($result, 'Failed to fetch TransExpress provinces: '.$e->getMessage(), $e);

            return $result;
        }

        if ($provinces === []) {
            $this->recordError($result, 'TransExpress provinces response was empty.');

            return $result;
        }

        Log::info('TransExpress location sync: provinces fetched.', [
            'courier_id' => $courier->id,
            'courier_code' => $courier->code,
            'provinces' => count($provinces),
        ]);

        /** @var list<MappedCourierState> $districts */
        $districts = [];
        /** @var list<MappedCourierCity> $cities */
        $cities = [];

        foreach ($provinces as $province) {
            $provinceId = $province['external_id'];
            $provinceName = $province['name'];

            try {
                $mappedDistricts = $this->transExpressMapper->mapDistricts(
                    $client->getDistricts($provinceId),
                    $provinceId
                );
            } catch (Throwable $e) {
                $this->recordError(
                    $result,
                    'Failed to fetch TransExpress districts for province '.$provinceId
                    .' ('.$provinceName.'): '.$e->getMessage(),
                    $e
                );

                return $result;
            }

            Log::info('TransExpress location sync: districts fetched.', [
                'courier_id' => $courier->id,
                'courier_code' => $courier->code,
                'province_external_id' => $provinceId,
                'province_name' => $provinceName,
                'districts' => count($mappedDistricts),
            ]);

            foreach ($mappedDistricts as $mappedDistrict) {
                $districts[] = $mappedDistrict;

                try {
                    $mappedCities = $this->transExpressMapper->mapCities(
                        $client->getCitiesByDistrict($mappedDistrict->externalId),
                        $mappedDistrict->externalId
                    );
                } catch (Throwable $e) {
                    $this->recordError(
                        $result,
                        'Failed to fetch TransExpress cities for district '.$mappedDistrict->externalId
                        .' ('.$mappedDistrict->name.') in province '.$provinceId
                        .' ('.$provinceName.'): '.$e->getMessage(),
                        $e
                    );

                    return $result;
                }

                Log::info('TransExpress location sync: cities fetched.', [
                    'courier_id' => $courier->id,
                    'courier_code' => $courier->code,
                    'province_external_id' => $provinceId,
                    'district_external_id' => $mappedDistrict->externalId,
                    'district_name' => $mappedDistrict->name,
                    'cities' => count($mappedCities),
                ]);

                foreach ($mappedCities as $mappedCity) {
                    $cities[] = $mappedCity;
                }
            }
        }

        try {
            DB::transaction(function () use ($courier, $districts, $cities, $syncedAt, $result): void {
                $stateLookup = [];

                foreach ($districts as $mappedDistrict) {
                    $stateLookup[$mappedDistrict->externalId] = $this->upsertState(
                        $courier,
                        $mappedDistrict,
                        $syncedAt,
                        $result
                    );
                }

                foreach ($cities as $mappedCity) {
                    $this->upsertCity($courier, $mappedCity, $stateLookup, $syncedAt, $result);
                }
            });
        } catch (Throwable $e) {
            $this->recordError(
                $result,
                'Failed to persist TransExpress location master data: '.$e->getMessage(),
                $e
            );

            $result->statesCreated = 0;
            $result->statesUpdated = 0;
            $result->citiesCreated = 0;
            $result->citiesUpdated = 0;
            $result->citiesSkipped = 0;
        }

        return $result;
    }

    /**
     * @return array{base_url: string, token: string, tenant: string}
     */
    private function curfoxCredentialsFromConfig(): array
    {
        $baseUrl = trim((string) (
            config('services.curfox.base_url')
            ?: config('feeder.curfox.base_url')
            ?: ''
        ));
        $token = CurfoxApiClient::normalizeBearerToken((string) (
            config('services.curfox.token')
            ?: config('feeder.curfox.token')
            ?: ''
        ));
        $tenant = trim((string) (
            config('services.curfox.tenant')
            ?: config('feeder.curfox.tenant')
            ?: ''
        ));

        if ($baseUrl === '' || $token === '' || $tenant === '') {
            throw new RuntimeException(
                'Curfox location sync credentials are incomplete.'
                .' Set CURFOX_BASE_URL, CURFOX_TOKEN, and CURFOX_TENANT in the environment.'
            );
        }

        return [
            'base_url' => $baseUrl,
            'token' => $token,
            'tenant' => $tenant,
        ];
    }

    /**
     * @return array{base_url: string}
     */
    private function transExpressCredentialsFromConfig(): array
    {
        $baseUrl = trim((string) (
            config('services.transexpress.base_url')
            ?: config('feeder.transexpress.base_url')
            ?: ''
        ));

        if ($baseUrl === '') {
            throw new RuntimeException(
                'TransExpress location sync base URL is incomplete.'
                .' Set TRANSEXPRESS_BASE_URL in the environment.'
            );
        }

        return [
            'base_url' => $baseUrl,
        ];
    }

    /**
     * @param  array{base_url: string, token: string, tenant: string}  $credentials
     */
    private function makeCurfoxClient(array $credentials): CurfoxApiClient
    {
        return new CurfoxApiClient([
            'base_url' => (string) ($credentials['base_url'] ?? ''),
            'token' => (string) ($credentials['token'] ?? ''),
            'tenant' => (string) ($credentials['tenant'] ?? ''),
        ]);
    }

    /**
     * @param  array{base_url: string}  $credentials
     */
    private function makeTransExpressClient(array $credentials): TransExpressApiClient
    {
        return new TransExpressApiClient([
            'base_url' => (string) ($credentials['base_url'] ?? ''),
        ]);
    }

    private function assertRoyal(Courier $courier): void
    {
        if ($this->normalizeCourierCode((string) $courier->code) !== self::CODE_ROYAL) {
            throw new RuntimeException(
                'Location sync is only implemented for Courier 1 (ROYAL / Curfox).'
            );
        }
    }

    private function normalizeCourierCode(string $code): string
    {
        $normalized = strtoupper(trim($code));

        // Accept common short alias without introducing a second courier row.
        if ($normalized === 'TRANSEX') {
            return self::CODE_TRANSEXPRESS;
        }

        return $normalized;
    }

    private function upsertState(
        Courier $courier,
        MappedCourierState $mapped,
        mixed $syncedAt,
        CourierLocationSyncResult $result,
    ): CourierState {
        $existing = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', $mapped->externalId)
            ->first();

        $attributes = [
            'external_ref_no' => $mapped->externalRefNo,
            'name' => $mapped->name,
            'is_active' => $mapped->isActive,
            'last_synced_at' => $syncedAt,
        ];

        if ($existing === null) {
            $created = CourierState::query()->create([
                'courier_id' => $courier->id,
                'external_id' => $mapped->externalId,
                ...$attributes,
            ]);
            $result->statesCreated++;

            return $created;
        }

        $previousName = (string) $existing->name;
        $existing->fill($attributes);
        $existing->save();
        $result->statesUpdated++;

        if ($previousName !== (string) $existing->name) {
            $this->cascadeStateNameToCities($courier, $existing);
        }

        return $existing;
    }

    /**
     * Keep denormalized city district fields aligned when a provider state is renamed.
     * Cities not present in the current city payload would otherwise keep a stale district_name.
     */
    private function cascadeStateNameToCities(Courier $courier, CourierState $state): void
    {
        CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('courier_state_id', $state->id)
            ->update([
                'district_name' => (string) $state->name,
                'external_district_code' => (string) $state->external_id,
            ]);
    }

    /**
     * @param  array<string, CourierState>  $stateLookup
     */
    private function upsertCity(
        Courier $courier,
        MappedCourierCity $mapped,
        array $stateLookup,
        mixed $syncedAt,
        CourierLocationSyncResult $result,
    ): void {
        $state = $stateLookup[$mapped->providerStateExternalId] ?? null;

        if ($state === null) {
            $result->citiesSkipped++;
            Log::warning('Courier city sync skipped: local state not found.', [
                'courier_id' => $courier->id,
                'courier_code' => $courier->code,
                'city_external_id' => $mapped->externalId,
                'provider_state_external_id' => $mapped->providerStateExternalId,
            ]);

            return;
        }

        $existing = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', $mapped->externalId)
            ->first();

        $attributes = [
            'courier_state_id' => $state->id,
            'external_ref_no' => $mapped->externalRefNo,
            'name' => $mapped->name,
            'postal_code' => $mapped->postalCode,
            'is_active' => $mapped->isActive,
            'last_synced_at' => $syncedAt,
            // Dual-write for existing Order UI lookups (district/city dropdowns).
            'city_name' => $mapped->name,
            'district_name' => (string) $state->name,
            'external_city_code' => $mapped->externalId,
            'external_district_code' => (string) $state->external_id,
        ];

        if ($existing === null) {
            CourierCity::query()->create([
                'courier_id' => $courier->id,
                'external_id' => $mapped->externalId,
                ...$attributes,
            ]);
            $result->citiesCreated++;

            return;
        }

        $existing->fill($attributes);
        $existing->save();
        $result->citiesUpdated++;
    }

    private function recordError(CourierLocationSyncResult $result, string $message, ?Throwable $e = null): void
    {
        $result->errors++;
        $result->errorMessages[] = $message;

        Log::error($message, [
            'exception' => $e instanceof Throwable ? $e::class : null,
        ]);
    }
}
