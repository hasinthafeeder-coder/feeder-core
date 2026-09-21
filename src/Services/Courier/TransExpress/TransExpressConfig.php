<?php

namespace Feeder\Core\Services\Courier\TransExpress;

use RuntimeException;

/**
 * Resolves global TransExpress connection settings from application config.
 *
 * These values are environment-level only — never per supplier account.
 */
final class TransExpressConfig
{
    /**
     * @return array{base_url: string}
     */
    public function connection(): array
    {
        $baseUrl = rtrim((string) (
            config('services.transexpress.base_url')
            ?? config('feeder.transexpress.base_url')
            ?? ''
        ), '/');

        // Host apps may configure .../api or the portal origin; normalize to origin.
        if (str_ends_with($baseUrl, '/api')) {
            $baseUrl = substr($baseUrl, 0, -4);
        }

        if ($baseUrl === '') {
            throw new RuntimeException('TransExpress global configuration is incomplete.');
        }

        return [
            'base_url' => $baseUrl,
        ];
    }
}
