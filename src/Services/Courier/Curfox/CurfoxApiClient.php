<?php

namespace Feeder\Core\Services\Courier\Curfox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Authenticated HTTP client for Curfox merchant public APIs.
 *
 * Responsible only for HTTP communication — no database logic.
 * Never logs Authorization headers or token values.
 */
final class CurfoxApiClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 60;

    private const MAX_RETRIES = 2;

    private const STATES_PATH = '/api/public/merchant/state';

    private const CITIES_PATH = '/api/public/merchant/city';

    private const MAX_SAFE_BODY_CHARS = 500;

    private const MAX_PAGES = 500;

    /**
     * @param  array{base_url: string, token: string, tenant: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
    ) {
    }

    /**
     * Strip an optional leading "Bearer " so callers can pass either form.
     */
    public static function normalizeBearerToken(string $token): string
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

    /**
     * Fetch all states (authoritative for state master data).
     *
     * Prefers noPagination; falls back to meta-driven pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function getStates(): array
    {
        return $this->collectAll(self::STATES_PATH);
    }

    /**
     * Fetch all cities (authoritative for city master data).
     *
     * Prefers noPagination; falls back to meta-driven pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function getCities(): array
    {
        return $this->collectAll(self::CITIES_PATH);
    }

    /**
     * Lightweight single-request probe used by connection testing.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>|null}
     */
    public function fetchStates(): array
    {
        $payload = $this->get(self::STATES_PATH, ['paginate' => 1, 'page' => 1]);
        $this->requireDataKey($payload, self::STATES_PATH);

        return [
            'data' => $this->extractDataList($payload),
            'meta' => $this->extractMeta($payload),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectAll(string $path): array
    {
        $payload = $this->get($path, ['noPagination' => 1]);
        $this->requireDataKey($payload, $path);
        $data = $this->extractDataList($payload);
        $meta = $this->extractMeta($payload);
        $lastPage = (int) ($meta['last_page'] ?? 1);

        if ($meta === null || $lastPage <= 1) {
            return $data;
        }

        // Provider ignored noPagination and still paginated — walk pages explicitly.
        return $this->collectPaginated($path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectPaginated(string $path): array
    {
        $all = [];
        $page = 1;
        $lastPage = 1;

        do {
            if ($page > self::MAX_PAGES) {
                throw new RuntimeException(
                    sprintf(
                        'Curfox API pagination exceeded safe page limit [phase=pagination] GET %s max_pages=%d',
                        $this->absoluteUrl($path),
                        self::MAX_PAGES
                    )
                );
            }

            $payload = $this->get($path, ['page' => $page]);
            $this->requireDataKey($payload, $path);
            $chunk = $this->extractDataList($payload);
            $meta = $this->extractMeta($payload) ?? [];

            $currentPage = (int) ($meta['current_page'] ?? $page);
            $lastPage = max(1, (int) ($meta['last_page'] ?? $page));

            if ($currentPage !== $page) {
                throw new RuntimeException(
                    sprintf(
                        'Curfox API pagination mismatch [phase=pagination] GET %s expected_page=%d current_page=%d',
                        $this->absoluteUrl($path, ['page' => $page]),
                        $page,
                        $currentPage
                    )
                );
            }

            foreach ($chunk as $row) {
                $all[] = $row;
            }

            if ($chunk === [] && $page < $lastPage) {
                throw new RuntimeException(
                    sprintf(
                        'Curfox API pagination failed [phase=pagination] GET %s page=%d of %d returned empty data',
                        $this->absoluteUrl($path, ['page' => $page]),
                        $page,
                        $lastPage
                    )
                );
            }

            $page++;
        } while ($page <= $lastPage);

        return $all;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $baseUrl = rtrim((string) ($this->credentials['base_url'] ?? ''), '/');
        $token = self::normalizeBearerToken((string) ($this->credentials['token'] ?? ''));
        $tenant = trim((string) ($this->credentials['tenant'] ?? ''));

        if ($baseUrl === '' || $token === '' || $tenant === '') {
            throw new RuntimeException('Curfox credentials are incomplete.');
        }

        $url = $this->absoluteUrl($path, $query);

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'X-tenant' => $tenant,
                ])
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
                    'Curfox API request failed during connection [phase=connection] GET %s: %s (%s)',
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
                    'Curfox API returned an invalid JSON payload [phase=payload] GET %s HTTP %d body=%s',
                    $url,
                    $response->status(),
                    $this->safeResponseBody($response)
                )
            );
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requireDataKey(array $payload, string $path): void
    {
        if (! array_key_exists('data', $payload)) {
            throw new RuntimeException(
                sprintf(
                    'Curfox API response is missing required "data" key [phase=payload] GET %s',
                    $this->absoluteUrl($path)
                )
            );
        }

        if (! is_array($payload['data'])) {
            throw new RuntimeException(
                sprintf(
                    'Curfox API response "data" must be an array [phase=payload] GET %s',
                    $this->absoluteUrl($path)
                )
            );
        }
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
                'Curfox API connection failed [phase=connection category=%s] GET %s: %s (%s)',
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
                'Curfox API request failed [phase=%s category=%s] GET %s HTTP %d: %s',
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
        $message = preg_replace('/Bearer\s+[^\s\'"]+/i', 'Bearer [REDACTED]', $message) ?? $message;

        return $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractDataList(array $payload): array
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        $list = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractMeta(array $payload): ?array
    {
        $meta = $payload['meta'] ?? null;

        return is_array($meta) ? $meta : null;
    }
}
