<?php

namespace Feeder\Core\Services\Courier\Curfox;

/**
 * Normalized state payload after Curfox field mapping.
 */
final class MappedCourierState
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $externalRefNo,
        public readonly string $name,
        public readonly bool $isActive,
    ) {
    }
}
