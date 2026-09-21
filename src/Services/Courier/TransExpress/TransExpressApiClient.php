<?php

namespace Feeder\Core\Services\Courier\TransExpress;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * HTTP client for TransExpress public location APIs.
 *
 * Responsible only for HTTP communication — no database logic.
 * Location endpoints require no authentication.
 */
final class TransExpressApiClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 60;

    private const MAX_RETRIES = 2;

    private const PROVINCES_PATH = '/provinces';

    private const DISTRICTS_PATH = '/districts';

    private const CITIES_PATH = '/cities';

    private const MAX_SAFE_BODY_CHARS = 500;

    /**
     * @param  array{base_url: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getProvinces(): array
    {
        return $this->getList(self::PROVINCES_PATH);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDistricts(string $provinceId): array
    {
        return $this->getList(self::DISTRICTS_PATH, [
            'province_id' => $provinceId,
        ]);
    }

    /**
     * Cities for a district.
     *
     * The unfiltered GET /cities response is a flat global list without district_id.
     * District relationship is obtained only via GET /cities?district_id={id}.
     *
     * @return list<array<string, mixed>>
     */
    public function getCitiesByDistrict(string $districtId): array
    {
        return $this->getList(self::CITIES_PATH, [
            'district_id' => $districtId,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    private function getList(string $path, array $query = []): array
    {
        $payload = $this->get($path, $query);

        return $this->extractList($payload, $path, $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<mixed>|array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $baseUrl = rtrim((string) ($this->credentials['base_url'] ?? ''), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('TransExpress location sync base URL is incomplete.');
        }

        $url = $this->absoluteUrl($path, $query);

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->asJson()
                ->timeout(self::DEFAULT_TIMEOUT_SECONDS)
                ->retry(self::MAX_RETRIES, 250, function ($exception): bool {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw $this->connectionFailure($url, $e);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf(
                    'TransExpress API request failed during connection [phase=connection] GET %s: %s (%s)',
                    $url,
                    $this->sanitizeMessage($e->getMessage()),
                    $e::class
                ),
                0,
                $e
            );
        }

        if ($response->failed()) {
            throw $this->httpFailure($url, $response);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException(
                sprintf(
                    'TransExpress API returned an invalid JSON payload [phase=payload] GET %s HTTP %d body=%s',
                    $url,
                    $response->status(),
                    $this->safeResponseBody($response)
                )
            );
        }

        return $json;
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    private function extractList(array $payload, string $path, array $query = []): array
    {
        // TransExpress returns a raw JSON array. Also tolerate a Curfox-style {data: []} envelope.
        $rows = $payload;

        if (array_key_exists('data', $payload) && is_array($payload['data'])) {
            $rows = $payload['data'];
        }

        if ($rows !== [] && ! array_is_list($rows)) {
            throw new RuntimeException(
                sprintf(
                    'TransExpress API response must be a JSON list [phase=payload] GET %s',
                    $this->absoluteUrl($path, $query)
                )
            );
        }

        $list = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }

        return $list;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function absoluteUrl(string $path, array $query = []): string
    {
        $baseUrl = rtrim((string) ($this->credentials['base_url'] ?? ''), '/');
        $url = $baseUrl.$path;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $url;
    }

    private function connectionFailure(string $url, ConnectionException $e): RuntimeException
    {
        $message = $this->sanitizeMessage($e->getMessage());
        $category = $this->classifyConnectionMessage($message);

        return new RuntimeException(
            sprintf(
                'TransExpress API connection failed [phase=connection category=%s] GET %s: %s (%s)',
                $category,
                $url,
                $message,
                $e::class
            ),
            0,
            $e
        );
    }

    private function httpFailure(string $url, Response $response): RuntimeException
    {
        $status = $response->status();
        $phase = in_array($status, [401, 403], true) ? 'authentication' : 'http_response';
        $category = $this->classifyHttpStatus($status);

        return new RuntimeException(
            sprintf(
                'TransExpress API request failed [phase=%s category=%s] GET %s HTTP %d: %s',
                $phase,
                $category,
                $url,
                $status,
                $this->safeResponseBody($response)
            )
        );
    }

    private function classifyHttpStatus(int $status): string
    {
        return match ($status) {
            401 => 'http_401_unauthorized',
            403 => 'http_403_forbidden',
            404 => 'http_404_not_found',
            422 => 'http_422_unprocessable',
            429 => 'http_429_rate_limited',
            500 => 'http_500_server_error',
            502, 503, 504 => 'http_'.$status.'_upstream',
            default => 'http_'.$status,
        };
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

        if (str_contains($lower, 'connection refused') || str_contains($lower, 'curl error 7')) {
            return 'connection_refused';
        }

        return 'connection';
    }

    private function safeResponseBody(Response $response): string
    {
        $body = trim((string) $response->body());

        if ($body === '') {
            return '(empty body)';
        }

        $body = $this->sanitizeMessage($body);

        if (strlen($body) > self::MAX_SAFE_BODY_CHARS) {
            $body = substr($body, 0, self::MAX_SAFE_BODY_CHARS).'...';
        }

        return $body;
    }

    private function sanitizeMessage(string $message): string
    {
        return (string) (preg_replace('/Bearer\s+[^\s\'"]+/i', 'Bearer [REDACTED]', $message) ?? $message);
    }
}
