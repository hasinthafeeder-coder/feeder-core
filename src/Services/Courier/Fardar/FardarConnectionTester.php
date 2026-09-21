<?php

namespace Feeder\Core\Services\Courier\Fardar;

use Feeder\Core\Contracts\Courier\CourierConnectionTester;
use Feeder\Core\Services\Courier\CourierConnectionResult;

/**
 * Fardar has no documented login/connection endpoint.
 *
 * Validates Client ID / API Key format locally without creating a parcel.
 * Remote credential acceptance is confirmed on the first create-parcel call.
 */
final class FardarConnectionTester implements CourierConnectionTester
{
    public function test(array $credentials): CourierConnectionResult
    {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));

        if ($clientId === '') {
            return CourierConnectionResult::failure(
                'Connection failed: Client ID is required.'
            );
        }

        if ($apiKey === '') {
            return CourierConnectionResult::failure(
                'Connection failed: API Key is required.'
            );
        }

        return CourierConnectionResult::success(
            'Credential format validated. Fardar authenticates Client ID and API Key on the first create-parcel request.'
        );
    }
}
