<?php

namespace Feeder\Core\Contracts;

use Illuminate\Http\UploadedFile;

interface FileStorageInterface
{
    public function upload(
        UploadedFile $file,
        array $options = []
    ): array;

    public function delete(string $uuid): bool;

    public function download(string $uuid);

    public function metadata(string $uuid): array;
}
