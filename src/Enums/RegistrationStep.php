<?php

namespace Feeder\Core\Enums;

enum RegistrationStep: int
{
    case PHONE = 1;
    case PERSONAL = 2;
    case COMPANY = 3;
    case BANK = 4;
    case COMPLETED = 5;
}
