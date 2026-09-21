<?php

namespace Feeder\Core\Services\Courier\Fardar;

use Feeder\Core\Exceptions\CourierProviderBookingException;
use Feeder\Core\Services\Courier\CourierBookingDebugSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fardar Domestic HTTP client.
 *
 * Provider requires application/x-www-form-urlencoded POST bodies.
 * Authentication is client_id + api_key in the form payload — no Bearer token.
 * Never logs api_key or Authorization material.
 */
final class FardarApiClient
{
    public const CREATE_PARCEL_PATH = '/api/parcel/new_api_v1.php';

    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly FardarConfig $config,
        private readonly CourierBookingDebugSanitizer $debugSanitizer,
    ) {
    }

    /**
     * @param  array<string, scalar|null>  $form
     * @param  list<string>  $secrets
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function createParcel(array $form, array $secrets): array
    {
        $connection = $this->config->connection();
        $response = $this->sendFormPost($connection['base_url'], self::CREATE_PARCEL_PATH, $form, $secrets);

        return $this->decodeResponse($response, $secrets);
    }

    /**
     * @param  array<string, scalar|null>  $form
     * @param  list<string>  $secrets
     */
    private function sendFormPost(string $baseUrl, string $path, array $form, array $secrets): Response
    {
        try {
            return Http::baseUrl($baseUrl)
                ->asForm()
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($path, $form);
        } catch (ConnectionException $e) {
            throw $this->connectionFailure($e, $secrets);
        } catch (Throwable $e) {
            throw $this->connectionFailure($e, $secrets);
        }
    }

    /**
     * @param  list<string>  $secrets
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function decodeResponse(Response $response, array $secrets): array
    {
        $json = $response->json();

        // Fardar returns business outcomes in the JSON body (status field).
        // Decode JSON first so HTTP 200 + provider status != 200 is handled by the adapter.
        if (is_array($json)) {
            if ($response->failed() && ! array_key_exists('status', $json)) {
                throw $this->httpFailure($response, $secrets);
            }

            return [
                'http_status' => $response->status(),
                'body' => $json,
            ];
        }

        if ($response->failed()) {
            throw $this->httpFailure($response, $secrets);
        }

        [$raw, $truncated] = $this->rawBody($response, $secrets);

        throw CourierProviderBookingException::invalidJson(
            'FARDAR',
            $response->status(),
            $raw,
            null,
            $truncated,
        );
    }

    /**
     * @param  list<string>  $secrets
     */
    private function httpFailure(Response $response, array $secrets): CourierProviderBookingException
    {
        $decoded = $this->responseBody($response, $secrets);

        return CourierProviderBookingException::httpFailure(
            'FARDAR',
            $response->status(),
            $decoded['body'],
            'Booking failed: Fardar rejected the parcel request.',
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
            default => 'Unable to reach Fardar Domestic.',
        };

        $diagnostic = match ($category) {
            'timeout' => 'Connection timeout',
            'dns_host' => 'DNS error',
            'ssl_certificate' => 'SSL error',
            default => $safeMessage !== '' ? $safeMessage : $summary,
        };

        return CourierProviderBookingException::connection(
            'FARDAR',
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
}
