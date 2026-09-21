<?php

namespace Feeder\Core\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Provider booking failure that must surface to the caller with the real response.
 *
 * Extends ValidationException so existing booking catch blocks still abort
 * persistence. The debug payload is sanitized before it is attached.
 */
class CourierProviderBookingException extends ValidationException
{
    /**
     * @var array{
     *     provider: string,
     *     failure_type: string,
     *     http_status: int|null,
     *     request: array<string, mixed>|null,
     *     response: array<string, mixed>|string|null,
     *     exception?: array{class: string, message: string},
     *     response_truncated?: bool
     * }
     */
    public array $debug = [
        'provider' => '',
        'failure_type' => '',
        'http_status' => null,
        'request' => null,
        'response' => null,
    ];

    /**
     * @var array{name: string, code: string}|null
     */
    public ?array $courier = null;

    /**
     * @param  array<string, mixed>|string|null  $response
     * @param  array<string, mixed>|null  $request
     */
    public static function httpFailure(
        string $provider,
        int $httpStatus,
        array|string|null $response,
        string $summary,
        ?array $request = null,
        bool $responseTruncated = false,
    ): self {
        return self::make($summary, [
            'provider' => $provider,
            'failure_type' => 'http_error',
            'http_status' => $httpStatus,
            'request' => $request,
            'response' => $response,
        ], $responseTruncated);
    }

    /**
     * @param  array<string, mixed>|null  $request
     */
    public static function invalidJson(
        string $provider,
        int $httpStatus,
        string $rawBody,
        ?array $request = null,
        bool $responseTruncated = false,
    ): self {
        $label = match (strtoupper($provider)) {
            'ROYAL' => 'Royal Express',
            'TRANSEXPRESS' => 'TransExpress',
            'FARDAR' => 'Fardar Domestic',
            default => $provider !== '' ? $provider : 'Courier provider',
        };

        return self::make($label.' returned a non-JSON booking response.', [
            'provider' => $provider,
            'failure_type' => 'invalid_json',
            'http_status' => $httpStatus,
            'request' => $request,
            'response' => $rawBody,
        ], $responseTruncated);
    }

    /**
     * @param  array<string, mixed>|null  $request
     */
    public static function connection(
        string $provider,
        string $summary,
        string $exceptionClass,
        string $exceptionMessage,
        ?array $request = null,
    ): self {
        return self::make($summary, [
            'provider' => $provider,
            'failure_type' => 'connection',
            'http_status' => null,
            'request' => $request,
            'response' => null,
            'exception' => [
                'class' => $exceptionClass,
                'message' => $exceptionMessage,
            ],
        ]);
    }

    /**
     * HTTP 2xx body that still rejects the booking.
     *
     * @param  array<string, mixed>|string|null  $response
     * @param  array<string, mixed>|null  $request
     */
    public static function rejected(
        string $provider,
        int $httpStatus,
        array|string|null $response,
        string $summary,
        ?array $request = null,
    ): self {
        return self::make($summary, [
            'provider' => $provider,
            'failure_type' => 'application_rejection',
            'http_status' => $httpStatus,
            'request' => $request,
            'response' => $response,
        ]);
    }

    public static function authentication(string $provider, string $summary): self
    {
        return self::make($summary, [
            'provider' => $provider,
            'failure_type' => 'authentication',
            'http_status' => null,
            'request' => null,
            'response' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $request
     */
    public function withRequest(?array $request): self
    {
        $this->debug['request'] = $request;

        return $this;
    }

    public function withCourier(string $name, string $code): self
    {
        $this->courier = [
            'name' => $name,
            'code' => $code,
        ];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    private static function make(string $summary, array $debug, bool $responseTruncated = false): self
    {
        /** @var self $exception */
        $exception = self::withMessages([
            'booking' => [$summary],
        ]);

        if ($responseTruncated) {
            $debug['response_truncated'] = true;
        }

        $exception->debug = $debug;

        return $exception;
    }
}
