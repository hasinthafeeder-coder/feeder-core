<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\CourierState;
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

        return $this->serializeCouriers(
            $this->eligibleCourierQueryForSupplierMarket(
                (int) $order->supplier_id,
                (int) $order->market_id,
            )->orderBy('name')->get()
        );
    }

    /**
     * Create-form lookup before an order row exists.
     *
     * @return list<array{id: int, uuid: string, code: string, name: string}>
     */
    public function eligibleCouriersForSupplierMarket(int $supplierId, int $marketId): array
    {
        return $this->serializeCouriers(
            $this->eligibleCourierQueryForSupplierMarket($supplierId, $marketId)
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * @return list<array{id: int, uuid: string, code: string, name: string}>
     */
    public function servicesForCourier(Order $order, int $courierId, ?int $actorCompanyId = null): array
    {
        $this->assertCompany($order, $actorCompanyId);
        $this->requireEligibleCourier($order, $courierId);

        return $this->serializeServices($courierId);
    }

    /**
     * @return list<array{id: int, uuid: string, code: string, name: string}>
     */
    public function servicesForSupplierMarketCourier(int $supplierId, int $marketId, int $courierId): array
    {
        $this->requireEligibleCourierForSupplierMarket($supplierId, $marketId, $courierId);

        return $this->serializeServices($courierId);
    }

    /**
     * Provider states/districts for the courier of the selected service.
     *
     * @return list<array{id: int, uuid: string, name: string, district: string, external_id: string}>
     */
    public function districtsForService(Order $order, int $courierServiceId, ?int $actorCompanyId = null): array
    {
        $this->assertCompany($order, $actorCompanyId);
        $service = $this->requireEligibleService($order, $courierServiceId);

        return $this->districtsForCourierId((int) $service->courier_id);
    }

    /**
     * @return list<array{id: int, uuid: string, name: string, district: string, external_id: string}>
     */
    public function districtsForCourier(int $courierId): array
    {
        return $this->districtsForCourierId($courierId);
    }

    /**
     * Cities for the selected courier service's courier + district (local table only).
     *
     * @return list<array{id: int, uuid: string, city_name: string, district_name: string, external_city_code: string, courier_state_id: int|null}>
     */
    public function citiesForServiceAndDistrict(
        Order $order,
        int $courierServiceId,
        string $district,
        ?int $actorCompanyId = null,
        ?int $courierStateId = null,
    ): array {
        $this->assertCompany($order, $actorCompanyId);
        $service = $this->requireEligibleService($order, $courierServiceId);

        if ($courierStateId !== null && $courierStateId > 0) {
            return $this->citiesForCourierAndState((int) $service->courier_id, $courierStateId);
        }

        return $this->citiesForCourierAndDistrictName((int) $service->courier_id, $district);
    }

    /**
     * @return list<array{id: int, uuid: string, city_name: string, district_name: string, external_city_code: string, courier_state_id: int|null}>
     */
    public function citiesForCourierAndDistrict(int $courierId, string $district): array
    {
        return $this->citiesForCourierAndDistrictName($courierId, $district);
    }

    /**
     * @return list<array{id: int, uuid: string, city_name: string, district_name: string, external_city_code: string, courier_state_id: int|null}>
     */
    public function citiesForCourierAndState(int $courierId, int $courierStateId): array
    {
        $state = CourierState::query()
            ->where('courier_id', $courierId)
            ->active()
            ->find($courierStateId);

        if ($state === null) {
            throw ValidationException::withMessages([
                'courier_state_id' => ['State/district is invalid for the selected courier.'],
            ]);
        }

        return CourierCity::query()
            ->where('courier_id', $courierId)
            ->where('courier_state_id', $state->id)
            ->active()
            ->orderBy('city_name')
            ->get()
            ->map(fn (CourierCity $city) => $this->serializeCity($city))
            ->values()
            ->all();
    }

    public function findCityForCourierDistrictAndName(
        int $courierId,
        string $districtName,
        string $cityName,
    ): ?CourierCity {
        $districtName = trim($districtName);
        $cityName = trim($cityName);

        if ($districtName === '' || $cityName === '') {
            return null;
        }

        return CourierCity::query()
            ->where('courier_id', $courierId)
            ->where('district_name', $districtName)
            ->where('city_name', $cityName)
            ->active()
            ->first();
    }

    public function firstActiveServiceForCourier(int $courierId): ?CourierService
    {
        return CourierService::query()
            ->where('courier_id', $courierId)
            ->active()
            ->orderBy('name')
            ->first();
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

    /**
     * Create-form fee preview before an order exists.
     *
     * @return array{
     *     courier_fee_amount: float,
     *     customer_payable_amount: float,
     *     items_subtotal: float,
     *     discount_amount: float,
     *     total_weight: float
     * }
     */
    public function feePreviewForSupplierMarket(
        int $supplierId,
        int $marketId,
        int $courierId,
        float $weightKg,
        float $itemsSubtotal,
        float $discountAmount,
    ): array {
        $courier = $this->requireEligibleCourierForSupplierMarket($supplierId, $marketId, $courierId);
        $pricing = CourierMarketPricing::query()
            ->where('courier_id', $courier->id)
            ->where('market_id', $marketId)
            ->active()
            ->first();

        if ($pricing === null) {
            throw ValidationException::withMessages([
                'courier_id' => ['Courier market pricing is not configured for this order market.'],
            ]);
        }

        $fee = $this->feeCalculator->calculate($pricing, $weightKg);

        return [
            'courier_fee_amount' => $fee,
            'customer_payable_amount' => round($itemsSubtotal - $discountAmount + $fee, 2),
            'items_subtotal' => round($itemsSubtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'total_weight' => round($weightKg, 3),
        ];
    }

    public function requireEligibleCourier(Order $order, int $courierId): Courier
    {
        return $this->requireEligibleCourierForSupplierMarket(
            (int) $order->supplier_id,
            (int) $order->market_id,
            $courierId,
        );
    }

    public function requireEligibleCourierForSupplierMarket(
        int $supplierId,
        int $marketId,
        int $courierId,
    ): Courier {
        $courier = $this->eligibleCourierQueryForSupplierMarket($supplierId, $marketId)->find($courierId);

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

    public function requireServiceForCourier(int $courierId, int $courierServiceId): CourierService
    {
        $service = CourierService::query()
            ->where('courier_id', $courierId)
            ->active()
            ->find($courierServiceId);

        if ($service === null) {
            throw ValidationException::withMessages([
                'courier_service_id' => ['Courier service is invalid for the selected courier.'],
            ]);
        }

        return $service;
    }

    public function requireEligibleCity(Order $order, int $courierId, int $courierCityId): CourierCity
    {
        $this->requireEligibleCourier($order, $courierId);

        return $this->requireCityForCourier($courierId, $courierCityId);
    }

    public function requireCityForCourier(int $courierId, int $courierCityId): CourierCity
    {
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
     * @param  \Illuminate\Support\Collection<int, Courier>  $couriers
     * @return list<array{id: int, uuid: string, code: string, name: string}>
     */
    private function serializeCouriers($couriers): array
    {
        return $couriers
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
    private function serializeServices(int $courierId): array
    {
        return CourierService::query()
            ->where('courier_id', $courierId)
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
     * Prefer courier_states (provider-specific). Fall back to denormalized
     * city district names only when a courier has no state rows yet.
     *
     * @return list<array{id: int, uuid: string, name: string, district: string, external_id: string}>
     */
    private function districtsForCourierId(int $courierId): array
    {
        $states = CourierState::query()
            ->where('courier_id', $courierId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'external_id']);

        if ($states->isNotEmpty()) {
            return $states
                ->map(static fn (CourierState $state) => [
                    'id' => (int) $state->id,
                    'uuid' => (string) $state->uuid,
                    'name' => (string) $state->name,
                    'district' => (string) $state->name,
                    'external_id' => (string) $state->external_id,
                ])
                ->values()
                ->all();
        }

        return CourierCity::query()
            ->where('courier_id', $courierId)
            ->active()
            ->whereNotNull('district_name')
            ->where('district_name', '!=', '')
            ->orderBy('district_name')
            ->distinct()
            ->pluck('district_name')
            ->map(static fn ($district) => [
                'id' => 0,
                'uuid' => '',
                'name' => (string) $district,
                'district' => (string) $district,
                'external_id' => '',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, uuid: string, city_name: string, district_name: string, external_city_code: string, courier_state_id: int|null}>
     */
    private function citiesForCourierAndDistrictName(int $courierId, string $district): array
    {
        $district = trim($district);

        if ($district === '') {
            throw ValidationException::withMessages([
                'district' => ['District is required.'],
            ]);
        }

        $matchingStates = CourierState::query()
            ->where('courier_id', $courierId)
            ->where('name', $district)
            ->active()
            ->get(['id']);

        $query = CourierCity::query()
            ->where('courier_id', $courierId)
            ->active()
            ->orderBy('city_name');

        if ($matchingStates->count() === 1) {
            $query->where('courier_state_id', (int) $matchingStates->first()->id);
        } else {
            // No state rows, or duplicate state names: keep the denormalized
            // district_name filter so parent records are not mixed.
            $query->where('district_name', $district);
        }

        return $query
            ->get()
            ->map(fn (CourierCity $city) => $this->serializeCity($city))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, uuid: string, city_name: string, district_name: string, external_city_code: string, courier_state_id: int|null}
     */
    private function serializeCity(CourierCity $city): array
    {
        return [
            'id' => (int) $city->id,
            'uuid' => (string) $city->uuid,
            'city_name' => (string) ($city->city_name ?: $city->name),
            'district_name' => (string) $city->district_name,
            'external_city_code' => (string) ($city->external_city_code ?: $city->external_id),
            'courier_state_id' => $city->courier_state_id !== null ? (int) $city->courier_state_id : null,
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Courier>
     */
    private function eligibleCourierQueryForSupplierMarket(int $supplierId, int $marketId)
    {
        return Courier::query()
            ->active()
            ->whereIn('id', function ($query) use ($supplierId): void {
                $query->select('courier_id')
                    ->from('supplier_courier_accounts')
                    ->where('supplier_id', $supplierId)
                    ->where('is_active', true);
            })
            ->whereIn('id', function ($query) use ($marketId): void {
                $query->select('courier_id')
                    ->from('courier_market_pricings')
                    ->where('market_id', $marketId)
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
