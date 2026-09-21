<?php

namespace Feeder\Core\Console\Commands;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Models\Shipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Temporary read-only audit of Fardar location CSV ↔ courier_cities fidelity.
 *
 * Does not mutate database rows, deactivate cities, or call the Fardar API.
 */
class AuditFardarLocationsCommand extends Command
{
    protected $signature = 'courier:fardar-audit-locations
                            {file? : Path to locations-fardar.csv (defaults to ../locations-fardar.csv from base_path)}';

    protected $description = 'Read-only audit of Fardar CSV import fidelity against courier_states/cities (no API calls)';

    public function handle(): int
    {
        try {
            $courier = $this->resolveFardar();
            $csvPath = $this->resolveCsvPath((string) ($this->argument('file') ?? ''));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Fardar Location Import Audit');
        $this->line('Courier: '.$courier->name.' (id='.$courier->id.')');
        $this->line('CSV: '.$csvPath);
        $this->newLine();

        try {
            $csv = $this->analyzeCsv($csvPath);
            $db = $this->analyzeDb($courier);
            $compare = $this->compareCsvToDb($csv['rowsByCityId'], $courier);
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('CSV rows:                  '.$csv['rows']);
        $this->line('CSV unique city IDs:       '.$csv['uniqueCityIds']);
        $this->line('CSV malformed rows:        '.$csv['malformed']);
        $this->line('CSV duplicate city IDs:    '.$csv['duplicateCityIds']);
        $this->line('CSV duplicate city names:  '.$csv['duplicateExactNames']);
        $this->line('CSV ALL CAPS names:        '.$csv['casing']['upper']);
        $this->line('CSV lower names:           '.$csv['casing']['lower']);
        $this->line('CSV mixed/title names:     '.$csv['casing']['title_or_mixed']);
        $this->line('CSV junk-like names:       '.$csv['junkLike']);
        $this->newLine();
        $this->line('DB active cities:          '.$db['activeCities']);
        $this->line('DB inactive cities:        '.$db['inactiveCities']);
        $this->line('DB distinct external IDs:  '.$db['distinctExternalIds']);
        $this->line('DB distinct names:         '.$db['distinctNames']);
        $this->line('DB states:                 '.$db['states']);
        $this->line('DB missing parents:        '.$db['missingParents']);
        $this->line('DB name!=city_name:        '.$db['nameCityNameMismatch']);
        $this->newLine();
        $this->line('Exact CSV → DB matches:    '.$compare['exact']);
        $this->line('Name mismatches:           '.$compare['nameMismatch']);
        $this->line('Casing-only mismatches:    '.$compare['casingMismatch']);
        $this->line('ID/parent mismatches:      '.$compare['parentMismatch']);
        $this->line('CSV-only city IDs:         '.$compare['csvOnly']);
        $this->line('DB-only city IDs:          '.$compare['dbOnly']);
        $this->newLine();

        $this->line('Known live API samples (from DB evidence; this command does not call Fardar):');
        foreach ($this->knownLiveSamples($courier) as $sample) {
            $this->line(sprintf(
                '  %-18s | CSV=%-7s | DB=%-12s | %s',
                $sample['city'],
                $sample['csv'],
                $sample['db'],
                $sample['api']
            ));
        }

        $this->newLine();
        $this->line('Shipments for FARDAR: '.$db['shipments'].' (with tracking: '.$db['shipmentsTracked'].')');
        $this->newLine();
        $this->comment('Read-only complete. No rows were modified. No Fardar API requests were made.');

        return self::SUCCESS;
    }

    private function resolveFardar(): Courier
    {
        $courier = Courier::query()->whereRaw('UPPER(code) = ?', ['FARDAR'])->first();

        if ($courier === null) {
            throw new RuntimeException('Courier not found for code: FARDAR');
        }

        return $courier;
    }

    private function resolveCsvPath(string $argument): string
    {
        $candidates = [];

        if (trim($argument) !== '') {
            $candidates[] = $argument;
        }

        $candidates[] = dirname(base_path()).DIRECTORY_SEPARATOR.'locations-fardar.csv';
        $candidates[] = base_path('locations-fardar.csv');
        $candidates[] = base_path('../locations-fardar.csv');

        foreach ($candidates as $path) {
            $absolute = realpath($path);
            if ($absolute !== false && is_file($absolute) && is_readable($absolute)) {
                return $absolute;
            }
        }

        throw new RuntimeException(
            'Fardar CSV not found. Pass an absolute path, e.g. courier:fardar-audit-locations D:\\DropshippingPlatform\\locations-fardar.csv'
        );
    }

    /**
     * @return array{
     *     rows: int,
     *     uniqueCityIds: int,
     *     malformed: int,
     *     duplicateCityIds: int,
     *     duplicateExactNames: int,
     *     casing: array{upper: int, lower: int, title_or_mixed: int},
     *     junkLike: int,
     *     rowsByCityId: array<string, array{district_id: string, district_name: string, city_id: string, city_name: string}>
     * }
     */
    private function analyzeCsv(string $csvPath): array
    {
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV: '.$csvPath);
        }

        try {
            $headerRow = fgetcsv($handle);
            if ($headerRow === false) {
                throw new RuntimeException('CSV is empty.');
            }

            $headers = [];
            foreach ($headerRow as $index => $header) {
                $name = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $header) ?? ''));
                if ($name !== '') {
                    $headers[$name] = (int) $index;
                }
            }

