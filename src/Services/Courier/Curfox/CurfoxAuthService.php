<?php

namespace Feeder\Core\Services\Courier\Curfox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Curfox merchant authentication (login / token acquisition).
 *
 * Never logs email/password/token values.
 */
final class CurfoxAuthService
{
    private const LOGIN_PATH = '/api/public/merchant/login';

    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly CurfoxConfig $config,
    ) {
    }

    /**
     * Authenticate against Curfox and return the bearer token.
     *
     * @throws ValidationException When credentials are rejected by Curfox
     * @throws RuntimeException When the provider is unreachable or returns an unexpected payload
     */
    public function login(string $email, string $password): string
    {
        $email = trim($email);
        $password = (string) $password;

        if ($email === '' || $password === '') {
            throw ValidationException::withMessages([
                'credentials' => ['ROYAL login email and password are required.'],
            ]);
        }

        $connection = $this->config->connection();
        $url = $connection['base_url'].self::LOGIN_PATH;

        try {
            $response = Http::baseUrl($connection['base_url'])
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-tenant' => $connection['tenant'],
                ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::LOGIN_PATH, [
                    'email' => $email,
                    'password' => $password,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                'Unable to reach Curfox login service.',
                0,
                $e
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Curfox login request failed.',
                0,
                $e
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw ValidationException::withMessages([
                'credentials' => ['ROYAL login failed. Check the email and password.'],
            ]);
        }

        if ($response->failed()) {
            $message = $this->safeProviderMessage($response->json('message'));

            throw ValidationException::withMessages([
                'credentials' => [
                    $message !== null
                        ? 'ROYAL login failed: '.$message
                        : 'ROYAL login failed. Check the email and password.',
                ],
            ]);
        }

        $token = CurfoxApiClient::normalizeBearerToken((string) ($response->json('token') ?? ''));

        if ($token === '') {
            throw new RuntimeException('Curfox login succeeded but did not return a token.');
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

