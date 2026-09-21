<?php

namespace Feeder\Core\Services\Courier\TransExpress;

use Feeder\Core\Exceptions\CourierProviderBookingException;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Services\Courier\CourierBookingDebugSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Authenticated TransExpress merchant API client with single-retry 401 refresh.
 *
 * Confirmed auth format: Authorization: Bearer {token}
 * Supplier credentials stay scoped to the provided SupplierCourierAccount.
 * Never logs Authorization headers or credential values.
 */
final class TransExpressMerchantClient
{
    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly TransExpressConfig $config,
        private readonly TransExpressAuthService $authService,
        private readonly CourierBookingDebugSanitizer $debugSanitizer,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function post(SupplierCourierAccount $account, string $path, array $payload): array
    {
        $connection = $this->config->connection();
        $credentials = $account->getCredentials();
        $secrets = $this->secretsFrom($credentials);
        $token = TransExpressAuthService::normalizeToken((string) ($credentials['token'] ?? ''));

        if ($token === '') {
            $token = $this->refreshAccountToken($account, $credentials, $secrets);
            $secrets = $this->secretsFrom($account->getCredentials());
        }

        $response = $this->sendPost($connection, $token, $path, $payload, $secrets);

        if ($response->status() === 401) {
            $token = $this->refreshAccountToken($account, $account->getCredentials(), $secrets);
            $secrets = $this->secretsFrom($account->getCredentials());
            $response = $this->sendPost($connection, $token, $path, $payload, $secrets);

            if ($response->status() === 401) {
                throw $this->httpFailure(
                    $response,
                    $secrets,
                    'TransExpress authentication failed after token refresh. Re-test the supplier courier account.'
                );
            }
        }

        return $this->decodeSuccessfulResponse($response, $secrets);
    }

    /**
     * @param  array{base_url: string}  $connection
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $secrets
     */
    private function sendPost(array $connection, string $token, string $path, array $payload, array $secrets): Response
    {
        try {
            return Http::baseUrl($connection['base_url'])
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($path, $payload);
        } catch (ConnectionException $e) {
            throw $this->connectionFailure($e, $secrets);
        } catch (Throwable $e) {
            throw $this->connectionFailure($e, $secrets);
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $secrets
     */
    private function refreshAccountToken(SupplierCourierAccount $account, array $credentials, array $secrets): string
    {
        $email = trim((string) ($credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ($email === '' || $password === '') {
            throw ValidationException::withMessages([
                'booking' => ['TransExpress account credentials are incomplete. Reconfigure the supplier courier account.'],
            ]);
        }

        try {
            $token = $this->authService->login($email, $password);
        } catch (CourierProviderBookingException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw CourierProviderBookingException::authentication(
                'TRANSEXPRESS',
                'TransExpress re-authentication failed. Re-test the supplier courier account.',
            );
        } catch (Throwable $e) {
            throw $this->connectionFailure($e, $secrets);
        }

        $credentials['token'] = $token;
        $account->setCredentials($credentials);
        $account->save();

        return $token;
    }

    /**
     * @param  list<string>  $secrets
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function decodeSuccessfulResponse(Response $response, array $secrets): array
    {
        if ($response->failed()) {
            throw $this->httpFailure($response, $secrets, null);
        }

        $json = $response->json();

        if (! is_array($json)) {
            [$raw, $truncated] = $this->rawBody($response, $secrets);

            throw CourierProviderBookingException::invalidJson(
                'TRANSEXPRESS',
                $response->status(),
                $raw,
                null,
                $truncated,
            );
        }

        return [
            'http_status' => $response->status(),
            'body' => $json,
        ];
    }

    /**
     * @param  list<string>  $secrets
     */
    private function httpFailure(Response $response, array $secrets, ?string $summary): CourierProviderBookingException
    {
        $decoded = $this->responseBody($response, $secrets);
        $providerMessage = is_array($decoded['body'])
            ? $this->safeProviderMessage(
                $decoded['body']['message']
                    ?? $decoded['body']['error']
                    ?? $decoded['body']['success']
                    ?? null
            )
            : null;

        $summary ??= $providerMessage !== null
            ? 'Booking failed: '.$providerMessage
            : 'Booking failed: TransExpress rejected the order request.';

        return CourierProviderBookingException::httpFailure(
            'TRANSEXPRESS',
            $response->status(),
            $decoded['body'],
            $summary,
            null,
            $decoded['truncated'],
        );
    }

    /**
     * @param  list<string>  $secrets
     */
    private function connectionFailure(Throwable $e, array $secrets): CourierProviderBookingException
    {
        $safeMessage = $this->debugSanitizer->redactString($e->getMessage(), $secrets);
        $category = $this->classifyConnectionMessage($safeMessage);

        $summary = match ($category) {
            'timeout' => 'Connection timeout',
            'dns_host' => 'DNS error',
            'ssl_certificate' => 'SSL error',
            default => 'Unable to reach TransExpress.',
        };

        $diagnostic = match ($category) {
            'timeout' => 'Connection timeout',
            'dns_host' => 'DNS error',
            'ssl_certificate' => 'SSL error',
            default => $safeMessage !== '' ? $safeMessage : $summary,
        };

        return CourierProviderBookingException::connection(
            'TRANSEXPRESS',
            $summary,
            $e::class,
            $diagnostic,
        );
    }

    /**
     * @param  list<string>  $secrets
     * @return array{body: array<string, mixed>|string, truncated: bool}
     */
    private function responseBody(Response $response, array $secrets): array
    {
        $json = $response->json();

        if (is_array($json)) {
            $sanitized = $this->debugSanitizer->sanitize($json, $secrets);

            return [
                'body' => is_array($sanitized) ? $sanitized : [],
                'truncated' => false,
            ];
        }

        [$raw, $truncated] = $this->rawBody($response, $secrets);

        return [
            'body' => $raw,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  list<string>  $secrets
     * @return array{0: string, 1: bool}
     */
    private function rawBody(Response $response, array $secrets): array
    {
        $raw = trim((string) $response->body());

        if ($raw === '') {
            return ['(empty body)', false];
        }

        $raw = $this->debugSanitizer->redactString($raw, $secrets);

        return $this->debugSanitizer->limitText($raw);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return list<string>
     */
    private function secretsFrom(array $credentials): array
    {
        return [
            (string) ($credentials['password'] ?? ''),
            (string) ($credentials['token'] ?? ''),
            TransExpressAuthService::normalizeToken((string) ($credentials['token'] ?? '')),
        ];
    }

    private function classifyConnectionMessage(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'curl error 60')) {
            return 'ssl_certificate';
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'curl error 28')) {
            return 'timeout';
        }

        if (
            str_contains($lower, 'could not resolve host')
            || str_contains($lower, 'name or service not known')
            || str_contains($lower, 'curl error 6')
            || str_contains($lower, 'getaddrinfo')
        ) {
            return 'dns_host';
        }

        return 'connection';
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
            || str_contains($lower, 'api_key')
        ) {
            return 'the courier provider rejected the request';
        }

        if (strlen($trimmed) > 240) {
            return substr($trimmed, 0, 240).'...';
        }

        return $trimmed;
    }
}
