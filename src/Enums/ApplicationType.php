<?php

namespace Feeder\Core\Enums;

enum ApplicationType: string
{
    case ADMIN = 'ADMIN';
    case RESELLER = 'RESELLER';
    case SUPPLIER = 'SUPPLIER';
    case FILES = 'FILES';
    case CORE = 'CORE';
}
