<?php

namespace Feeder\Core\Console\Commands;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Services\Courier\CourierLocationSyncService;
use Feeder\Core\Services\Courier\Curfox\CurfoxApiClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Sync provider state/city master data into the local ERP tables.
 *
 * Credentials come from provider configuration (CURFOX_* / TRANSEXPRESS_*) —
 * never from supplier accounts and never from hard-coded secrets.
 * Optional CLI overrides apply to this run only.
 */
class SyncCourierLocationsCommand extends Command
{
    protected $signature = 'courier:sync-locations
                            {courier : Courier code (e.g. ROYAL, TRANSEXPRESS)}
                            {--base-url= : Optional API base URL override for this run}
                            {--tenant= : Optional Curfox X-tenant override for this run}
                            {--token= : Optional temporary Curfox API token override for this run (not persisted)}';

    protected $description = 'Sync courier state and city lists from the provider API into the ERP database';

    public function handle(CourierLocationSyncService $syncService): int
    {
        $courierCode = strtoupper(trim((string) $this->argument('courier')));

        try {
            $courier = $this->resolveCourier($courierCode);
            $providerCode = $this->normalizeProviderCode((string) $courier->code);
            $credentials = $this->resolveCredentials($providerCode);

            $this->info('Starting location sync for '.$courier->name.' ('.$courier->code.').');

            if ($providerCode === CourierLocationSyncService::CODE_TRANSEXPRESS) {
                $this->line('Using TransExpress base URL from configuration (no authentication required).');
                $this->line('Hierarchy: province → district (courier_states) → city (courier_cities).');
            } else {
                $this->line('Using provider Curfox credentials from configuration (not supplier accounts).');
            }

            if (
                $providerCode === CourierLocationSyncService::CODE_ROYAL
                && trim((string) $this->option('token')) !== ''
            ) {
                $this->line('Using temporary --token override for this run (not persisted).');
            }

            $result = $syncService->sync($courier, $credentials);
        } catch (RuntimeException $e) {
            $this->error($this->formatThrowableDiagnostic($e));

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error($this->formatThrowableDiagnostic($e));

            return self::FAILURE;
        }

        $statesTotal = CourierState::query()->where('courier_id', $courier->id)->count();
        $statesActive = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('is_active', true)
            ->count();
        $citiesTotal = CourierCity::query()->where('courier_id', $courier->id)->count();
        $citiesActive = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('is_active', true)
            ->count();
        $citiesInactive = $citiesTotal - $citiesActive;

        $this->newLine();
        $this->info($courier->code.' location sync completed.');
        $this->newLine();

        if ($providerCode === CourierLocationSyncService::CODE_TRANSEXPRESS) {
            $this->line('Districts (courier_states):');
        } else {
            $this->line('States:');
        }

        $this->line('  Created: '.$result->statesCreated);
        $this->line('  Updated: '.$result->statesUpdated);
        $this->line('  Total: '.$statesTotal);
        $this->line('  Active: '.$statesActive);
        $this->newLine();
        $this->line('Cities:');
        $this->line('  Created: '.$result->citiesCreated);
        $this->line('  Updated: '.$result->citiesUpdated);
        if ($result->citiesSkipped > 0) {
            $this->line('  Skipped: '.$result->citiesSkipped);
        }
        $this->line('  Total: '.$citiesTotal);
        $this->line('  Active: '.$citiesActive);
        $this->line('  Inactive: '.$citiesInactive);
        $this->newLine();
        $this->line('Errors:');
        $this->line('  '.$result->errors);

        if ($result->errorMessages !== []) {
            $this->newLine();
            $this->warn('Error details:');
            foreach (array_slice($result->errorMessages, 0, 20) as $message) {
                $this->line(' - '.$this->redactSecrets($message));
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

        if ($courier === null && $courierCode !== $normalized) {
            $courier = Courier::query()
                ->whereRaw('UPPER(code) = ?', [$courierCode])
                ->first();
        }

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
        $normalized = strtoupper(trim($code));

        if ($normalized === 'TRANSEX') {
            return CourierLocationSyncService::CODE_TRANSEXPRESS;
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function resolveCredentials(string $providerCode): array
    {
        if ($providerCode === CourierLocationSyncService::CODE_TRANSEXPRESS) {
            return $this->resolveTransExpressCredentials();
        }

        if ($providerCode === CourierLocationSyncService::CODE_ROYAL) {
            return $this->resolveCurfoxCredentials();
        }

        throw new RuntimeException(
            'Location sync is not implemented for courier code: '.$providerCode
        );
    }

    /**
     * @return array{base_url: string}
     */
    private function resolveTransExpressCredentials(): array
    {
        $baseUrlOverride = trim((string) $this->option('base-url'));

        $credentials = [
            'base_url' => $baseUrlOverride !== ''
                ? $baseUrlOverride
                : trim((string) (
                    config('services.transexpress.base_url')
                    ?: config('feeder.transexpress.base_url')
                    ?: ''
                )),
        ];

        if ($credentials['base_url'] === '') {
            throw new RuntimeException(
                'TransExpress location sync base URL is incomplete.'
                .' Set TRANSEXPRESS_BASE_URL, or pass --base-url for this run.'
            );
        }

        return $credentials;
    }

    /**
     * @return array{base_url: string, token: string, tenant: string}
     */
    private function resolveCurfoxCredentials(): array
    {
        $baseUrlOverride = trim((string) $this->option('base-url'));
        $tenantOverride = trim((string) $this->option('tenant'));
        $tokenOverride = CurfoxApiClient::normalizeBearerToken((string) $this->option('token'));

        // Prefer explicit CLI overrides so a temporary --token works even when
        // CURFOX_TOKEN is unset. Fall back to configuration for unset options.
        $credentials = [
            'base_url' => $baseUrlOverride !== ''
                ? $baseUrlOverride
                : trim((string) (
                    config('services.curfox.base_url')
                    ?: config('feeder.curfox.base_url')
                    ?: ''
                )),
            'token' => $tokenOverride !== ''
                ? $tokenOverride
                : CurfoxApiClient::normalizeBearerToken((string) (
                    config('services.curfox.token')
                    ?: config('feeder.curfox.token')
                    ?: ''
                )),
            'tenant' => $tenantOverride !== ''
                ? $tenantOverride
                : trim((string) (
                    config('services.curfox.tenant')
                    ?: config('feeder.curfox.tenant')
                    ?: ''
                )),
        ];

        if (
            $credentials['base_url'] === ''
            || $credentials['token'] === ''
            || $credentials['tenant'] === ''
        ) {
            throw new RuntimeException(
                'Curfox location sync credentials are incomplete.'
                .' Set CURFOX_BASE_URL, CURFOX_TOKEN, and CURFOX_TENANT,'
                .' or pass --base-url / --tenant / --token for this run.'
            );
        }

        return $credentials;
    }

    private function formatThrowableDiagnostic(Throwable $e): string
    {
        $parts = [
            $this->redactSecrets($e->getMessage()),
            'exception='.$e::class,
        ];

        $previous = $e->getPrevious();
        if ($previous instanceof Throwable) {
            $parts[] = 'previous='.$previous::class.': '.$this->redactSecrets($previous->getMessage());
        }

        return implode(' | ', $parts);
    }

    private function redactSecrets(string $message): string
    {
        return (string) preg_replace('/Bearer\s+[^\s\'"]+/i', 'Bearer [REDACTED]', $message);
    }
}
