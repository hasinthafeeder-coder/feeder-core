<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Contracts\Courier\CourierBookingAdapter;
use Feeder\Core\Enums\ShipmentStatus;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Models\ShipmentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ShipmentBookingService
{
    public function __construct(
        private readonly CourierFeeCalculator $feeCalculator,
        private readonly OrderShipmentLockService $shipmentLockService,
        private readonly OrderAuthorizationGuard $authorizationGuard,
        private readonly OrderCourierLookupService $courierLookupService,
    ) {
    }

    /**
     * Domain booking flow foundation:
     * validate courier/service/city → require active supplier courier account
     * → require market pricing → calculate fee → adapter book → create shipment.
     * Failed adapter calls leave the order unlocked (no shipment row).
     *
     * @param  int|null  $actorCompanyId  When provided, enforces reseller-company boundary.
     */
    public function book(
        Order $order,
        int $courierId,
        int $courierServiceId,
        int $courierCityId,
        CourierBookingAdapter $adapter,
        ?int $bookedBy = null,
        ?int $actorCompanyId = null,
    ): Shipment {
        return DB::transaction(function () use ($order, $courierId, $courierServiceId, $courierCityId, $adapter, $bookedBy, $actorCompanyId) {
            $order = Order::query()->with(['items', 'shipment'])->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            if ($order->shipment !== null) {
                throw ValidationException::withMessages([
                    'order_id' => ['Order already has a shipment.'],
                ]);
            }

            $courier = $this->courierLookupService->requireEligibleCourier($order, $courierId);

            $service = CourierService::query()
                ->where('courier_id', $courier->id)
                ->active()
                ->find($courierServiceId);

            if ($service === null) {
                throw ValidationException::withMessages([
                    'courier_service_id' => ['Courier service is invalid for the selected courier.'],
                ]);
            }

            $city = $this->courierLookupService->requireEligibleCity($order, $courier->id, $courierCityId);
            $account = $this->courierLookupService->requireSupplierAccount($order, $courier);
            $pricing = $this->courierLookupService->requireMarketPricing($order, $courier);

            $weight = (float) $order->total_weight;
            $fee = $this->feeCalculator->calculate($pricing, $weight);

            try {
                $bookingResult = $adapter->book(
                    $order,
                    $courier,
                    $service,
                    $city,
                    $account,
                    $weight,
                    $fee,
                );
            } catch (ValidationException $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);

                throw ValidationException::withMessages([
                    'booking' => [
                        'Courier booking failed. No shipment was created. Contact support before retrying if the courier may have accepted the request.',
                    ],
                ]);
            }

            $trackingNumber = $bookingResult['tracking_number'] ?? null;

            if (! is_string($trackingNumber) || $trackingNumber === '') {
                throw ValidationException::withMessages([
                    'booking' => ['Courier booking did not return a tracking number.'],
                ]);
            }

            $shipment = Shipment::query()->create([
                'order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'courier_id' => $courier->id,
                'courier_service_id' => $service->id,
                'courier_city_id' => $city->id,
                'supplier_courier_account_id' => $account->id,
                'tracking_number' => $trackingNumber,
                'weight_snapshot' => $weight,
                'courier_fee_snapshot' => $fee,
                'currency_id' => $order->currency_id,
                'pricing_rule_snapshot' => [
                    'first_kg_fee' => (float) $pricing->first_kg_fee,
                    'additional_kg_fee' => (float) $pricing->additional_kg_fee,
                ],
                'status' => ShipmentStatus::BOOKED,
                'booked_at' => now(),
                'booked_by' => $bookedBy,
                'external_booking_ref' => $bookingResult['external_booking_ref'] ?? null,
            ]);

            $order->update([
                'courier_fee_amount' => $fee,
                'customer_payable_amount' => round(
                    (float) $order->items_subtotal - (float) $order->discount_amount + $fee,
                    2
                ),
                'updated_by' => $bookedBy,
            ]);

            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'external_status' => 'BOOKED',
                'normalized_status' => ShipmentStatus::BOOKED->value,
                'description' => 'Shipment booked successfully.',
                'event_at' => now(),
                'raw_response' => $this->sanitizeRawResponse($bookingResult['raw_response'] ?? null),
            ]);

            return $shipment->fresh(['events', 'courier', 'service', 'city']);
        });
    }

    /**
     * Domain write path for courier selection changes. Locked after booking.
     */
    public function updateCourierSelection(
        Order $order,
        int $courierId,
        int $courierServiceId,
        int $courierCityId,
        ?int $updatedBy = null,
        ?int $actorCompanyId = null,
    ): Shipment {
        return DB::transaction(function () use ($order, $courierId, $courierServiceId, $courierCityId, $updatedBy, $actorCompanyId) {
            $order = Order::query()->with('shipment')->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->shipmentLockService->assertShipmentMutable($order);

            $shipment = $order->shipment;

            if ($shipment === null) {
                throw ValidationException::withMessages([
                    'shipment' => ['Order does not have a shipment to update.'],
                ]);
            }

            $courier = Courier::query()->active()->find($courierId);
            if ($courier === null) {
                throw ValidationException::withMessages([
                    'courier_id' => ['Courier is invalid or inactive.'],
                ]);
            }

            $service = CourierService::query()
                ->where('courier_id', $courier->id)
                ->active()
                ->find($courierServiceId);

            if ($service === null) {
                throw ValidationException::withMessages([
                    'courier_service_id' => ['Courier service is invalid for the selected courier.'],
                ]);
            }

            $city = CourierCity::query()
                ->where('courier_id', $courier->id)
                ->active()
                ->find($courierCityId);

            if ($city === null) {
                throw ValidationException::withMessages([
                    'courier_city_id' => ['Courier city is invalid for the selected courier.'],
                ]);
            }

            $shipment->update([
                'courier_id' => $courier->id,
                'courier_service_id' => $service->id,
                'courier_city_id' => $city->id,
            ]);

            $order->update(['updated_by' => $updatedBy]);

            return $shipment->fresh(['courier', 'service', 'city']);
        });
    }

    /**
     * Domain write path for tracking mutations. Locked after booking.
     */
    public function updateTrackingNumber(
        Order $order,
        string $trackingNumber,
        ?int $updatedBy = null,
        ?int $actorCompanyId = null,
    ): Shipment {
        return DB::transaction(function () use ($order, $trackingNumber, $updatedBy, $actorCompanyId) {
            $order = Order::query()->with('shipment')->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->shipmentLockService->assertShipmentMutable($order);

            $shipment = $order->shipment;

            if ($shipment === null) {
                throw ValidationException::withMessages([
                    'shipment' => ['Order does not have a shipment to update.'],
                ]);
            }

            $shipment->update([
                'tracking_number' => $trackingNumber,
            ]);

            $order->update(['updated_by' => $updatedBy]);

            return $shipment->fresh();
        });
    }

    public function assertOrderItemsMutable(Order $order): void
    {
        $this->shipmentLockService->assertOrderItemsMutable($order);
    }

    public function assertShipmentMutable(Order $order): void
    {
        $this->shipmentLockService->assertShipmentMutable($order);
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>|null
     */
    private function sanitizeRawResponse(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $blocked = [
            'api_key',
            'apikey',
            'token',
            'access_token',
            'password',
            'secret',
            'credentials',
            'authorization',
            'auth',
        ];

        $clean = [];

        foreach ($raw as $key => $value) {
            $normalized = strtolower((string) $key);

            if (in_array($normalized, $blocked, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeRawResponse($value) ?? [];
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
