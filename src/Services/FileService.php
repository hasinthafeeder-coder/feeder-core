<?php

namespace Feeder\Core\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class FileService
{
    protected function client(): PendingRequest
    {
        return Http::baseUrl(config('feeder.file_server.url'))
            ->acceptJson()
            ->withToken(config('feeder.file_server.api_key'));
    }

    public function upload(UploadedFile $file, string $application, string $entityType, string $entityUuid, string $category, ?string $uploadedBy = null, array $metadata = [],): array
    {
        $response = $this->client()
            ->attach(
                'file',
                fopen($file->getRealPath(), 'r'),
                $file->getClientOriginalName()
            )
            ->post('/api/files/upload', [
                'application' => $application,
                'entity_type' => $entityType,
                'entity_uuid' => $entityUuid,
                'category' => $category,
                'uploaded_by' => $uploadedBy,
                'metadata' => json_encode($metadata),
            ])
            ->throw();

        return $response->json();
    }
}
