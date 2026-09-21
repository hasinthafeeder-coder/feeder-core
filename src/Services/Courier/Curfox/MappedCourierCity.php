<?php

namespace Feeder\Core\Services\Courier\Curfox;

/**
 * Normalized city payload after Curfox field mapping.
 */
final class MappedCourierCity
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $externalRefNo,
        public readonly string $name,
        public readonly ?string $postalCode,
        public readonly string $providerStateExternalId,
        public readonly bool $isActive,
    ) {
    }
}
