<?php

namespace Feeder\Core\Services\Courier\CredentialSchema;

/**
 * Fallback credential schema for couriers without a provider-specific definition.
 *
 * Real Courier #1–#4 schemas will replace this per courier code when integrated.
 */
final class GenericCourierCredentialSchema extends AbstractCourierCredentialSchema
{
    public function __construct(
        private readonly string $code = 'GENERIC',
    ) {
    }

    public function courierCode(): string
    {
        return strtoupper(trim($this->code));
    }

    public function fields(): array
    {
        return [
            new CourierCredentialField(
                key: 'account_reference',
                label: 'Account Reference',
                inputType: 'text',
                secret: false,
                required: false,
                maskable: true,
                maskVisibleChars: 4,
                helpText: 'Optional non-secret business/account identifier used for Admin display.',
            ),
            new CourierCredentialField(
                key: 'api_key',
                label: 'API Key',
                inputType: 'password',
                secret: true,
                required: true,
                helpText: 'Provider API credential. Leave blank when editing to keep the existing value.',
            ),
        ];
    }
}
