<?php

namespace Feeder\Core\Exceptions;

use Exception;

/**
 * Structured failure for reseller debt obligation recording / correction.
 */
class ResellerDebtObligationException extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
