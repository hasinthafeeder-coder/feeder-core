<?php

namespace Feeder\Core\Enums;

enum CompanyStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case REJECTED = 'REJECTED';
    case SUSPENDED = 'SUSPENDED';
}
