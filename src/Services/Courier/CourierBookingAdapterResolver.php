<?php

namespace Feeder\Core\Services\Courier;

use Feeder\Core\Contracts\Courier\CourierBookingAdapter;
use Feeder\Core\Models\Courier;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the production CourierBookingAdapter for a courier code.
 *
 * Adapters are registered via config('feeder.courier_booking_adapters')
 * and may be overridden at runtime (e.g. tests) through register().
 * Missing adapters fail closed — no invented booking behaviour.
 */
class CourierBookingAdapterResolver
{
    /** @var array<string, CourierBookingAdapter> */
    private array $overrides = [];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function register(string $courierCode, CourierBookingAdapter $adapter): void
    {
        $this->overrides[$this->normalize($courierCode)] = $adapter;
    }

    public function resolve(Courier $courier): CourierBookingAdapter
    {
        $code = $this->normalize((string) $courier->code);

        if (isset($this->overrides[$code])) {
            return $this->overrides[$code];
        }

        /** @var array<string, class-string<CourierBookingAdapter>> $map */
        $map = (array) config('feeder.courier_booking_adapters', []);
        $class = $map[$code] ?? $map[$courier->code] ?? null;

        if (! is_string($class) || $class === '' || ! is_a($class, CourierBookingAdapter::class, true)) {
            throw ValidationException::withMessages([
                'booking' => [
                    'Courier booking is not configured for the selected courier.',
                ],
            ]);
        }

        return $this->container->make($class);
    }

    private function normalize(string $courierCode): string
    {
        return strtoupper(trim($courierCode));
    }
}
