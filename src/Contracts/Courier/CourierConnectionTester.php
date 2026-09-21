<?php

namespace Feeder\Core\Contracts\Courier;

use Feeder\Core\Services\Courier\CourierConnectionResult;

interface CourierConnectionTester
{
    /**
     * Provider-specific credential validation against the courier API.
     *
     * Implementations must never log or return raw credential values.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function test(array $credentials): CourierConnectionResult;
}
