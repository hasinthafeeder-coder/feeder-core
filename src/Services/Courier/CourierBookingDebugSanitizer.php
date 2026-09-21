<?php

namespace Feeder\Core\Services\Courier;

/**
 * Removes credentials from courier booking debug payloads.
 *
 * Field names that identify an API mismatch (city, district, amount, reference)
 * are kept. Secret values and authentication material are not.
 */
final class CourierBookingDebugSanitizer
{
    private const MAX_TEXT_CHARS = 100000;

    /**
     * @var list<string>
     */
    private const BLOCKED_KEYS = [
        'api_key',
        'apikey',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'password',
        'passwd',
        'secret',
        'client_secret',
        'merchant_secret',
        'credentials',
        'authorization',
        'auth',
        'bearer',
        'cookie',
        'set-cookie',
        'set_cookie',
    ];

    /**
     * @param  list<string>  $secrets
     */
    public function sanitize(mixed $value, array $secrets = []): mixed
    {
        $secrets = $this->usableSecrets($secrets);

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                if ($this->isBlockedKey($key)) {
                    continue;
                }

                $clean[$key] = $this->sanitize($item, $secrets);
            }

            return $clean;
        }

        if (is_string($value)) {
            return $this->redactString($value, $secrets);
        }

        return $value;
    }

    /**
     * @param  list<string>  $secrets
     */
    public function redactString(string $value, array $secrets = []): string
    {
        $secrets = $this->usableSecrets($secrets);
        $value = preg_replace('/Bearer\s+[^\s\'"]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/(api[_-]?key|token|password|secret|authorization)\s*[:=]\s*\S+/i', '$1=[REDACTED]', $value) ?? $value;

        foreach ($secrets as $secret) {
            $value = str_replace($secret, '[REDACTED]', $value);
        }

        return $value;
    }

    public function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        $length = strlen($digits);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        $hidden = $length - 6;

        return substr($digits, 0, 3).str_repeat('*', $hidden).substr($digits, -3);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    public function limitText(string $value): array
    {
        if (strlen($value) <= self::MAX_TEXT_CHARS) {
            return [$value, false];
        }

        return [substr($value, 0, self::MAX_TEXT_CHARS), true];
    }

    private function isBlockedKey(mixed $key): bool
    {
        return in_array(strtolower((string) $key), self::BLOCKED_KEYS, true);
    }

    /**
     * @param  list<string>  $secrets
     * @return list<string>
     */
    private function usableSecrets(array $secrets): array
    {
        $usable = [];

        foreach ($secrets as $secret) {
            $secret = trim($secret);

            if (strlen($secret) < 8) {
                continue;
            }

            $usable[] = $secret;
        }

        return array_values(array_unique($usable));
    }
}
