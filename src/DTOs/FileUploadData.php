<?php

namespace Feeder\Core\DTOs;

class FileUploadData
{
    public function __construct(
        public readonly string $application,
        public readonly string $entityType,
        public readonly string $entityUuid,
        public readonly string $category,
        public readonly bool $isPrivate = false,
    ) {}
}
