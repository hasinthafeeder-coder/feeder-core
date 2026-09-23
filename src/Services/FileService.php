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
        $fileHandle = fopen($file->getRealPath(), 'r');
        
        try {
            $payload = [
                'application' => $application,
                'entity_type' => $entityType,
                'entity_uuid' => $entityUuid,
                'category' => $category,
                'metadata' => json_encode($metadata),
            ];

            // feeder-files uploaded_by is optional but size:10 when present — omit rather than send null/empty.
            if (is_string($uploadedBy) && $uploadedBy !== '') {
                $payload['uploaded_by'] = $uploadedBy;
            }

            $response = $this->client()
                ->attach(
                    'file',
                    $fileHandle,
                    $file->getClientOriginalName()
                )
                ->post('/api/files/upload', $payload)
                ->throw();

            return $response->json();
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
        }
    }
}
