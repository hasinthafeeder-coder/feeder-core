<?php

namespace Feeder\Core\Services\Courier\Fardar;

use RuntimeException;

/**
 * Resolves global Fardar Domestic connection settings from application config.
 *
 * These values are environment-level only — never per supplier account.
 * Supplier Client ID / API Key live in supplier_courier_accounts.
 */
final class FardarConfig
{
    /**
     * @return array{base_url: string}
     */
    public function connection(): array
    {
        $baseUrl = rtrim((string) (
            config('services.fardar.base_url')
            ?? config('feeder.fardar.base_url')
            ?? ''
        ), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('Fardar Domestic global configuration is incomplete.');
        }

        return [
            'base_url' => $baseUrl,
        ];
    }
}
