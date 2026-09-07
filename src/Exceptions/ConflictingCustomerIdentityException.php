<?php

namespace Feeder\Core\Exceptions;

use RuntimeException;

class ConflictingCustomerIdentityException extends RuntimeException
{
    public function __construct(
        public readonly int $primaryCustomerId,
        public readonly int $secondaryCustomerId,
        string $message = 'Phone numbers resolve to conflicting customer identities.',
    ) {
        parent::__construct($message);
    }
}
