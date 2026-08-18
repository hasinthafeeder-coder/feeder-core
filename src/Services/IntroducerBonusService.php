<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\User;
use Feeder\Core\Services\Referral\ReferralService;
use Illuminate\Validation\ValidationException;

class IntroducerBonusService
{
    public const DEFAULT_KEY = 'introducer_bonus';

    public function __construct(
        protected SettingsService $settingsService,
        protected ReferralService $referralService,
    ) {
    }

    public function getDefaultBonus(): string
    {
        $value = $this->settingsService->get(self::DEFAULT_KEY, '50.00');

        return $this->normalizeMoneyValue((string) $value, '50.00');
    }

    public function getDefaultIntroducerBonus(): string
    {
        return $this->getDefaultBonus();
    }

    public function setDefaultBonus(mixed $amount): string
    {
        $normalized = $this->normalizeMoneyValue($amount, '50.00');

        $this->settingsService->set(self::DEFAULT_KEY, $normalized, 'financial', 'Default introducer bonus paid per eligible sale.');

        return $normalized;
    }

    public function setDefaultIntroducerBonus(mixed $amount): string
    {
        return $this->setDefaultBonus($amount);
    }

    public function resolveDirectIntroducer(User $user): ?User
    {
        return $this->referralService->getDirectIntroducer($user);
    }

    protected function normalizeMoneyValue(mixed $amount, ?string $fallback = null): string
    {
        if ($amount === null || $amount === '') {
            if ($fallback !== null) {
                return $this->normalizeMoneyValue($fallback);
            }

            return '0.00';
        }

        $string = trim((string) $amount);

        if ($string === '') {
            if ($fallback !== null) {
                return $this->normalizeMoneyValue($fallback);
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
