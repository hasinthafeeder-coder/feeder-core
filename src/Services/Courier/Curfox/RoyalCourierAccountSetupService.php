<?php

namespace Feeder\Core\Services\Courier\Curfox;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Courier\CourierCredentialSchemaRegistry;
use Feeder\Core\Services\Courier\SupplierCourierAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * ROYAL supplier account Test & Save provisioning.
 *
 * Authenticates against Curfox before creating/updating an active account.
 * Never exposes password/token in exceptions or return values.
 */
final class RoyalCourierAccountSetupService
{
    public function __construct(
        private readonly SupplierCourierAccountService $accountService,
        private readonly CourierCredentialSchemaRegistry $schemaRegistry,
        private readonly CurfoxAuthService $authService,
        private readonly RoyalAccountMeta $accountMeta,
    ) {
    }

    /**
     * @param  array{
     *     courier_id?: int,
     *     courier_uuid?: string,
     *     account_label: string,
     *     credentials?: array<string, mixed>,
     *     meta?: array<string, mixed>,
     *     is_default?: bool,
     *     is_active?: bool
     * }  $data
     */
    public function create(User $supplier, array $data, int $actorUserId): SupplierCourierAccount
    {
        $courier = $this->resolveRoyalCourier($data);
        $schema = $this->schemaRegistry->forCourier($courier);
        $credentials = $schema->validate($data['credentials'] ?? [], partial: false);
        $meta = $this->accountMeta->validateAndBuild($courier, $data['meta'] ?? []);

        $token = $this->authenticate($credentials);
        $credentials['token'] = $token;

        $account = $this->accountService->create(
            $supplier,
            [
                'courier_id' => $courier->id,
                'account_label' => $data['account_label'],
                'credentials' => $credentials,
                'meta_json' => $meta,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ],
            $actorUserId,
        );

        return $account;
    }

    /**
     * @param  array{
     *     account_label?: string,
     *     credentials?: array<string, mixed>,
     *     meta?: array<string, mixed>,
     *     is_default?: bool,
     *     is_active?: bool
     * }  $data
     */
    public function update(SupplierCourierAccount $account, array $data, int $actorUserId): SupplierCourierAccount
    {
        $account->loadMissing('courier');

        if ($account->courier === null || strtoupper((string) $account->courier->code) !== 'ROYAL') {
            throw ValidationException::withMessages([
                'courier_id' => ['ROYAL account setup can only update ROYAL courier accounts.'],
            ]);
        }

        $schema = $this->schemaRegistry->forCourier($account->courier);
        $incoming = $schema->validate($data['credentials'] ?? [], partial: true);
        $existing = $account->getCredentials();

        $email = array_key_exists('email', $incoming)
            ? (string) $incoming['email']
            : (string) ($existing['email'] ?? '');
        $password = array_key_exists('password', $incoming)
            ? (string) $incoming['password']
            : (string) ($existing['password'] ?? '');

        if (trim($email) === '' || $password === '') {
            throw ValidationException::withMessages([
                'credentials.email' => ['ROYAL login email and password are required.'],
            ]);
        }

        $emailChanged = array_key_exists('email', $incoming)
            && trim((string) ($existing['email'] ?? '')) !== trim($email);
        $passwordChanged = array_key_exists('password', $incoming);

        // Never continue using a stale token when login identity changes.
        if ($emailChanged || $passwordChanged) {
            unset($existing['token']);
        }

        if (array_key_exists('meta', $data)) {
            $meta = $this->accountMeta->validateAndBuild($account->courier, $data['meta'] ?? []);
        } else {
            $meta = is_array($account->meta_json) ? $account->meta_json : [];

            if (
                trim((string) ($meta[RoyalAccountMeta::KEY_MERCHANT_BUSINESS_ID] ?? '')) === ''
                || trim((string) ($meta[RoyalAccountMeta::KEY_ORIGIN_STATE_NAME] ?? '')) === ''
                || trim((string) ($meta[RoyalAccountMeta::KEY_ORIGIN_CITY_NAME] ?? '')) === ''
            ) {
                throw ValidationException::withMessages([
                    'meta.merchant_business_id' => [
                        'ROYAL account metadata is incomplete. Re-enter merchant business ID and origin.',
                    ],
                ]);
            }
        }

        $token = $this->authenticate([
            'email' => $email,
            'password' => $password,
        ]);

        $mergedCredentials = $existing;
        $mergedCredentials['email'] = $email;
        $mergedCredentials['password'] = $password;
        $mergedCredentials['token'] = $token;

        return $this->accountService->update(
            $account,
            [
                'account_label' => $data['account_label'] ?? $account->account_label,
                'credentials' => $mergedCredentials,
                'meta_json' => $meta,
                'is_default' => array_key_exists('is_default', $data)
                    ? (bool) $data['is_default']
                    : (bool) $account->is_default,
            ],
            $actorUserId,
        );
    }

    /**
     * Re-authenticate an existing account and persist a fresh token.
     */
    public function refreshToken(SupplierCourierAccount $account): string
    {
        $credentials = $account->getCredentials();
        $email = trim((string) ($credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ($email === '' || $password === '') {
            throw ValidationException::withMessages([
                'credentials' => ['ROYAL login email and password are incomplete.'],
            ]);
        }

        $token = $this->authenticate([
            'email' => $email,
            'password' => $password,
        ]);

        return DB::transaction(function () use ($account, $credentials, $token): string {
            $locked = SupplierCourierAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $credentials['token'] = $token;
            $locked->setCredentials($credentials);
            $locked->save();

            return $token;
        });
    }

    /**
     * @param  array{courier_id?: int, courier_uuid?: string}  $data
     */
    private function resolveRoyalCourier(array $data): Courier
    {
        $courier = null;

        if (! empty($data['courier_id'])) {
            $courier = Courier::query()->active()->find((int) $data['courier_id']);
        } elseif (! empty($data['courier_uuid'])) {
            $courier = Courier::query()->active()->where('uuid', $data['courier_uuid'])->first();
        }

        if ($courier === null || strtoupper((string) $courier->code) !== 'ROYAL') {
            throw ValidationException::withMessages([
                'courier_id' => ['Select Royal Express (ROYAL) to use this account setup.'],
            ]);
        }

        return $courier;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function authenticate(array $credentials): string
    {
        try {
            return $this->authService->login(
                (string) ($credentials['email'] ?? ''),
                (string) ($credentials['password'] ?? ''),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'credentials' => ['Unable to authenticate with ROYAL. Try again later.'],
            ]);
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'credentials' => ['Unable to authenticate with ROYAL. Try again later.'],
            ]);
        }
    }
}

