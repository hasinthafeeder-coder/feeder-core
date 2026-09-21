<?php

namespace Feeder\Core\Services\Courier\TransExpress;

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
 * TransExpress supplier account Test & Save provisioning.
 *
 * Authenticates against TransExpress before creating/updating an active account.
 * Never exposes password/token in exceptions or return values.
 */
final class TransExpressCourierAccountSetupService
{
    public function __construct(
        private readonly SupplierCourierAccountService $accountService,
        private readonly CourierCredentialSchemaRegistry $schemaRegistry,
        private readonly TransExpressAuthService $authService,
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
        $courier = $this->resolveTransExpressCourier($data);
        $schema = $this->schemaRegistry->forCourier($courier);
        $credentials = $schema->validate($data['credentials'] ?? [], partial: false);

        $token = $this->authenticate($credentials);
        $credentials['token'] = $token;

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

        if ($account->courier === null || strtoupper((string) $account->courier->code) !== 'TRANSEXPRESS') {
            throw ValidationException::withMessages([
                'courier_id' => ['TransExpress account setup can only update TransExpress courier accounts.'],
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
                'credentials.email' => ['TransExpress login email and password are required.'],
            ]);
        }

        $emailChanged = array_key_exists('email', $incoming)
            && trim((string) ($existing['email'] ?? '')) !== trim($email);
        $passwordChanged = array_key_exists('password', $incoming);

        // Never continue using a stale token when login identity changes.
        if ($emailChanged || $passwordChanged) {
            unset($existing['token']);
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
                'credentials' => ['TransExpress login email and password are incomplete.'],
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
    private function resolveTransExpressCourier(array $data): Courier
    {
        $courier = null;

        if (! empty($data['courier_id'])) {
            $courier = Courier::query()->active()->find((int) $data['courier_id']);
        } elseif (! empty($data['courier_uuid'])) {
            $courier = Courier::query()->active()->where('uuid', $data['courier_uuid'])->first();
        }

        if ($courier === null || strtoupper((string) $courier->code) !== 'TRANSEXPRESS') {
            throw ValidationException::withMessages([
                'courier_id' => ['Select TransExpress (TRANSEXPRESS) to use this account setup.'],
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
                'credentials' => ['Unable to authenticate with TransExpress. Try again later.'],
            ]);
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'credentials' => ['Unable to authenticate with TransExpress. Try again later.'],
            ]);
        }
    }
}
