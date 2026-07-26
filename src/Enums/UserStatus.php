<?php

namespace Feeder\Core\Enums;

enum UserStatus: string
{
    case REGISTERING = 'REGISTERING';
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case REJECTED = 'REJECTED';
    case SUSPENDED = 'SUSPENDED';
    case DELETED = 'DELETED';
}
