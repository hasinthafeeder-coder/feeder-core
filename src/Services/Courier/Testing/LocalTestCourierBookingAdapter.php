<?php

namespace Feeder\Core\Services\Courier\Testing;

use Feeder\Core\Contracts\Courier\CourierBookingAdapter;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\SupplierCourierAccount;
use InvalidArgumentException;

/**
 * Local/testing-only courier booking adapter.
 *
 * Returns deterministic synthetic tracking numbers. Never performs HTTP or
 * contacts courier providers. Intended for runtime registration via
 * CourierBookingAdapterResolver::register() — not for production config maps.
 */
final class LocalTestCourierBookingAdapter implements CourierBookingAdapter
{
    public const MARKER = 'SETTLETEST';

    /** @var array<string, string> */
    private const WAYBILL_PREFIX = [
        'ROYAL' => 'STLRA',
        'TRANSEXPRESS' => 'STLBG',
        'FARDAR' => 'STLAPI',
    ];

    private int $sequence = 0;

    public function __construct(
        private readonly string $runId,
        private readonly ?string $forcedTrackingNumber = null,
    ) {
        if (trim($runId) === '') {
            throw new InvalidArgumentException('LocalTestCourierBookingAdapter requires a non-empty run id.');
        }
    }

    public function book(
        Order $order,
        Courier $courier,
        CourierService $service,
        CourierCity $city,
        ?SupplierCourierAccount $account,
        float $weightKg,
        float $courierFee,
    ): array {
        $tracking = $this->forcedTrackingNumber ?? $this->nextTrackingNumber((string) $courier->code);

        return [
            'tracking_number' => $tracking,
            'external_booking_ref' => self::MARKER.':'.$this->runId,
            'raw_response' => [
                'source' => self::MARKER,
                'adapter' => self::class,
                'run_id' => $this->runId,
                'courier_code' => strtoupper((string) $courier->code),
                'note' => 'Synthetic local booking — no courier HTTP call',
            ],
        ];
    }

    public function nextTrackingNumber(string $courierCode): string
    {
        $code = strtoupper(trim($courierCode));
        $prefix = self::WAYBILL_PREFIX[$code] ?? null;

        if ($prefix === null) {
            throw new InvalidArgumentException(
                'LocalTestCourierBookingAdapter has no waybill prefix for courier "'.$courierCode.'".'
            );
        }

        $this->sequence++;
        $runSlug = substr(preg_replace('/[^A-Za-z0-9]/', '', $this->runId) ?? '', 0, 6);
        if ($runSlug === '') {
            $runSlug = 'LOCAL0';
        }

        return $prefix.$runSlug.str_pad((string) $this->sequence, 5, '0', STR_PAD_LEFT);
    }
}
