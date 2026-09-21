<?php

namespace Feeder\Core\Services\Courier;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin-facing supplier courier account lifecycle.
 *
 * Credentials are encrypted at rest. Callers must never return getCredentials()
 * to frontend/API responses. Use presentAccount() for safe Admin views.
 */
class SupplierCourierAccountService
{
    public function __construct(
        private readonly CourierCredentialSchemaRegistry $schemaRegistry,
    ) {
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForSupplier(User $supplier): Collection
    {
        return SupplierCourierAccount::query()
            ->with('courier')
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('account_label')
            ->get()
            ->map(fn (SupplierCourierAccount $account) => $this->presentAccount($account));
    }

    /**
     * Safe Admin presentation — never includes decrypted secrets.
     *
     * @return array{
     *     uuid: string,
     *     account_label: string,
     *     is_active: bool,
     *     is_default: bool,
     *     courier: array{uuid: string, code: string, name: string}|null,
     *     mask_summary: string|null,
     *     credential_display: list<array{key: string, label: string, display: string}>,
     *     credentials_unreadable: bool,
     *     meta: array{
     *         merchant_business_id: string|null,
     *         origin_state_id: int|null,
     *         origin_city_id: int|null,
     *         origin_state_name: string|null,
     *         origin_city_name: string|null
     *     },
     *     created_by: int|null,
     *     updated_by: int|null
     * }
     */
    public function presentAccount(SupplierCourierAccount $account): array
    {
        $account->loadMissing('courier');

        $presentedCredentials = $account->presentableCredentials();
        $credentials = $presentedCredentials['credentials'];
        $credentialsUnreadable = $presentedCredentials['unreadable'];

        $schema = $account->courier !== null
            ? $this->schemaRegistry->forCourier($account->courier)
            : $this->schemaRegistry->forCode('GENERIC');

        $meta = is_array($account->meta_json) ? $account->meta_json : [];

        return [
            'uuid' => (string) $account->uuid,
            'account_label' => (string) $account->account_label,
            'is_active' => (bool) $account->is_active,
            'is_default' => (bool) $account->is_default,
            'courier' => $account->courier === null ? null : [
                'uuid' => (string) $account->courier->uuid,
                'code' => (string) $account->courier->code,
                'name' => (string) $account->courier->name,
            ],
            'mask_summary' => $credentialsUnreadable
                ? 'Credentials unreadable — re-enter required'
                : $schema->maskSummary($credentials),
            'credential_display' => $credentialsUnreadable
                ? []
                : $schema->safeDisplay($credentials),
            'credentials_unreadable' => $credentialsUnreadable,
            'meta' => [
                'merchant_business_id' => isset($meta['merchant_business_id'])
                    ? (string) $meta['merchant_business_id']
                    : null,
                'origin_state_id' => isset($meta['origin_state_id']) ? (int) $meta['origin_state_id'] : null,
                'origin_city_id' => isset($meta['origin_city_id']) ? (int) $meta['origin_city_id'] : null,
                'origin_state_name' => isset($meta['origin_state_name'])
                    ? (string) $meta['origin_state_name']
                    : null,
                'origin_city_name' => isset($meta['origin_city_name'])
                    ? (string) $meta['origin_city_name']
                    : null,
            ],
            'created_by' => $account->created_by !== null ? (int) $account->created_by : null,
            'updated_by' => $account->updated_by !== null ? (int) $account->updated_by : null,
        ];
    }

    /**
     * @param  array{
     *     courier_id?: int,
     *     courier_uuid?: string,
     *     account_label: string,
     *     credentials?: array<string, mixed>,
     *     meta_json?: array<string, mixed>|null,
     *     is_default?: bool,
     *     is_active?: bool
     * }  $data
     */
    public function create(User $supplier, array $data, int $actorUserId): SupplierCourierAccount
    {
        $this->assertSupplier($supplier);

        $courier = $this->resolveCourier($data);
        $schema = $this->schemaRegistry->forCourier($courier);
        $credentials = $schema->validate($data['credentials'] ?? [], partial: false);
        $credentials = $this->mergeInternalCredentialKeys($credentials, $data['credentials'] ?? []);

        $makeDefault = (bool) ($data['is_default'] ?? false);
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        $metaJson = array_key_exists('meta_json', $data) && is_array($data['meta_json'])
            ? $data['meta_json']
            : null;

        if ($makeDefault && ! $isActive) {
            throw ValidationException::withMessages([
                'is_default' => ['Only an active courier account can be set as default.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($supplier, $courier, $data, $credentials, $metaJson, $makeDefault, $isActive, $actorUserId) {
                if ($makeDefault) {
                    $this->clearDefaultsForSupplier((int) $supplier->id);
                }

                $account = new SupplierCourierAccount([
                    'supplier_id' => $supplier->id,
                    'courier_id' => $courier->id,
                    'account_label' => trim((string) $data['account_label']),
                    'meta_json' => $metaJson,
                    'is_active' => $isActive,
                    'is_default' => $makeDefault,
                    'created_by' => $actorUserId,
                    'updated_by' => $actorUserId,
                ]);
                $account->setCredentials($credentials);
                $account->save();

                return $account->fresh(['courier']);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateSupplierCourier($e)) {
                throw ValidationException::withMessages([
                    'courier_id' => [
                        'This supplier already has an account for the selected courier.',
                    ],
                ]);
            }

            if ($this->isDuplicateActiveDefault($e)) {
                throw ValidationException::withMessages([
                    'is_default' => [
                        'This supplier already has an active default courier account.',
                    ],
                ]);
            }

            throw $e;
        }
    }

    /**
     * @param  array{
     *     account_label?: string,
     *     credentials?: array<string, mixed>,
     *     meta_json?: array<string, mixed>|null,
     *     is_default?: bool,
     *     is_active?: bool
     * }  $data
     */
    public function update(SupplierCourierAccount $account, array $data, int $actorUserId): SupplierCourierAccount
    {
        $account->loadMissing('courier');

        if ($account->courier === null) {
            throw ValidationException::withMessages([
                'courier_id' => ['Courier service could not be resolved for this account.'],
            ]);
        }

        $schema = $this->schemaRegistry->forCourier($account->courier);

        try {
            return DB::transaction(function () use ($account, $data, $schema, $actorUserId) {
                $locked = SupplierCourierAccount::query()
                    ->whereKey($account->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (array_key_exists('account_label', $data)) {
                    $locked->account_label = trim((string) $data['account_label']);
                }

                if (array_key_exists('credentials', $data) && is_array($data['credentials'])) {
                    $incoming = $schema->validate($data['credentials'], partial: true);
                    $merged = $this->mergeCredentials($locked->getCredentials(), $incoming, $schema->fields());
                    $merged = $this->mergeInternalCredentialKeys($merged, $data['credentials']);
                    $locked->setCredentials($merged);
                }

                if (array_key_exists('meta_json', $data)) {
                    $locked->meta_json = is_array($data['meta_json']) ? $data['meta_json'] : null;
                }

                $willBeActive = array_key_exists('is_active', $data)
                    ? (bool) $data['is_active']
                    : (bool) $locked->is_active;

                $willBeDefault = array_key_exists('is_default', $data)
                    ? (bool) $data['is_default']
                    : (bool) $locked->is_default;

                if (! $willBeActive) {
                    $willBeDefault = false;
                }

                if ($willBeDefault && ! $willBeActive) {
                    throw ValidationException::withMessages([
                        'is_default' => ['Only an active courier account can be set as default.'],
                    ]);
                }

                if ($willBeDefault) {
                    $this->clearDefaultsForSupplier((int) $locked->supplier_id, (int) $locked->id);
                }

                $locked->is_active = $willBeActive;
                $locked->is_default = $willBeDefault;
                $locked->updated_by = $actorUserId;
                $locked->save();

                return $locked->fresh(['courier']);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateActiveDefault($e)) {
                throw ValidationException::withMessages([
                    'is_default' => [
                        'This supplier already has an active default courier account.',
                    ],
                ]);
            }

            throw $e;
        }
    }

    public function activate(SupplierCourierAccount $account, int $actorUserId): SupplierCourierAccount
    {
        return $this->update($account, ['is_active' => true], $actorUserId);
    }

    public function deactivate(SupplierCourierAccount $account, int $actorUserId): SupplierCourierAccount
    {
        return $this->update($account, [
            'is_active' => false,
            'is_default' => false,
        ], $actorUserId);
    }

    public function setDefault(SupplierCourierAccount $account, int $actorUserId): SupplierCourierAccount
    {
        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'is_default' => ['Activate the courier account before setting it as default.'],
            ]);
        }

        return $this->update($account, ['is_default' => true], $actorUserId);
    }

    public function clearDefault(SupplierCourierAccount $account, int $actorUserId): SupplierCourierAccount
    {
        return $this->update($account, ['is_default' => false], $actorUserId);
    }

    public function requireForSupplier(User $supplier, string $accountUuid): SupplierCourierAccount
    {
        $this->assertSupplier($supplier);

        $account = SupplierCourierAccount::query()
            ->with('courier')
            ->where('uuid', $accountUuid)
            ->where('supplier_id', $supplier->id)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => ['Courier account was not found for this supplier.'],
            ]);
        }

