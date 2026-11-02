<?php

namespace Feeder\Core\Support;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Base-10 money amounts at a fixed scale.
 *
 * Callers pass decimal strings. Floats are rejected so a value is never
 * rounded through binary floating point.
 */
final class DecimalAmount
{
    public const SCALE = 2;

    public const MAX = '100000000000.00';

    public static function normalizePositive(mixed $amount, string $field): string
    {
        self::assertBcmath();

        if (is_float($amount)) {
            throw ValidationException::withMessages([
                $field => ['Amount must be a decimal string, not a floating-point number.'],
            ]);
        }

        if (is_int($amount)) {
            $amount = (string) $amount;
        }

        if (! is_string($amount)) {
            throw ValidationException::withMessages([
                $field => ['A positive amount is required.'],
            ]);
        }

        $string = trim($amount);

        if ($string === '' || preg_match('/[eE]/', $string) === 1 || preg_match('/^\d{1,13}(\.\d{1,2})?$/', $string) !== 1) {
            throw ValidationException::withMessages([
                $field => ['Amount must be a positive number with up to 2 decimal places.'],
            ]);
        }

        $normalized = bcadd($string, '0', self::SCALE);

        if (bccomp($normalized, '0.00', self::SCALE) !== 1) {
            throw ValidationException::withMessages([
                $field => ['Amount must be greater than zero.'],
            ]);
        }

        if (bccomp($normalized, self::MAX, self::SCALE) === 1) {
            throw ValidationException::withMessages([
                $field => ['Amount exceeds the supported maximum.'],
            ]);
        }

        return $normalized;
    }

    public static function fromStored(mixed $amount): string
    {
        self::assertBcmath();

        if (is_int($amount)) {
            $amount = (string) $amount;
        }

        if (! is_string($amount) || preg_match('/^\d+(\.\d+)?$/', trim($amount)) !== 1) {
            throw new RuntimeException('A stored expense amount must be a decimal string.');
        }

        return bcadd(trim($amount), '0', self::SCALE);
    }

    public static function add(string $left, string $right): string
    {
        self::assertBcmath();

        return bcadd(self::fromStored($left), self::fromStored($right), self::SCALE);
    }

    public static function format(string $amount): string
    {
        $normalized = self::fromStored($amount);
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');
        $wholeWithSeparators = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return ($wholeWithSeparators ?? $whole).'.'.$fraction;
    }

    /**
     * Display a signed 2-decimal total. The sign is kept; the absolute value
     * still goes through the same scale rules as format().
     */
    public static function formatSigned(string $amount): string
    {
        self::assertBcmath();

        $trimmed = trim($amount);
        $negative = str_starts_with($trimmed, '-');
        $absolute = $negative ? substr($trimmed, 1) : $trimmed;
        $formatted = self::format($absolute);

        if (! $negative || bccomp($absolute, '0', self::SCALE) === 0) {
            return $formatted;
        }

        return '-'.$formatted;
    }

    private static function assertBcmath(): void
    {
        if (! function_exists('bcadd') || ! function_exists('bccomp')) {
            throw new RuntimeException('The bcmath extension is required for expense amounts.');
        }
    }
}
