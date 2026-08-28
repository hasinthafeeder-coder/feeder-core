<?php

namespace Feeder\Core\Exceptions;

use RuntimeException;

class UnsupportedRegistrationCountryException extends RuntimeException
{
    public static function forIsoCode(string $isoCode): self
    {
        return new self("Registration validation is not configured for country [{$isoCode}].");
    }
}
