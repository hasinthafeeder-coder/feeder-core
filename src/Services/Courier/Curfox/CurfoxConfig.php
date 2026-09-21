<?php

namespace Feeder\Core\Services\Courier\Curfox;

use RuntimeException;

/**
 * Resolves global Curfox (ROYAL) connection settings from application config.
 *
 * These values are environment-level only — never per supplier account.
 */
final class CurfoxConfig
{
    /**
     * @return array{base_url: string, tenant: string}
     */
    public function connection(): array
    {
        $baseUrl = rtrim((string) (
            config('services.curfox.base_url')
            ?? config('feeder.curfox.base_url')
            ?? ''
        ), '/');

        $tenant = trim((string) (
            config('services.curfox.tenant')
            ?? config('feeder.curfox.tenant')
            ?? ''
        ));

        if ($baseUrl === '' || $tenant === '') {
            throw new RuntimeException('Curfox global configuration is incomplete.');
        }

        return [
            'base_url' => $baseUrl,
            'tenant' => $tenant,
        ];
    }
}