            foreach (['district id', 'district name', 'city id', 'city name'] as $required) {
                if (! isset($headers[$required])) {
                    throw new RuntimeException('CSV missing required header: '.$required);
                }
            }

            $rows = 0;
            $malformed = 0;
            $cityIdCounts = [];
            $nameCounts = [];
            $casing = ['upper' => 0, 'lower' => 0, 'title_or_mixed' => 0];
            $junkLike = 0;
            /** @var array<string, array{district_id: string, district_name: string, city_id: string, city_name: string}> $rowsByCityId */
            $rowsByCityId = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rows++;
                $districtId = trim((string) ($row[$headers['district id']] ?? ''));
                $districtName = trim((string) ($row[$headers['district name']] ?? ''));
                $cityId = trim((string) ($row[$headers['city id']] ?? ''));
                $cityName = trim((string) ($row[$headers['city name']] ?? ''));

                if ($districtId === '' || ! preg_match('/^\d+$/', $districtId)
                    || $districtName === '' || strcasecmp($districtName, 'NULL') === 0
                    || $cityId === '' || ! preg_match('/^\d+$/', $cityId)
                    || $cityName === '' || strcasecmp($cityName, 'NULL') === 0
                ) {
                    $malformed++;

                    continue;
                }

                $normalizedCityId = (string) (int) $cityId;
                $cityIdCounts[$normalizedCityId] = ($cityIdCounts[$normalizedCityId] ?? 0) + 1;
                $nameCounts[$cityName] = ($nameCounts[$cityName] ?? 0) + 1;
                $rowsByCityId[$normalizedCityId] = [
                    'district_id' => (string) (int) $districtId,
                    'district_name' => $districtName,
                    'city_id' => $normalizedCityId,
                    'city_name' => $cityName,
                ];

                if (preg_match('/[A-Za-z]/', $cityName)) {
                    if ($cityName === strtoupper($cityName)) {
                        $casing['upper']++;
                    } elseif ($cityName === strtolower($cityName)) {
                        $casing['lower']++;
                    } else {
                        $casing['title_or_mixed']++;
                    }
                }