        return $account;
    }

    /**
     * Couriers available for a new account (active couriers not already linked).
     *
     * @return Collection<int, Courier>
     */
    public function availableCouriersForSupplier(User $supplier): Collection
    {
        $this->assertSupplier($supplier);

        $linkedCourierIds = SupplierCourierAccount::query()
            ->where('supplier_id', $supplier->id)
            ->pluck('courier_id');

        return Courier::query()
            ->active()
            ->when($linkedCourierIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $linkedCourierIds))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @param  list<\Feeder\Core\Services\Courier\CredentialSchema\CourierCredentialField>  $fields
     * @return array<string, mixed>
     */
    private function mergeCredentials(array $existing, array $incoming, array $fields): array
    {
        $merged = $existing;

        foreach ($fields as $field) {
            if (! array_key_exists($field->key, $incoming)) {
                continue;
            }

            $merged[$field->key] = $incoming[$field->key];
        }

        return $merged;
    }

    /**
     * Allow internal non-form credential keys (e.g. provider tokens) to persist.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function mergeInternalCredentialKeys(array $credentials, array $source): array
    {
        if (! array_key_exists('token', $source)) {
            return $credentials;
        }

        $token = $source['token'];

        if ($token === null || $token === '') {
            unset($credentials['token']);

            return $credentials;
        }

        if (! is_scalar($token)) {
            return $credentials;
        }

        $normalized = \Feeder\Core\Services\Courier\Curfox\CurfoxApiClient::normalizeBearerToken((string) $token);

        if ($normalized === '') {
            unset($credentials['token']);

            return $credentials;
        }

        $credentials['token'] = $normalized;

        return $credentials;
    }

    private function clearDefaultsForSupplier(int $supplierId, ?int $exceptAccountId = null): void
    {
        $query = SupplierCourierAccount::query()
            ->where('supplier_id', $supplierId)
            ->where('is_default', true);

        if ($exceptAccountId !== null) {
            $query->where('id', '!=', $exceptAccountId);
        }

        $query->update(['is_default' => false]);
    }

    /**
     * @param  array{courier_id?: int, courier_uuid?: string}  $data
     */
    private function resolveCourier(array $data): Courier
    {
        $courier = null;

        if (! empty($data['courier_id'])) {
            $courier = Courier::query()->active()->find((int) $data['courier_id']);
        } elseif (! empty($data['courier_uuid'])) {
            $courier = Courier::query()->active()->where('uuid', $data['courier_uuid'])->first();
        }

        if ($courier === null) {
            throw ValidationException::withMessages([
                'courier_id' => ['Select a valid active courier service.'],
            ]);
        }

        return $courier;
    }

    private function assertSupplier(User $supplier): void
    {
        $supplier->loadMissing('company.portal');

        if (! $supplier->isSupplier()) {
            throw ValidationException::withMessages([
                'supplier' => ['Courier accounts can only be managed for supplier users.'],
            ]);
        }
    }

    private function isDuplicateSupplierCourier(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'supplier_courier_accounts_supplier_id_courier_id_unique')
            || (str_contains($message, 'Duplicate entry') && str_contains($message, 'supplier_id'));
    }

    private function isDuplicateActiveDefault(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'supplier_courier_accounts_active_default_unique');
    }
}
