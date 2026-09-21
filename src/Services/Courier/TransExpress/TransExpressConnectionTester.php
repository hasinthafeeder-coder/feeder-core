<?php

namespace Feeder\Core\Services\Courier\TransExpress;

use Feeder\Core\Contracts\Courier\CourierConnectionTester;
use Feeder\Core\Services\Courier\CourierConnectionResult;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Validates TransExpress supplier credentials via client login.
 */
final class TransExpressConnectionTester implements CourierConnectionTester
{
    public function __construct(
        private readonly TransExpressAuthService $authService,
    ) {
    }

    public function test(array $credentials): CourierConnectionResult
    {
        try {
            $token = $this->authService->login(
                (string) ($credentials['email'] ?? ''),
                (string) ($credentials['password'] ?? ''),
            );

            if ($token === '') {
                return CourierConnectionResult::failure(
                    'Connection failed: TransExpress login did not return a usable token.'
                );
            }

            return CourierConnectionResult::success(
                'Connection successful. TransExpress login authenticated.'
            );
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values();
            $first = (string) ($messages->first() ?? 'Invalid TransExpress credentials.');

            return CourierConnectionResult::failure('Connection failed: '.$first);
        } catch (RuntimeException $e) {
            return CourierConnectionResult::failure(
                'Connection failed: Unable to reach TransExpress with the provided credentials.'
            );
        } catch (Throwable $e) {
            report($e);

            return CourierConnectionResult::failure(
                'Connection failed: Unable to validate credentials with the courier provider.'
            );
        }
    }
}
