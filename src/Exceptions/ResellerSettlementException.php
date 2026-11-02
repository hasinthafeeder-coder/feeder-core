<?php

namespace Feeder\Core\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Structured business failure for reseller settlement generation, locking, and payments.
 */
class ResellerSettlementException extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => $this->details,
        ], $this->status);
    }
}
