<?php

namespace Feeder\Core\Console\Commands;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Services\Courier\CourierLocationSyncService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Import provider location master data from a CSV file into local ERP tables.
 *
 * Currently implemented for Fardar Express Domestic (district → state, city → city).
 * Does not use supplier credentials. Safe to re-run (upsert by courier + external_id).
 */
class ImportCourierLocationsCommand extends Command
{
    protected $signature = 'courier:import-locations
                            {courier : Courier code (e.g. FARDAR)}
                            {file : Absolute or relative path to the location CSV file}';

    protected $description = 'Import courier district and city lists from a CSV file into the ERP database';

    public function handle(CourierLocationSyncService $syncService): int
    {
        $courierCode = strtoupper(trim((string) $this->argument('courier')));
        $filePath = (string) $this->argument('file');

        try {
            $courier = $this->resolveCourier($courierCode);
            $providerCode = $this->normalizeProviderCode((string) $courier->code);

            if ($providerCode !== CourierLocationSyncService::CODE_FARDAR) {
                throw new RuntimeException(
                    'CSV location import is currently implemented for FARDAR only. Received: '.$providerCode
                );
            }

            $this->info('Starting location import for '.$courier->name.' ('.$courier->code.').');
            $this->line('Source CSV: '.$filePath);
            $this->line('Hierarchy: district (courier_states) → city (courier_cities).');

            $result = $syncService->importFromCsv($courier, $filePath);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage().' | exception='.$e::class);

            return self::FAILURE;
        }

        $statesTotal = CourierState::query()->where('courier_id', $courier->id)->count();
        $citiesTotal = CourierCity::query()->where('courier_id', $courier->id)->count();

        $this->newLine();
        $this->info('Fardar location import completed.');
        $this->newLine();
        $this->line('Districts created: '.$result->statesCreated);
        $this->line('Districts updated: '.$result->statesUpdated);
        $this->line('Cities created: '.$result->citiesCreated);
        $this->line('Cities updated: '.$result->citiesUpdated);
        $this->line('Rows skipped: '.$result->rowsSkipped);
        $this->line('Errors: '.$result->errors);
        $this->newLine();
        $this->line('Districts total: '.$statesTotal);
        $this->line('Cities total: '.$citiesTotal);

        if ($result->errorMessages !== []) {
            $this->newLine();
            $this->warn('Error details:');
            foreach (array_slice($result->errorMessages, 0, 20) as $message) {
                $this->line(' - '.$message);
            }

            if (count($result->errorMessages) > 20) {
                $this->line(' - ... and '.(count($result->errorMessages) - 20).' more (see application log).');
            }
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveCourier(string $courierCode): Courier
    {
        if ($courierCode === '') {
            throw new RuntimeException('Courier code is required.');
        }

        $normalized = $this->normalizeProviderCode($courierCode);

        $courier = Courier::query()
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->first();

        if ($courier === null) {
            throw new RuntimeException('Courier not found for code: '.$courierCode);
        }

        if (! $courier->is_active) {
            throw new RuntimeException('Courier is inactive: '.$courierCode);
        }

        return $courier;
    }

    private function normalizeProviderCode(string $code): string
    {
        return strtoupper(trim($code));
    }
}
