<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\ResellerNonDeliveryFineCondition;
use Feeder\Core\Enums\ResellerReturnReason;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\User;

/**
 * Server-side non-delivery fine resolution for reseller Minus Report debt.
 *
 * Eligible: RETURNED (non-delivered) orders only. CANCELLED never fined.
 * Condition totals (not additive) from authoritative order flags:
 * - after_hours → out-of-office-hours
 * - duplicate_warning_overridden → processing-order override
 *
 * Ordinary returns (neither flag) receive the BASE fine only — no extra return fine.
 * Per-reseller override applies to BASE only; combination totals stay global.
 * Amounts are market-native; never FX-converted. Missing market schedule → skip.
 */
class ResellerReturnFineCalculator
{
    public function __construct(
        private readonly ResellerNonDeliveryFineSettingsService $fineSettings,
    ) {}

    /**
     * @return array{
     *     applicable: bool,
     *     amount: string|null,
     *     currency_code: string|null,
     *     fine_condition: string|null,
     *     return_reason: string|null,
     *     after_hours: bool,
     *     processing_override: bool,
     *     skip_reason: string|null
     * }
     */
    public function resolveForOrder(Order $order): array
    {
        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status === OrderStatus::CANCELLED) {
            return $this->notApplicable('cancelled_order');
        }

        if ($status === OrderStatus::DELIVERED) {
            return $this->notApplicable('delivered_order');
        }

        if ($status !== OrderStatus::RETURNED) {
            return $this->notApplicable('order_not_non_delivered');
        }

        $afterHours = (bool) $order->after_hours;
        $processingOverride = (bool) $order->duplicate_warning_overridden;
        $condition = $this->resolveCondition($afterHours, $processingOverride);

        $market = $this->resolveMarket($order);

        if ($market === null) {
            return $this->notApplicable('market_missing', $afterHours, $processingOverride, $condition);
        }

        $currencyCode = strtoupper(trim((string) (
            $order->currency_code_snapshot
            ?? $market->currency?->iso_code
            ?? ''
        )));

        if ($currencyCode === '') {
            return $this->notApplicable('currency_missing', $afterHours, $processingOverride, $condition);
        }

        $marketCurrency = strtoupper(trim((string) ($market->currency?->iso_code ?? '')));

        if ($marketCurrency !== '' && $currencyCode !== $marketCurrency) {
            return [
                'applicable' => false,
                'amount' => null,
                'currency_code' => $currencyCode,
                'fine_condition' => $condition->value,
                'return_reason' => $this->rawReturnReason($order),
                'after_hours' => $afterHours,
                'processing_override' => $processingOverride,
                'skip_reason' => 'currency_market_mismatch',
            ];
        }

        $schedule = $this->fineSettings->getSchedule($market);

        if (! $schedule['configured']) {
            return [
                'applicable' => false,
                'amount' => null,
                'currency_code' => $currencyCode,
                'fine_condition' => $condition->value,
                'return_reason' => $this->rawReturnReason($order),
                'after_hours' => $afterHours,
                'processing_override' => $processingOverride,
                'skip_reason' => 'market_fine_defaults_missing',
            ];
        }

        $amount = $this->resolveAmount($order, $market, $condition, $schedule);

        if ($amount === null) {
            return [
                'applicable' => false,
                'amount' => null,
                'currency_code' => $currencyCode,
                'fine_condition' => $condition->value,
                'return_reason' => $this->rawReturnReason($order),
                'after_hours' => $afterHours,
                'processing_override' => $processingOverride,
                'skip_reason' => 'fine_amount_unresolved',
            ];
        }

