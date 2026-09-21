<?php

namespace Feeder\Core\Enums;

enum OrderImportRowStatus: string
{
    case VALID = 'VALID';
    case BANNED = 'BANNED';
    case INVALID = 'INVALID';
    case CREATED = 'CREATED';

    public function label(): string
    {
        return match ($this) {
            self::VALID => 'Valid',
            self::BANNED => 'Banned',
            self::INVALID => 'Invalid',
            self::CREATED => 'Created',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::VALID => 'valid',
            self::BANNED => 'banned',
            self::INVALID => 'invalid',
            self::CREATED => 'created',
        };
    }
}
