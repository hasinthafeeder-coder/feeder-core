<?php

namespace Feeder\Core\Services\Courier\CredentialSchema;

use Feeder\Core\Contracts\Courier\CourierCredentialSchema;
use Illuminate\Validation\ValidationException;

/**
 * Lightweight base for provider credential schemas.
 *
 * Concrete providers define fields(); this class handles validation,
 * secret classification, and safe display without a dynamic form builder.
 */
abstract class AbstractCourierCredentialSchema implements CourierCredentialSchema
{
    abstract public function courierCode(): string;

    /**
     * @return list<CourierCredentialField>
     */
    abstract public function fields(): array;

    public function validate(array $credentials, bool $partial = false): array
    {
        $normalized = [];
        $errors = [];

        foreach ($this->fields() as $field) {
            $present = array_key_exists($field->key, $credentials);
            $raw = $present ? $credentials[$field->key] : null;
            $value = is_string($raw) ? trim($raw) : $raw;
            $isEmpty = $value === null || $value === '';

            if ($isEmpty) {
                if ($partial && $field->secret) {
                    continue;
                }

                if ($field->required && ! $partial) {
                    $errors[$field->key] = ["{$field->label} is required."];

                    continue;
                }

                if ($partial && ! $field->secret && $present) {
                    $normalized[$field->key] = '';
                }

                continue;
            }

            if (! is_scalar($value)) {
                $errors[$field->key] = ["{$field->label} must be a string value."];

                continue;
            }

            $normalized[$field->key] = (string) $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    public function safeDisplay(array $credentials): array
    {
        $rows = [];

        foreach ($this->fields() as $field) {
            $value = $credentials[$field->key] ?? null;
            $hasValue = is_string($value) ? trim($value) !== '' : $value !== null && $value !== '';

            if ($field->secret) {
                $rows[] = [
                    'key' => $field->key,
                    'label' => $field->label,
                    'display' => $hasValue ? 'Configured' : 'Not configured',
                ];

                continue;
            }

            if (! $hasValue) {
                $rows[] = [
                    'key' => $field->key,
                    'label' => $field->label,
                    'display' => '—',
                ];

                continue;
            }

            $stringValue = (string) $value;

            $rows[] = [
                'key' => $field->key,
                'label' => $field->label,
                'display' => $field->maskable
                    ? $this->maskValue($stringValue, $field->maskVisibleChars)
                    : $stringValue,
            ];
        }

        return $rows;
    }

    public function maskSummary(array $credentials): ?string
    {
        foreach ($this->fields() as $field) {
            if ($field->secret || ! $field->maskable) {
                continue;
            }

            $value = $credentials[$field->key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            return $this->maskValue($value, $field->maskVisibleChars);
        }

        foreach ($this->fields() as $field) {
            if (! $field->secret) {
                continue;
            }

            $value = $credentials[$field->key] ?? null;

            if (is_string($value) ? trim($value) !== '' : $value !== null && $value !== '') {
                return 'Credentials configured';
            }
        }

        return null;
    }

    protected function maskValue(string $value, int $visibleChars): string
    {
        $value = trim($value);
        $length = mb_strlen($value);

        if ($length === 0) {
            return '—';
        }

        if ($visibleChars <= 0 || $length <= $visibleChars) {
            return str_repeat('•', min($length, 8));
        }

        return str_repeat('•', max(4, $length - $visibleChars)).mb_substr($value, -$visibleChars);
    }
}