        return [
            'applicable' => true,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'fine_condition' => $condition->value,
            'return_reason' => $this->rawReturnReason($order),
            'after_hours' => $afterHours,
            'processing_override' => $processingOverride,
            'skip_reason' => null,
        ];
    }

    public function resolveCondition(bool $afterHours, bool $processingOverride): ResellerNonDeliveryFineCondition
    {
        if ($afterHours && $processingOverride) {
            return ResellerNonDeliveryFineCondition::COMBINED;
        }

        if ($afterHours) {
            return ResellerNonDeliveryFineCondition::AFTER_HOURS;
        }

        if ($processingOverride) {
            return ResellerNonDeliveryFineCondition::PROCESSING_OVERRIDE;
        }

        return ResellerNonDeliveryFineCondition::BASE;
    }

    /**
     * Operational return-reason label derived from authoritative order flags.
     *
     * COMBINED and BASE both persist OTHER (schema has no combined label).
     * Fine amounts still come from resolveCondition / schedule totals.
     */
    public function resolveReturnReason(bool $afterHours, bool $processingOverride): ResellerReturnReason
    {
        return match ($this->resolveCondition($afterHours, $processingOverride)) {
            ResellerNonDeliveryFineCondition::AFTER_HOURS => ResellerReturnReason::OUT_OF_OFFICE,
            ResellerNonDeliveryFineCondition::PROCESSING_OVERRIDE => ResellerReturnReason::DUPLICATE,
            ResellerNonDeliveryFineCondition::COMBINED,
            ResellerNonDeliveryFineCondition::BASE => ResellerReturnReason::OTHER,
        };
    }

    public function resolveReturnReasonForOrder(Order $order): ResellerReturnReason
    {
        return $this->resolveReturnReason(
            (bool) $order->after_hours,
            (bool) $order->duplicate_warning_overridden,
        );
    }

    /**
     * Pure amount selection given a complete schedule (unit-test friendly).
     *
     * @param  array{
     *     base: string,
     *     after_hours: string,
     *     processing_override: string,
     *     combined: string
     * }  $schedule
     */
    public function amountForCondition(
        ResellerNonDeliveryFineCondition $condition,
        array $schedule,
        ?string $baseOverride = null,
    ): string {
        return match ($condition) {
            ResellerNonDeliveryFineCondition::BASE => $baseOverride ?? $schedule['base'],
            ResellerNonDeliveryFineCondition::AFTER_HOURS => $schedule['after_hours'],
            ResellerNonDeliveryFineCondition::PROCESSING_OVERRIDE => $schedule['processing_override'],
            ResellerNonDeliveryFineCondition::COMBINED => $schedule['combined'],
        };
    }

    /**
     * @param  array{
     *     base: string|null,
     *     after_hours: string|null,
     *     processing_override: string|null,
     *     combined: string|null,
     *     configured: bool,
     *     missing_keys: list<string>
     * }  $schedule
     */
    private function resolveAmount(
        Order $order,
        Market $market,
        ResellerNonDeliveryFineCondition $condition,
        array $schedule,
    ): ?string {
        if (! $schedule['configured']) {
            return null;
        }

        /** @var array{base: string, after_hours: string, processing_override: string, combined: string} $complete */
        $complete = [
            'base' => (string) $schedule['base'],
            'after_hours' => (string) $schedule['after_hours'],
            'processing_override' => (string) $schedule['processing_override'],
            'combined' => (string) $schedule['combined'],
        ];

        $baseOverride = null;

        if ($condition === ResellerNonDeliveryFineCondition::BASE && $order->reseller_id !== null) {
            $reseller = $order->relationLoaded('reseller')
                ? $order->reseller
                : User::query()->find($order->reseller_id);

            if ($reseller !== null) {
                $baseOverride = $this->fineSettings->getResellerBaseOverride($reseller, $market);
            }
        }

        return $this->amountForCondition($condition, $complete, $baseOverride);
    }

    private function resolveMarket(Order $order): ?Market
    {
        if ($order->relationLoaded('market') && $order->market !== null) {
            return $order->market->loadMissing('currency');
        }

        if ($order->market_id === null) {
            return null;
        }

        return Market::query()->with('currency')->find($order->market_id);
    }

    private function rawReturnReason(Order $order): ?string
    {
        $raw = $order->return_reason;

        if ($raw === null || $raw === '') {
            return null;
        }

        return is_object($raw) && property_exists($raw, 'value')
            ? (string) $raw->value
            : (string) $raw;
    }

    /**
     * @return array{
     *     applicable: bool,
     *     amount: string|null,
     *     currency_code: string|null,
     *     fine_condition: string|null,
     *     return_reason: string|null,
     *     after_hours: bool,
     *     processing_override: bool,
     *     skip_reason: string|null
     * }
     */
    private function notApplicable(
        string $skipReason,
        bool $afterHours = false,
        bool $processingOverride = false,
        ?ResellerNonDeliveryFineCondition $condition = null,
    ): array {
        return [
            'applicable' => false,
            'amount' => null,
            'currency_code' => null,
            'fine_condition' => $condition?->value,
            'return_reason' => null,
            'after_hours' => $afterHours,
            'processing_override' => $processingOverride,
            'skip_reason' => $skipReason,
        ];
    }
}
