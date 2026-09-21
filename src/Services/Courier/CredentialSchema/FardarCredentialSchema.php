<?php

namespace Feeder\Core\Services\Courier\CredentialSchema;

/**
 * Credential schema for Fardar Express Domestic.
 *
 * Global FARDAR_BASE_URL comes from application config.
 * Supplier accounts store only client_id / api_key (encrypted at rest).
 * There is no login/token exchange — credentials are sent on each form request.
 */
final class FardarCredentialSchema extends AbstractCourierCredentialSchema
{
    public function courierCode(): string
    {
        return 'FARDAR';
    }

    public function fields(): array
    {
        return [
            new CourierCredentialField(
                key: 'client_id',
                label: 'Client ID',
                inputType: 'text',
                secret: false,
                required: true,
                maskable: true,
                maskVisibleChars: 4,
                helpText: 'Supplier-specific Fardar Domestic client ID.',
            ),
            new CourierCredentialField(
                key: 'api_key',
                label: 'API Key',
                inputType: 'password',
                secret: true,
                required: true,
                helpText: 'Supplier-specific Fardar Domestic API key. Leave blank when editing to keep the existing value.',
            ),
        ];
    }

    public function maskSummary(array $credentials): ?string
    {
        $clientId = $credentials['client_id'] ?? null;

        if (is_string($clientId) && trim($clientId) !== '') {
            return $this->maskValue(trim($clientId), 4);
        }

        return parent::maskSummary($credentials);
    }
}
