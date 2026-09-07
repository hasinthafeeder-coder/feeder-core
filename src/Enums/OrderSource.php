<?php

namespace Feeder\Core\Enums;

enum OrderSource: string
{
    case MANUAL = 'MANUAL';
    case META_IMPORT = 'META_IMPORT';
    case API = 'API';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::META_IMPORT => 'Meta Import',
            self::API => 'API',
        };
    }
}
