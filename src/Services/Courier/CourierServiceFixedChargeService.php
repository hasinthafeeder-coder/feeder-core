<?php

namespace Feeder\Core\Services\Courier;

use Feeder\Core\Models\CourierService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Admin-managed fixed charge per courier service for reseller settlements.
 *
 * One global amount per service (currently Sri Lanka market). Independent of
 * CourierMarketPricing weight-based customer fees and remittance Excel charges.
 */
class CourierServiceFixedChargeService
{
    /**
     * @return Collection<int, CourierService>
     */
    public function listServicesForConfiguration(): Collection
    {
        return CourierService::query()
            ->with('courier')
            ->orderBy('courier_id')
            ->orderBy('name')
            ->get();
    }

    public function getConfiguredCharge(CourierService $service): ?string
    {
        $raw = $service->reseller_fixed_charge;

        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->normalizeMoneyValue($raw);
    }

    public function setFixedCharge(CourierService $service, mixed $amount): string
    {
        $normalized = $this->normalizeMoneyValue($amount);

        $service->fill(['reseller_fixed_charge' => $normalized]);
        $service->save();

        return $normalized;
    }

    /**
     * Resolve the charge to snapshot for an assigned courier service.
     * Returns null when the service is missing or has no configured charge.
     */
    public function resolveChargeForServiceId(?int $courierServiceId): ?string
    {
        if ($courierServiceId === null || $courierServiceId < 1) {
            return null;
        }

        $service = CourierService::query()->find($courierServiceId);

        if ($service === null) {
            return null;
        }

        return $this->getConfiguredCharge($service);
    }

    public function normalizeMoneyValue(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'reseller_fixed_charge' => 'The fixed courier charge is required.',
            ]);
        }

        $string = trim((string) $amount);

        if ($string === '' || ! is_numeric($string)) {
            throw ValidationException::withMessages([
                'reseller_fixed_charge' => 'The fixed courier charge must be a valid numeric value.',
            ]);
        }

        $value = (float) $string;

        if ($value < 0) {
            throw ValidationException::withMessages([
                'reseller_fixed_charge' => 'The fixed courier charge cannot be negative.',
            ]);
        }

        if ($value > 100000000) {
            throw ValidationException::withMessages([
                'reseller_fixed_charge' => 'The fixed courier charge exceeds the supported maximum.',
            ]);
        }

        return number_format($value, 2, '.', '');
    }
}
