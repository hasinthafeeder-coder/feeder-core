<?php

namespace Feeder\Core\Services\Courier\CredentialSchema;

/**
 * Credential schema for TransExpress.
 *
 * Global TRANSEXPRESS_BASE_URL comes from application config.
 * Supplier accounts store only email / password / token (encrypted).
 */
final class TransExpressCredentialSchema extends AbstractCourierCredentialSchema
{
    public function courierCode(): string
    {
        return 'TRANSEXPRESS';
    }

    public function fields(): array
    {
        return [
            new CourierCredentialField(
                key: 'email',
                label: 'TransExpress Login Email',
                inputType: 'email',
                secret: false,
                required: true,
                maskable: true,
                maskVisibleChars: 4,
                helpText: 'Supplier-specific TransExpress client login email.',
            ),
            new CourierCredentialField(
                key: 'password',
                label: 'TransExpress Password',
                inputType: 'password',
                secret: true,
                required: true,
                helpText: 'Supplier-specific TransExpress password. Leave blank when editing to keep the existing value.',
            ),
        ];
    }

    public function safeDisplay(array $credentials): array
    {
        $rows = parent::safeDisplay($credentials);

        $token = $credentials['token'] ?? null;
        $hasToken = is_string($token) ? trim($token) !== '' : $token !== null && $token !== '';

        $rows[] = [
            'key' => 'token',
            'label' => 'API Token',
            'display' => $hasToken ? 'Configured' : 'Not configured',
        ];

        return $rows;
    }

    public function maskSummary(array $credentials): ?string
    {
        $email = $credentials['email'] ?? null;

        if (is_string($email) && trim($email) !== '') {
            return $this->maskValue(trim($email), 4);
        }

        return parent::maskSummary($credentials);
    }
}
