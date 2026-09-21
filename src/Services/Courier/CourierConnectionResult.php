<?php

namespace Feeder\Core\Services\Courier;

final class CourierConnectionResult
{
    private function __construct(
        public readonly bool $available,
        public readonly bool $successful,
        public readonly string $message,
    ) {
    }

    public static function unavailable(
        string $message = 'Connection test is not available for this courier yet.'
    ): self {
        return new self(available: false, successful: false, message: $message);
    }

    public static function success(string $message = 'Connection successful.'): self
    {
        return new self(available: true, successful: true, message: $message);
    }

    public static function failure(string $message = 'Connection failed.'): self
    {
        return new self(available: true, successful: false, message: $message);
    }

    /**
     * @return array{available: bool, successful: bool, message: string}
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'successful' => $this->successful,
            'message' => $this->message,
        ];
    }
}
