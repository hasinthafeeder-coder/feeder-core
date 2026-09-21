<?php

namespace Feeder\Core\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cross-portal encrypter for supplier courier credentials at rest.
 *
 * Host apps each have their own APP_KEY (sessions/cookies). Shared DB ciphertext
 * must use one dedicated key so admin, supplier, and reseller can all decrypt.
 */
final class CredentialsEncrypter
{
    private ?Encrypter $encrypter = null;

    public function encryptString(string $value): string
    {
        return $this->encrypter()->encryptString($value);
    }

    public function decryptString(string $payload): string
    {
        return $this->encrypter()->decryptString($payload);
    }

    private function encrypter(): Encrypter
    {
        if ($this->encrypter !== null) {
            return $this->encrypter;
        }

        $primaryKey = $this->configuredPrimaryKey();
        $cipher = (string) config('app.cipher', 'AES-256-CBC');

        $encrypter = new Encrypter($this->parseKey($primaryKey), $cipher);

        $previous = [];
        $appKey = config('app.key');

        if (is_string($appKey) && $appKey !== '' && $appKey !== $primaryKey) {
            $previous[] = $this->parseKey($appKey);
        }

        foreach ((array) config('feeder.credentials_encryption_previous_keys', []) as $previousKey) {
            if (! is_string($previousKey) || $previousKey === '' || $previousKey === $primaryKey) {
                continue;
            }

            $previous[] = $this->parseKey($previousKey);
        }

        if ($previous !== []) {
            $encrypter->previousKeys($previous);
        }

        return $this->encrypter = $encrypter;
    }

    private function configuredPrimaryKey(): string
    {
        $key = config('feeder.credentials_encryption_key');

        if (! is_string($key) || $key === '') {
            $key = config('app.key');
        }

        if (! is_string($key) || $key === '') {
            throw new RuntimeException(
                'No encryption key configured for courier credentials. Set FEEDER_CREDENTIALS_KEY (preferred) or APP_KEY.'
            );
        }

        return $key;
    }

    private function parseKey(string $key): string
    {
        if (Str::startsWith($key, $prefix = 'base64:')) {
            $decoded = base64_decode(Str::after($key, $prefix), true);

            if ($decoded === false) {
                throw new RuntimeException('Courier credentials encryption key is not valid base64.');
            }

            return $decoded;
        }

        return $key;
    }
}
