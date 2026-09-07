<?php

namespace Feeder\Core\Enums;

enum CustomerBanEventType: string
{
    case BANNED = 'BANNED';
    case LIFTED = 'LIFTED';
}
