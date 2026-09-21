<?php

namespace Feeder\Core\Services\Courier;

use Feeder\Core\Contracts\Courier\CourierConnectionTester;
use Feeder\Core\Models\Courier;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves provider connection testers by courier code.
 *
 * Missing testers fail closed with an unavailable result — never invent success.
 */
class CourierConnectionTesterResolver
{
    /** @var array<string, CourierConnectionTester> */
    private array $overrides = [];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function register(string $courierCode, CourierConnectionTester $tester): void
    {
        $this->overrides[$this->normalize($courierCode)] = $tester;
    }

    public function resolve(Courier $courier): ?CourierConnectionTester
    {
        $code = $this->normalize((string) $courier->code);

        if (isset($this->overrides[$code])) {
            return $this->overrides[$code];
        }

        /** @var array<string, class-string<CourierConnectionTester>> $map */
        $map = (array) config('feeder.courier_connection_testers', []);
        $class = $map[$code] ?? $map[$courier->code] ?? null;

        if (! is_string($class) || $class === '' || ! is_a($class, CourierConnectionTester::class, true)) {
            return null;
        }

        return $this->container->make($class);
    }

    private function normalize(string $courierCode): string
    {
        return strtoupper(trim($courierCode));
    }
}
