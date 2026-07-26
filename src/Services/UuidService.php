<?php

namespace Feeder\Core\Services;

use Illuminate\Support\Str;

class UuidService
{
    public static function generate(int $length = 10): string
    {
        return strtoupper(Str::random($length));
    }
}
