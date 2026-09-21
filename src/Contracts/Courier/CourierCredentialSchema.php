<?php

namespace Feeder\Core\Contracts\Courier;

use Feeder\Core\Services\Courier\CredentialSchema\CourierCredentialField;

interface CourierCredentialSchema
{
    public function courierCode(): string;

    /**
     * @return list<CourierCredentialField>
     */
    public function fields(): array;

    /**
     * Validate and normalize credential input.
     *
     * When $partial is true (edit), secret fields may be omitted/blank.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function validate(array $credentials, bool $partial = false): array;

    /**
     * Safe display rows for Admin UI. Never includes raw secret values.
     *
     * @param  array<string, mixed>  $credentials
     * @return list<array{key: string, label: string, display: string}>
     */
    public function safeDisplay(array $credentials): array;

    /**
     * Short masked summary for list tables, or null when nothing safe to show.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function maskSummary(array $credentials): ?string;
}
