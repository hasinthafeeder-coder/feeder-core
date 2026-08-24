<?php

namespace Feeder\Core\Enums;

enum ProductStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case DELETED = 'DELETED';
}
