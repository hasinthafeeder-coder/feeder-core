<?php



namespace Feeder\Core\Services\Courier\CredentialSchema;



/**

 * Credential schema for Courier 1 — Royal Express (Curfox).

 *

 * Global CURFOX_BASE_URL / CURFOX_TENANT come from application config.

 * Supplier accounts store only email / password / token (encrypted).

 * Merchant business ID and origin live in meta_json, not credentials.

 */

final class RoyalExpressCredentialSchema extends AbstractCourierCredentialSchema

{

    public function courierCode(): string

    {

        return 'ROYAL';

    }



    public function fields(): array

    {

        return [

            new CourierCredentialField(

                key: 'email',

                label: 'ROYAL Login Email',

                inputType: 'email',

                secret: false,

                required: true,

                maskable: true,

                maskVisibleChars: 4,

                helpText: 'Supplier-specific Curfox merchant login email.',

            ),

            new CourierCredentialField(

                key: 'password',

                label: 'ROYAL Password',

                inputType: 'password',

                secret: true,

                required: true,

                helpText: 'Supplier-specific Curfox password. Leave blank when editing to keep the existing value.',

            ),

        ];

    }



    public function safeDisplay(array $credentials): array

    {

        $rows = parent::safeDisplay($credentials);



        $token = $credentials['token'] ?? null;

        $hasToken = is_string($token) ? trim($token) !== '' : $token !== null && $token !== '';



        $rows[] = [

            'key' => 'token',

            'label' => 'API Token',

            'display' => $hasToken ? 'Configured' : 'Not configured',

        ];



        return $rows;

    }



    public function maskSummary(array $credentials): ?string

    {

        $email = $credentials['email'] ?? null;



        if (is_string($email) && trim($email) !== '') {

            return $this->maskValue(trim($email), 4);

        }



        return parent::maskSummary($credentials);

    }

}


