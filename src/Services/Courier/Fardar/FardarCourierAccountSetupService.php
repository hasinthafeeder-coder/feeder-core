<?php

namespace Feeder\Core\Services\Courier\Fardar;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Courier\CourierCredentialSchemaRegistry;
use Feeder\Core\Services\Courier\SupplierCourierAccountService;
use Illuminate\Validation\ValidationException;

/**
 * Fardar Domestic supplier account setup.
 *
 * Fardar has no login/token endpoint. Credentials are validated for format
 * and persisted encrypted. Remote credential acceptance is confirmed on first booking.
 * Never exposes api_key in exceptions or return values.
 */
final class FardarCourierAccountSetupService
{
    public function __construct(
        private readonly SupplierCourierAccountService $accountService,
        private readonly CourierCredentialSchemaRegistry $schemaRegistry,
    ) {
    }

    /**
     * @param  array{
     *     courier_id?: int,
     *     courier_uuid?: string,
     *     account_label: string,
     *     credentials?: array<string, mixed>,
     *     is_default?: bool,
     *     is_active?: bool
     * }  $data
     */
    public function create(User $supplier, array $data, int $actorUserId): SupplierCourierAccount
    {
        $courier = $this->resolveFardarCourier($data);
        $schema = $this->schemaRegistry->forCourier($courier);
        $credentials = $schema->validate($data['credentials'] ?? [], partial: false);
        $this->assertCredentialFormat($credentials);

        return $this->accountService->create(
            $supplier,
            [
                'courier_id' => $courier->id,
                'account_label' => $data['account_label'],
                'credentials' => $credentials,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ],
            $actorUserId,
        );
    }

    /**
     * @param  array{
     *     account_label?: string,
     *     credentials?: array<string, mixed>,
     *     is_default?: bool,
     *     is_active?: bool
     * }  $data
     */
    public function update(SupplierCourierAccount $account, array $data, int $actorUserId): SupplierCourierAccount
    {
        $account->loadMissing('courier');

        if ($account->courier === null || strtoupper((string) $account->courier->code) !== 'FARDAR') {
            throw ValidationException::withMessages([
                'courier_id' => ['Fardar account setup can only update Fardar courier accounts.'],
            ]);
        }

        $schema = $this->schemaRegistry->forCourier($account->courier);
        $incoming = $schema->validate($data['credentials'] ?? [], partial: true);
        $existing = $account->getCredentials();

        $clientId = array_key_exists('client_id', $incoming)
            ? (string) $incoming['client_id']
            : (string) ($existing['client_id'] ?? '');
        $apiKey = array_key_exists('api_key', $incoming)
            ? (string) $incoming['api_key']
            : (string) ($existing['api_key'] ?? '');

        $this->assertCredentialFormat([
            'client_id' => $clientId,
            'api_key' => $apiKey,
        ]);

        $mergedCredentials = $existing;
        $mergedCredentials['client_id'] = $clientId;
        $mergedCredentials['api_key'] = $apiKey;

        return $this->accountService->update(
            $account,
            [
                'account_label' => $data['account_label'] ?? $account->account_label,
                'credentials' => $mergedCredentials,
                'is_default' => array_key_exists('is_default', $data)
                    ? (bool) $data['is_default']
                    : (bool) $account->is_default,
            ],
            $actorUserId,
        );
    }

    /**
     * @param  array{courier_id?: int, courier_uuid?: string}  $data
     */
    private function resolveFardarCourier(array $data): Courier
    {
        $courier = null;

        if (! empty($data['courier_id'])) {
            $courier = Courier::query()->active()->find((int) $data['courier_id']);
        } elseif (! empty($data['courier_uuid'])) {
            $courier = Courier::query()->active()->where('uuid', $data['courier_uuid'])->first();
        }

        if ($courier === null || strtoupper((string) $courier->code) !== 'FARDAR') {
            throw ValidationException::withMessages([
                'courier_id' => ['Select Fardar Domestic (FARDAR) to use this account setup.'],
            ]);
        }

        return $courier;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function assertCredentialFormat(array $credentials): void
    {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));

        $errors = [];

        if ($clientId === '') {
            $errors['credentials.client_id'] = ['Client ID is required.'];
        }

        if ($apiKey === '') {
            $errors['credentials.api_key'] = ['API Key is required.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