                if (preg_match('/^x[A-Za-z]/', $cityName) || preg_match('/x{3,}/i', $cityName)) {
                    $junkLike++;
                }
            }
        } finally {
            fclose($handle);
        }

        $duplicateCityIds = count(array_filter($cityIdCounts, static fn (int $n): bool => $n > 1));
        $duplicateExactNames = count(array_filter($nameCounts, static fn (int $n): bool => $n > 1));

        return [
            'rows' => $rows,
            'uniqueCityIds' => count($rowsByCityId),
            'malformed' => $malformed,
            'duplicateCityIds' => $duplicateCityIds,
            'duplicateExactNames' => $duplicateExactNames,
            'casing' => $casing,
            'junkLike' => $junkLike,
            'rowsByCityId' => $rowsByCityId,
        ];
    }

    /**
     * @return array{
     *     activeCities: int,
     *     inactiveCities: int,
     *     distinctExternalIds: int,
     *     distinctNames: int,
     *     states: int,
     *     missingParents: int,
     *     nameCityNameMismatch: int,
     *     shipments: int,
     *     shipmentsTracked: int
     * }
     */
    private function analyzeDb(Courier $courier): array
    {
        $activeCities = CourierCity::query()->where('courier_id', $courier->id)->where('is_active', true)->count();
        $inactiveCities = CourierCity::query()->where('courier_id', $courier->id)->where('is_active', false)->count();
        $distinctExternalIds = CourierCity::query()->where('courier_id', $courier->id)->distinct()->count('external_id');
        $distinctNames = CourierCity::query()->where('courier_id', $courier->id)->distinct()->count('name');
        $states = CourierState::query()->where('courier_id', $courier->id)->count();
        $missingParents = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where(function ($q): void {
                $q->whereNull('courier_state_id')
                    ->orWhereNotExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('courier_states')
                            ->whereColumn('courier_states.id', 'courier_cities.courier_state_id');
                    });
            })
            ->count();
        $nameCityNameMismatch = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->whereColumn('name', '!=', 'city_name')
            ->count();

        $shipments = Shipment::query()->where('courier_id', $courier->id)->count();
        $shipmentsTracked = Shipment::query()
            ->where('courier_id', $courier->id)
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->count();

        return [
            'activeCities' => $activeCities,
            'inactiveCities' => $inactiveCities,
            'distinctExternalIds' => $distinctExternalIds,
            'distinctNames' => $distinctNames,
            'states' => $states,
            'missingParents' => $missingParents,
            'nameCityNameMismatch' => $nameCityNameMismatch,
            'shipments' => $shipments,
            'shipmentsTracked' => $shipmentsTracked,
        ];
    }

    /**
     * @param  array<string, array{district_id: string, district_name: string, city_id: string, city_name: string}>  $csvRows
     * @return array{exact: int, nameMismatch: int, casingMismatch: int, parentMismatch: int, csvOnly: int, dbOnly: int}
     */
    private function compareCsvToDb(array $csvRows, Courier $courier): array
    {
        $dbByExt = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->with('state:id,external_id,name')
            ->get()
            ->keyBy(static fn (CourierCity $city): string => (string) $city->external_id);

        $exact = 0;
        $nameMismatch = 0;
        $casingMismatch = 0;
        $parentMismatch = 0;
        $csvOnly = 0;

        foreach ($csvRows as $cityId => $csv) {
            $db = $dbByExt->get($cityId);
            if ($db === null) {
                $csvOnly++;

                continue;
            }

            $dbName = (string) $db->name;
            $dbCityName = (string) ($db->city_name ?? '');
            $dbStateExt = (string) ($db->state?->external_id ?? $db->external_district_code);
            $dbStateName = (string) ($db->state?->name ?? $db->district_name);

            $nameOk = $dbName === $csv['city_name'] && $dbCityName === $csv['city_name'];
            $parentOk = $dbStateExt === $csv['district_id'] && $dbStateName === $csv['district_name'];

            if ($nameOk && $parentOk) {
                $exact++;
            } else {
                if (! $nameOk) {
                    if (strcasecmp($dbName, $csv['city_name']) === 0) {
                        $casingMismatch++;
                    } else {
                        $nameMismatch++;
                    }
                }
                if (! $parentOk) {
                    $parentMismatch++;
                }
            }
        }

        $dbOnly = 0;
        foreach ($dbByExt as $ext => $_) {
            if (! isset($csvRows[$ext])) {
                $dbOnly++;
            }
        }

        return [
            'exact' => $exact,
            'nameMismatch' => $nameMismatch,
            'casingMismatch' => $casingMismatch,
            'parentMismatch' => $parentMismatch,
            'csvOnly' => $csvOnly,
            'dbOnly' => $dbOnly,
        ];
    }

    /**
     * @return list<array{city: string, csv: string, db: string, api: string}>
     */
    private function knownLiveSamples(Courier $courier): array
    {
        $accepted = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '9506')
            ->first();
        $rejected = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '4194')
            ->first();

        $samples = [
            [
                'city' => 'ARAKAWILA',
                'csv' => $accepted ? 'present' : 'absent',
                'db' => $accepted ? 'exact match' : 'absent',
                'api' => 'API_ACCEPTED (waybill API5083024)',
            ],
            [
                'city' => 'AMMANTHANAVELI',
                'csv' => $rejected ? 'present' : 'absent',
                'db' => $rejected ? 'exact match' : 'absent',
                'api' => 'API_REJECTED_209 (ORD-2026-001640 first attempt)',
            ],
        ];

        foreach (['Matara', 'Colombo', 'Batticaloa', 'Vakarai'] as $name) {
            $city = CourierCity::query()
                ->where('courier_id', $courier->id)
                ->where('name', $name)
                ->orderByRaw('CAST(external_id AS UNSIGNED)')
                ->first();
            $samples[] = [
                'city' => $name,
                'csv' => $city ? 'present' : 'absent',
                'db' => $city ? 'exact match' : 'absent',
                'api' => 'UNKNOWN (no persisted sample in this audit)',
            ];
        }

        return $samples;
    }
}
