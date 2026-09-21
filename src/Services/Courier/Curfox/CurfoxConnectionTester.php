<?php



namespace Feeder\Core\Services\Courier\Curfox;



use Feeder\Core\Contracts\Courier\CourierConnectionTester;

use Feeder\Core\Services\Courier\CourierConnectionResult;

use Illuminate\Validation\ValidationException;

use RuntimeException;

use Throwable;



/**

 * Validates Royal Express / Curfox supplier credentials via merchant login.

 */

final class CurfoxConnectionTester implements CourierConnectionTester

{

    public function __construct(

        private readonly CurfoxAuthService $authService,

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

                    'Connection failed: Curfox login did not return a usable token.'

                );

            }



            return CourierConnectionResult::success(

                'Connection successful. ROYAL login authenticated.'

            );

        } catch (ValidationException $e) {

            $messages = collect($e->errors())->flatten()->filter()->values();

            $first = (string) ($messages->first() ?? 'Invalid ROYAL credentials.');



            return CourierConnectionResult::failure('Connection failed: '.$first);

        } catch (RuntimeException $e) {

            return CourierConnectionResult::failure(

                'Connection failed: Unable to reach Curfox with the provided credentials.'

            );

        } catch (Throwable $e) {

            report($e);



            return CourierConnectionResult::failure(

                'Connection failed: Unable to validate credentials with the courier provider.'

            );

        }

    }

}


