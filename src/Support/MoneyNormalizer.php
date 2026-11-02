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
        return $this->tryNormalizeAmount($amount, false);
    }

    /**
     * Two-decimal normalization. Blank input returns null.
     * Negative values are accepted only when $allowNegative is true.
     */
    public function tryNormalizeAmount(mixed $amount, bool $allowNegative = false): ?string
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
        if ((! $allowNegative && $value < 0) || abs($value) > 100000000000) {
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

    /**
     * Decimal-safe multiplication for payable lines (unit cost × quantity).
     */
    public function multiply(string $amount, int|string $multiplier): string
    {
        $normalizedAmount = $this->normalizeNonNegative($amount, 'amount');
        $factor = trim((string) $multiplier);

        if ($factor === '' || ! is_numeric($factor) || str_contains($factor, 'e') || str_contains($factor, 'E')) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be a valid non-negative number.'],
            ]);
        }

        if ((float) $factor < 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity cannot be negative.'],
            ]);
        }

        if (function_exists('bcmul')) {
            return bcmul($normalizedAmount, $factor, 2);
        }

        return number_format((float) $normalizedAmount * (float) $factor, 2, '.', '');
    }

    public function isPositive(string $amount): bool
    {
        $normalized = number_format((float) $amount, 2, '.', '');

        if (function_exists('bccomp')) {
            return bccomp($normalized, '0.00', 2) === 1;
        }

        return (float) $normalized > 0;
    }
}
