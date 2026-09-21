<?php

namespace Feeder\Core\Services\Courier\TransExpress;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * TransExpress client authentication (login / token acquisition).
 *
 * Never logs email/password/token values.
 */
final class TransExpressAuthService
{
    private const LOGIN_PATH = '/api/login/client';

    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly TransExpressConfig $config,
    ) {
    }

    /**
     * Authenticate against TransExpress and return the API token.
     *
     * @throws ValidationException When credentials are rejected by TransExpress
     * @throws RuntimeException When the provider is unreachable or returns an unexpected payload
     */
    public function login(string $email, string $password): string
    {
        $email = trim($email);
        $password = (string) $password;

        if ($email === '' || $password === '') {
            throw ValidationException::withMessages([
                'credentials' => ['TransExpress login email and password are required.'],
            ]);
        }

        $connection = $this->config->connection();

        try {
            $response = Http::baseUrl($connection['base_url'])
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::LOGIN_PATH, [
                    'email' => $email,
                    'password' => $password,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                'Unable to reach TransExpress login service.',
                0,
                $e
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'TransExpress login request failed.',
                0,
                $e
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw ValidationException::withMessages([
                'credentials' => ['TransExpress login failed. Check the email and password.'],
            ]);
        }

        if ($response->failed()) {
            $message = $this->safeProviderMessage($response->json('message'));

            throw ValidationException::withMessages([
                'credentials' => [
                    $message !== null
                        ? 'TransExpress login failed: '.$message
                        : 'TransExpress login failed. Check the email and password.',
                ],
            ]);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('TransExpress login returned a non-JSON response.');
        }

        $status = strtolower(trim((string) ($json['status'] ?? '')));

        if ($status !== '' && $status !== 'success') {
            throw ValidationException::withMessages([
                'credentials' => ['TransExpress login failed. Check the email and password.'],
            ]);
        }

        $token = $this->normalizeToken((string) ($json['token'] ?? ''));

        if ($token === '') {
            throw new RuntimeException('TransExpress login succeeded but did not return a token.');
        }

        return $token;
    }

    public static function normalizeToken(string $token): string
    {
        $token = trim($token);

        if ($token === '') {
            return '';
        }

        if (preg_match('/^Bearer\s+/i', $token) === 1) {
            $token = trim((string) preg_replace('/^Bearer\s+/i', '', $token));
        }

        return $token;
    }

    private function safeProviderMessage(mixed $message): ?string
    {
        if (! is_string($message)) {
            return null;
        }

        $trimmed = trim($message);

        if ($trimmed === '') {
            return null;
        }

        $lower = strtolower($trimmed);

        if (
            str_contains($lower, 'password')
            || str_contains($lower, 'token')
            || str_contains($lower, 'authorization')
            || str_contains($lower, 'bearer')
        ) {
            return null;
        }

        if (strlen($trimmed) > 200) {
            return substr($trimmed, 0, 200).'...';
        }

        return $trimmed;
    }
}
