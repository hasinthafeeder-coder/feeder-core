<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResellerServiceChargeService
{
    public const DEFAULT_KEY = 'reseller_service_charge';

    public function __construct(protected SettingsService $settingsService)
    {
    }

    public function getDefaultCharge(): string
    {
        $value = $this->settingsService->get(self::DEFAULT_KEY, '75.00');

        return $this->normalizeMoneyValue((string) $value, '75.00');
    }

    public function getDefaultServiceCharge(): string
    {
        return $this->getDefaultCharge();
    }

    public function getResellerOverride(User $reseller): ?string
    {
        if ($reseller->reseller_service_charge_override === null || $reseller->reseller_service_charge_override === '') {
            return null;
        }

        return $this->normalizeMoneyValue((string) $reseller->reseller_service_charge_override, null, true);
    }

    public function getEffectiveCharge(User $reseller): string
    {
        return $this->getResellerOverride($reseller) ?? $this->getDefaultCharge();
    }

    public function setDefaultCharge(mixed $amount): string
    {
        $normalized = $this->normalizeMoneyValue($amount, '75.00');

        $this->settingsService->set(self::DEFAULT_KEY, $normalized, 'financial', 'Default reseller service charge charged per order.');

        return $normalized;
    }

    public function setResellerOverride(User $reseller, mixed $amount): User
    {
        $normalized = $this->normalizeMoneyValue($amount, null, true);

        $reseller->forceFill([
            'reseller_service_charge_override' => $normalized,
        ])->save();

        return $reseller->fresh();
    }

    public function clearResellerOverride(User $reseller): User
    {
        $reseller->forceFill([
            'reseller_service_charge_override' => null,
        ])->save();

        return $reseller->fresh();
    }

    protected function normalizeMoneyValue(mixed $amount, ?string $fallback = null, bool $allowNull = false): ?string
    {
        if ($amount === null || $amount === '') {
            if ($allowNull) {
                return null;
            }

            if ($fallback !== null) {
                return $this->normalizeMoneyValue($fallback, null, false);
            }

            return '0.00';
        }

        $string = trim((string) $amount);

        if ($string === '') {
            if ($allowNull) {
                return null;
            }

            if ($fallback !== null) {
                return $this->normalizeMoneyValue($fallback, null, false);
            }

            return '0.00';
        }

        if (! is_numeric($string)) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must be a valid numeric value.',
            ]);
        }

        $value = (float) $string;

        if ($value < 0) {
            throw ValidationException::withMessages([
                'amount' => 'The amount cannot be negative.',
            ]);
        }

        if ($value > 100000000) {
            throw ValidationException::withMessages([
                'amount' => 'The amount exceeds the supported maximum.',
            ]);
        }

        return number_format($value, 2, '.', '');
    }
}
