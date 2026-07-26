<?php

namespace Feeder\Core\Enums;

enum UserType: string
{
    case OWNER = 'OWNER';
    case EMPLOYEE = 'EMPLOYEE';
    case SUPER_ADMIN = 'SUPER_ADMIN';
}
