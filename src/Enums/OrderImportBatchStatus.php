<?php

namespace Feeder\Core\Enums;

enum OrderImportBatchStatus: string
{
    case VALIDATED = 'VALIDATED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}
