<?php

namespace Feeder\Core\Services\Courier;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Services\Courier\Curfox\RoyalCourierAccountSetupService;
use Feeder\Core\Services\Courier\TransExpress\TransExpressCourierAccountSetupService;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Server-side courier credential connection testing.
 *
 * Admin browsers never call courier APIs directly.
 */
class CourierConnectionService
{
    public function __construct(
        private readonly CourierConnectionTesterResolver $testerResolver,
        private readonly CourierCredentialSchemaRegistry $schemaRegistry,
    ) {
    }

    public function testAccount(SupplierCourierAccount $account): CourierConnectionResult
    {
        $account->loadMissing('courier');

        if ($account->courier === null) {
            return CourierConnectionResult::failure('Courier service could not be resolved for this account.');
        }

        $code = strtoupper((string) $account->courier->code);

        if ($code === 'ROYAL') {
            try {
                app(RoyalCourierAccountSetupService::class)->refreshToken($account);

                return CourierConnectionResult::success(
                    'Connection successful. ROYAL login authenticated and token updated.'
                );
            } catch (ValidationException $e) {
                $messages = collect($e->errors())->flatten()->filter()->values();
                $first = (string) ($messages->first() ?? 'Invalid ROYAL credentials.');

                return CourierConnectionResult::failure('Connection failed: '.$first);
            } catch (Throwable $e) {
                report($e);

                return CourierConnectionResult::failure(
                    'Connection failed: Unable to validate credentials with the courier provider.'
                );
            }
        }

        if ($code === 'TRANSEXPRESS') {
            try {
                app(TransExpressCourierAccountSetupService::class)->refreshToken($account);

                return CourierConnectionResult::success(
                    'Connection successful. TransExpress login authenticated and token updated.'
                );
            } catch (ValidationException $e) {
                $messages = collect($e->errors())->flatten()->filter()->values();
                $first = (string) ($messages->first() ?? 'Invalid TransExpress credentials.');

                return CourierConnectionResult::failure('Connection failed: '.$first);
            } catch (Throwable $e) {
                report($e);

                return CourierConnectionResult::failure(
                    'Connection failed: Unable to validate credentials with the courier provider.'
                );
            }
        }

        return $this->testCredentials($account->courier, $account->getCredentials());
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function testCredentials(Courier $courier, array $credentials): CourierConnectionResult
    {
        $tester = $this->testerResolver->resolve($courier);

        if ($tester === null) {
            return CourierConnectionResult::unavailable();
        }

        try {
            $schema = $this->schemaRegistry->forCourier($courier);
            $validated = $schema->validate($credentials, partial: false);

            return $tester->test($validated);
        } catch (ValidationException $e) {
            return CourierConnectionResult::failure(
                'Connection failed: Invalid credentials.'
            );
        } catch (Throwable $e) {
            report($e);

            return CourierConnectionResult::failure(
                'Connection failed: Unable to validate credentials with the courier provider.'
            );
        }
    }
}
