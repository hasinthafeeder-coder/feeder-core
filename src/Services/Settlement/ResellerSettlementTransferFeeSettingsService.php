<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Services\MarketService;
use Feeder\Core\Services\SettingsService;
use Illuminate\Validation\ValidationException;

/**
 * Market-scoped reseller settlement bank-transfer fee (once per payee per batch).
 *
 * Amounts are native to each market currency. Never FX-converted.
 * LKR (lk) has an approved seeded default of 30.00; other markets expose a
 * configuration gap until an explicit fee is set.
 */
class ResellerSettlementTransferFeeSettingsService
{
    public const KEY = 'reseller_settlement_transfer_fee';

    /**
     * Approved LKR default. Other markets intentionally omitted.
     *
     * @var array<string, string>
     */
    public const MARKET_DEFAULTS = [
        'lk' => '30.00',
    ];

    public function __construct(
        protected SettingsService $settingsService,
        protected MarketService $marketService,
    ) {}

    /**
     * @return array{
     *     amount: string|null,
     *     configured: bool,
     *     currency_code: string|null
     * }
     */
    public function getFee(Market|string $market): array
    {
        $market = $this->resolveMarket($market);
        $amount = $this->readAmount($market);
        $currencyCode = $this->marketCurrencyCode($market);

        return [
            'amount' => $amount,
            'configured' => $amount !== null,
            'currency_code' => $currencyCode !== '' ? $currencyCode : null,
        ];
    }

    public function hasFee(Market|string $market): bool
    {
        return $this->getFee($market)['configured'];
    }

    public function setFee(Market|string $market, mixed $amount): string
    {
        $market = $this->resolveMarket($market);
        $this->assertMarketHasActiveCurrency($market);

        $normalized = $this->normalizeRequiredMoney($amount);

        $this->settingsService->setForMarket(
            self::KEY,
            (int) $market->id,
            $normalized,
            'financial',
            "Reseller settlement bank-transfer fee (once per payee per batch) for the {$market->name} market.",
        );

        return $normalized;
    }

    /**
     * Resolve the fee snapshot to persist on a settlement invoice.
     *
     * Currency is taken from the invoice/batch market — never from UI locale.
     * Missing MYR (or any non-configured market) yields transfer_fee_not_configured
     * without inventing an LKR fee or FX conversion.
     *
     * @return array{
     *     status: string,
     *     amount: string,
     *     currency_code: string|null
     * }
     */
    public function resolveForInvoice(Market $market, string $invoiceCurrencyCode): array
    {
        $market = $this->resolveMarket($market);
        $invoiceCurrency = strtoupper(trim($invoiceCurrencyCode));
        $marketCurrency = $this->marketCurrencyCode($market);
        $configured = $this->readAmount($market);

        if (
            $configured === null
            || $invoiceCurrency === ''
            || $marketCurrency === ''
            || $invoiceCurrency !== $marketCurrency
        ) {
            return [
                'status' => ResellerSettlementInvoice::TRANSFER_FEE_STATUS_NOT_CONFIGURED,
                'amount' => '0.00',
                'currency_code' => null,
            ];
        }

        return [
            'status' => ResellerSettlementInvoice::TRANSFER_FEE_STATUS_APPLIED,
            'amount' => $configured,
            'currency_code' => $invoiceCurrency,
        ];
    }

    /**
     * Seed approved defaults for markets that define them and are not yet configured.
     */
    public function seedApprovedDefaults(): void
    {
        foreach (self::MARKET_DEFAULTS as $marketCode => $amount) {
            $market = Market::query()->where('code', $marketCode)->first();

            if ($market === null || $this->hasFee($market)) {
                continue;
            }

            $this->setFee($market, $amount);
        }
    }

    protected function resolveMarket(Market|string $market): Market
    {
        if ($market instanceof Market) {
            return $market->loadMissing('currency', 'country');
        }

        $resolved = Market::query()
            ->where('uuid', $market)
            ->orWhere('code', $market)
            ->first();

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'market' => 'The specified market could not be found.',
            ]);
        }

        return $resolved->loadMissing('currency', 'country');
    }

    protected function assertMarketHasActiveCurrency(Market $market): void
    {
        $currency = $market->currency;
        $code = $this->marketCurrencyCode($market);

        $isActive = $currency === null
            ? false
            : (($currency->is_active ?? true) === true || (int) ($currency->is_active ?? 1) === 1);

        if ($currency === null || ! $isActive || $code === '') {
            throw ValidationException::withMessages([
                'market' => "The {$market->name} market does not have a valid active currency.",
            ]);
        }
    }

    protected function marketCurrencyCode(Market $market): string
    {
        $market->loadMissing('currency');

        // Production currencies use iso_code; isolated settlement tests may use code.
        return strtoupper((string) (
            $market->currency?->iso_code
            ?? $market->currency?->code
            ?? ''
        ));
    }

    protected function readAmount(Market $market): ?string
    {
        $value = $this->settingsService->getForMarket(self::KEY, (int) $market->id);

        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeMoneyValue((string) $value);
    }

    protected function normalizeRequiredMoney(mixed $amount): string
    {
        $normalized = $this->normalizeMoneyValue($amount, allowNull: true);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'reseller_settlement_transfer_fee' => 'The transfer fee amount is required.',
            ]);
        }

        return $normalized;
    }

    protected function normalizeMoneyValue(mixed $amount, bool $allowNull = false): ?string
    {
        if ($amount === null || $amount === '') {
            return $allowNull ? null : '0.00';
        }

        $string = trim((string) $amount);

        if ($string === '') {
            return $allowNull ? null : '0.00';
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
