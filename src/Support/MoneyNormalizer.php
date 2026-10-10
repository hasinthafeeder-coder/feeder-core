<?php

namespace Feeder\Core\Support;

use Illuminate\Validation\ValidationException;

/**
 * Shared non-negative monetary normalization (2 decimal places).
 */
class MoneyNormalizer
{
    public function normalizeNonNegative(mixed $amount, string $field): string
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                $field => ['A non-negative amount is required.'],
            ]);
        }

        $string = trim((string) $amount);

        if ($string === '' || ! is_numeric($string)) {
            throw ValidationException::withMessages([
                $field => ['Must be a valid numeric amount.'],
            ]);
        }

        if (str_contains($string, 'e') || str_contains($string, 'E')) {
            throw ValidationException::withMessages([
                $field => ['Scientific notation is not allowed.'],
            ]);
        }

        $value = (float) $string;

        if ($value < 0) {
            throw ValidationException::withMessages([
                $field => ['Amount cannot be negative.'],
            ]);
        }

        if ($value > 100000000000) {
            throw ValidationException::withMessages([
                $field => ['Amount exceeds the supported maximum.'],
            ]);
        }

        return number_format($value, 2, '.', '');
    }

    public function tryNormalizeNonNegative(mixed $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        $string = trim((string) $amount);
        if ($string === '') {
            return null;
        }

        if (! is_numeric($string) || str_contains($string, 'e') || str_contains($string, 'E')) {
            return null;
        }

        $value = (float) $string;
        if ($value < 0 || $value > 100000000000) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    public function subtract(string $minuend, string $subtrahend): string
    {
        return number_format(((float) $minuend) - ((float) $subtrahend), 2, '.', '');
    }

    public function add(?string $left, ?string $right): string
    {
        return number_format(((float) ($left ?? '0.00')) + ((float) ($right ?? '0.00')), 2, '.', '');
    }

    public function equals(string $left, string $right): bool
    {
        return number_format((float) $left, 2, '.', '') === number_format((float) $right, 2, '.', '');
    }
}
