<?php

namespace Feeder\Core\Enums;

enum CourierReceiptImportStatus: string
{
    case PREVIEWED = 'PREVIEWED';
    case FAILED = 'FAILED';
    case CONFIRMED = 'CONFIRMED';
}
