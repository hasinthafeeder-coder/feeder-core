<?php

namespace Feeder\Core\Services\Courier;

use Feeder\Core\Contracts\Courier\CourierCredentialSchema;
use Feeder\Core\Models\Courier;
use Feeder\Core\Services\Courier\CredentialSchema\GenericCourierCredentialSchema;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves credential schemas by courier code.
 *
 * Provider-specific schemas are registered via config('feeder.courier_credential_schemas').
 * Unregistered couriers fall back to GenericCourierCredentialSchema until integrated.
 */
class CourierCredentialSchemaRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function forCourier(Courier $courier): CourierCredentialSchema
    {
        return $this->forCode((string) $courier->code);
    }

    public function forCode(string $courierCode): CourierCredentialSchema
    {
        $code = $this->normalize($courierCode);

        /** @var array<string, class-string<CourierCredentialSchema>> $map */
        $map = (array) config('feeder.courier_credential_schemas', []);
        $class = $map[$code] ?? $map[$courierCode] ?? null;

        if (is_string($class) && $class !== '' && is_a($class, CourierCredentialSchema::class, true)) {
            return $this->container->make($class);
        }

        return new GenericCourierCredentialSchema($code);
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     input_type: string,
     *     secret: bool,
     *     required: bool,
     *     maskable: bool,
     *     help_text: string|null
     * }>
     */
    public function fieldDefinitionsForCourier(Courier $courier): array
    {
        return array_map(
            static fn ($field) => $field->toArray(),
            $this->forCourier($courier)->fields()
        );
    }

    private function normalize(string $courierCode): string
    {
        return strtoupper(trim($courierCode));
    }
}
