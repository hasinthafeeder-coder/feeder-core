<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\SupplierCourierAccount;
use Illuminate\Validation\ValidationException;

/**
 * Local courier eligibility and city-dataset lookups for an order.
 *
 * Couriers are eligible only when the order's supplier has an active
 * SupplierCourierAccount and the order's market has active CourierMarketPricing.
 *
 * District/city data is read exclusively from courier_cities (no external API).
 * Cities are courier-scoped in the existing schema; services belong to the courier.
 */
class OrderCourierLookupService
{
    public function __construct(
        private readonly CourierFeeCalculator $feeCalculator,
        private readonly OrderAuthorizationGuard $authorizationGuard,
    ) {
    }

    /**
     * @return list<array{id: int, uuid: string, code: string, name: string}>
     */
    public function eligibleCouriers(Order $order, ?int $actorCompanyId = null): array
    {
        $this->assertCompany($order, $actorCompanyId);

        return $this->eligibleCourierQuery($order)
            ->orderBy('name')
            ->get()
            ->map(static fn (Courier $courier) => [
                'id' => (int) $courier->id,
                'uuid' => (string) $courier->uuid,
                'code' => (string) $courier->code,
                'name' => (string) $courier->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, uuid: string, code: string, name: string}>
     */
    public function servicesForCourier(Order $order, int $courierId, ?int $actorCompanyId = null): array
    {
        $this->assertCompany($order, $actorCompanyId);
        $courier = $this->requireEligibleCourier($order, $courierId);

        return CourierService::query()
            ->where('courier_id', $courier->id)
            ->active()
            ->orderBy('name')
            ->get()
            ->map(static fn (CourierService $service) => [
                'id' => (int) $service->id,
                'uuid' => (string) $service->uuid,
                'code' => (string) $service->code,
                'name' => (string) $service->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Distinct districts from local courier_cities for the courier of the selected service.
     *
     * @return list<array{district: string}>
     */
    public function districtsForService(Order $order, int $courierServiceId, ?int $actorCompanyId = null): array
    {
        $this->assertCompany($order, $actorCompanyId);
        $service = $this->requireEligibleService($order, $courierServiceId);

        return CourierCity::query()
            ->where('courier_id', $service->courier_id)
            ->active()
            ->whereNotNull('district_name')
            ->where('district_name', '!=', '')
            ->orderBy('district_name')
            ->distinct()
            ->pluck('district_name')
            ->map(static fn ($district) => ['district' => (string) $district])
            ->values()
            ->all();
    }

    /**
     * Cities for the selected courier service's courier + district (local table only).
     *
     * @return list<array{id: int, uuid: string, city_name: string, district_name: string, external_city_code: string}>
     */
    public function citiesForServiceAndDistrict(
        Order $order,
        int $courierServiceId,
        string $district,
        ?int $actorCompanyId = null,
    ): array {
        $this->assertCompany($order, $actorCompanyId);
        $service = $this->requireEligibleService($order, $courierServiceId);
        $district = trim($district);

        if ($district === '') {
            throw ValidationException::withMessages([
                'district' => ['District is required.'],
            ]);
        }

        return CourierCity::query()
            ->where('courier_id', $service->courier_id)
            ->where('district_name', $district)
            ->active()
            ->orderBy('city_name')
            ->get()
            ->map(static fn (CourierCity $city) => [
                'id' => (int) $city->id,
                'uuid' => (string) $city->uuid,
                'city_name' => (string) $city->city_name,
                'district_name' => (string) $city->district_name,
                'external_city_code' => (string) $city->external_city_code,
            ])
            ->values()
            ->all();
    }

    /**
     * Authoritative fee preview using CourierFeeCalculator + CourierMarketPricing.
     *
     * @return array{
     *     courier_fee_amount: float,
     *     customer_payable_amount: float,
     *     items_subtotal: float,
     *     discount_amount: float,
     *     total_weight: float
     * }
     */
    public function feePreview(Order $order, int $courierId, ?int $actorCompanyId = null): array
    {
        $this->assertCompany($order, $actorCompanyId);
        $courier = $this->requireEligibleCourier($order, $courierId);
        $pricing = $this->requireMarketPricing($order, $courier);
        $fee = $this->feeCalculator->calculate($pricing, (float) $order->total_weight);

        $itemsSubtotal = (float) $order->items_subtotal;
        $discount = (float) $order->discount_amount;

        return [
            'courier_fee_amount' => $fee,
            'customer_payable_amount' => round($itemsSubtotal - $discount + $fee, 2),
            'items_subtotal' => $itemsSubtotal,
            'discount_amount' => $discount,
            'total_weight' => (float) $order->total_weight,
        ];
    }

    public function requireEligibleCourier(Order $order, int $courierId): Courier
    {
        $courier = $this->eligibleCourierQuery($order)->find($courierId);

        if ($courier === null) {
            throw ValidationException::withMessages([
                'courier_id' => ['Courier is not available for this order supplier/market.'],
            ]);
        }

        return $courier;
    }

    public function requireEligibleService(Order $order, int $courierServiceId): CourierService
    {
        $service = CourierService::query()->active()->find($courierServiceId);

        if ($service === null) {
            throw ValidationException::withMessages([
                'courier_service_id' => ['Courier service is invalid or inactive.'],
            ]);
        }

        $this->requireEligibleCourier($order, (int) $service->courier_id);

        return $service;
    }

    public function requireEligibleCity(Order $order, int $courierId, int $courierCityId): CourierCity
    {
        $this->requireEligibleCourier($order, $courierId);

        $city = CourierCity::query()
            ->where('courier_id', $courierId)
            ->active()
            ->find($courierCityId);

        if ($city === null) {
            throw ValidationException::withMessages([
                'courier_city_id' => ['Courier city is invalid for the selected courier.'],
            ]);
        }

        return $city;
    }

    public function requireMarketPricing(Order $order, Courier $courier): CourierMarketPricing
    {
        $pricing = CourierMarketPricing::query()
            ->where('courier_id', $courier->id)
            ->where('market_id', $order->market_id)
            ->active()
            ->first();

        if ($pricing === null) {
            throw ValidationException::withMessages([
                'courier_id' => ['Courier market pricing is not configured for this order market.'],
            ]);
        }

        return $pricing;
    }

    public function requireSupplierAccount(Order $order, Courier $courier): SupplierCourierAccount
    {
        $account = SupplierCourierAccount::query()
            ->where('supplier_id', $order->supplier_id)
            ->where('courier_id', $courier->id)
            ->active()
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'supplier_courier_account' => [
                    'An active supplier courier account is required before booking.',
                ],
            ]);
        }

        return $account;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Courier>
     */
    private function eligibleCourierQuery(Order $order)
    {
        return Courier::query()
            ->active()
            ->whereIn('id', function ($query) use ($order): void {
                $query->select('courier_id')
                    ->from('supplier_courier_accounts')
                    ->where('supplier_id', $order->supplier_id)
                    ->where('is_active', true);
            })
            ->whereIn('id', function ($query) use ($order): void {
                $query->select('courier_id')
                    ->from('courier_market_pricings')
                    ->where('market_id', $order->market_id)
                    ->where('is_active', true);
            });
    }

    private function assertCompany(Order $order, ?int $actorCompanyId): void
    {
        if ($actorCompanyId !== null) {
            $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
        }
    }
}
