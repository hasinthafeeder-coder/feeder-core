<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\ResellerDebtObligationType;
use Feeder\Core\Enums\ResellerNonDeliveryFineCondition;
use Feeder\Core\Enums\ResellerReturnReason;
use Feeder\Core\Exceptions\ResellerDebtObligationException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ResellerDebtObligation;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records auditable reseller debt obligations (non-delivery fines).
 *
 * Idempotent on source_idempotency_key and (obligation_type, order_id).
 * Never creates reseller payments, wallet rows, or Plus Report offsets.
 * Does not rewrite financial history when a return reason correction is attempted.
 */
class ResellerDebtObligationService
{
    public function __construct(
        private readonly ResellerReturnFineCalculator $fineCalculator,
        private readonly MarketBusinessDateResolver $businessDates,
        private readonly MoneyNormalizer $money,
        private readonly FinancialDailySummaryUpdater $financialDailySummaries,
    ) {}

    public static function returnFineIdempotencyKey(int $orderId): string
    {
        return 'return_fine:order:'.$orderId;
    }

    /**
     * Record a non-delivery fine for a confirmed RETURNED order.
     *
     * Amount/condition come from authoritative order flags + financial settings.
     * Safe to call from within an outer DB transaction (e.g. supplier return scan).
     *
     * @return array{
     *     created: bool,
     *     skipped: bool,
     *     skip_reason: string|null,
     *     obligation: ResellerDebtObligation|null
     * }
     */
    public function recordReturnFineForOrder(Order $order, ?int $recordedBy = null): array
    {
        $orderId = (int) $order->id;

        return DB::transaction(function () use ($orderId, $recordedBy) {
            $locked = Order::query()
                ->with(['market.currency', 'reseller'])
                ->lockForUpdate()
                ->find($orderId);

            if ($locked === null) {
                throw new ResellerDebtObligationException(
                    'RESELLER_DEBT_ORDER_NOT_FOUND',
                    'Order not found for non-delivery fine recording.',
                    ['order_id' => $orderId],
                );
            }

            $idempotencyKey = self::returnFineIdempotencyKey((int) $locked->id);

            $existing = ResellerDebtObligation::query()
                ->where('source_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertExistingMatchesOrder($existing, $locked);

                return [
                    'created' => false,
                    'skipped' => false,
                    'skip_reason' => null,
                    'obligation' => $existing,
                ];
            }

            $byType = ResellerDebtObligation::query()
                ->where('obligation_type', ResellerDebtObligationType::RETURN_FINE->value)
                ->where('order_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($byType !== null) {
                $this->assertExistingMatchesOrder($byType, $locked);

                return [
                    'created' => false,
                    'skipped' => false,
                    'skip_reason' => null,
                    'obligation' => $byType,
                ];
            }

            $resolution = $this->fineCalculator->resolveForOrder($locked);

            if (! $resolution['applicable']) {
                return [
                    'created' => false,
                    'skipped' => true,
                    'skip_reason' => $resolution['skip_reason'],
                    'obligation' => null,
                ];
            }

            $this->assertOrderResellerIntegrity($locked);

            $reason = $resolution['return_reason'] !== null
                ? ResellerReturnReason::tryFrom((string) $resolution['return_reason'])
                : null;
            $fineCondition = ResellerNonDeliveryFineCondition::from((string) $resolution['fine_condition']);
            $amount = $this->money->normalizeNonNegative($resolution['amount'], 'original_amount');
            $currencyCode = strtoupper(trim((string) $resolution['currency_code']));
            $effectiveDate = $this->resolveEffectiveDate($locked);

            try {
                $obligation = ResellerDebtObligation::query()->create([
                    'obligation_number' => $this->makeObligationNumber($locked, $effectiveDate),
                    'reseller_id' => (int) $locked->reseller_id,
                    'reseller_company_id' => $locked->reseller_company_id !== null
                        ? (int) $locked->reseller_company_id
                        : null,
                    'order_id' => (int) $locked->id,
                    'market_id' => (int) $locked->market_id,
                    'currency_id' => (int) $locked->currency_id,
                    'currency_code_snapshot' => $currencyCode,
                    'obligation_type' => ResellerDebtObligationType::RETURN_FINE,
                    'return_reason' => $reason,
                    'fine_condition' => $fineCondition,
                    'original_amount' => $amount,
                    'outstanding_amount' => $amount,
                    'effective_date' => $effectiveDate,
                    'source_type' => ResellerDebtObligation::SOURCE_SUPPLIER_RETURN_SCAN,
                    'source_idempotency_key' => $idempotencyKey,
                    'recorded_by' => $recordedBy,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $recovered = ResellerDebtObligation::query()
                    ->where('source_idempotency_key', $idempotencyKey)
                    ->first()
                    ?? ResellerDebtObligation::query()
                        ->where('obligation_type', ResellerDebtObligationType::RETURN_FINE->value)
                        ->where('order_id', $locked->id)
                        ->first();

                if ($recovered === null) {
                    throw $exception;
                }

                $this->assertExistingMatchesOrder($recovered, $locked);

                return [
                    'created' => false,
                    'skipped' => false,
                    'skip_reason' => null,
                    'obligation' => $recovered,
                ];
            }

            $this->financialDailySummaries->recordDebtObligation($obligation);

            return [
                'created' => true,
                'skipped' => false,
                'skip_reason' => null,
                'obligation' => $obligation,
            ];
        });
    }

    /**
     * Safe correction path: never silently rewrite or duplicate financial history.
     *
     * When an obligation already exists for the order, a differing reason is reported
     * as unresolved rather than mutating the original fine.
     *
     * @return array{
     *     corrected: bool,
     *     unresolved: bool,
     *     message: string,
     *     obligation: ResellerDebtObligation|null
     * }
     */
    public function reportReturnReasonCorrection(
        Order $order,
        ResellerReturnReason $proposedReason,
    ): array {
        $existing = ResellerDebtObligation::query()
            ->where('obligation_type', ResellerDebtObligationType::RETURN_FINE->value)
            ->where('order_id', $order->id)
            ->first();

        if ($existing === null) {
            return [
                'corrected' => false,
                'unresolved' => false,
                'message' => 'No return-fine obligation exists; no financial correction required.',
                'obligation' => null,
            ];
        }

        $existingReason = $existing->return_reason instanceof ResellerReturnReason
            ? $existing->return_reason
            : ResellerReturnReason::tryFrom((string) $existing->return_reason);

        if ($existingReason === $proposedReason) {
            return [
                'corrected' => false,
                'unresolved' => false,
                'message' => 'Proposed return reason matches the recorded obligation.',
                'obligation' => $existing,
            ];
        }

        return [
            'corrected' => false,
            'unresolved' => true,
            'message' => 'Return reason correction would rewrite financial history. '
                .'Original obligation preserved; manual review required.',
            'obligation' => $existing,
        ];
    }

    private function assertOrderResellerIntegrity(Order $order): void
    {
        if ($order->reseller_id === null) {
            throw new ResellerDebtObligationException(
                'RESELLER_DEBT_RESELLER_MISSING',
                'Returned order has no reseller; cannot record a return fine.',
                ['order_id' => $order->id],
            );
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status !== OrderStatus::RETURNED) {
            throw new ResellerDebtObligationException(
                'RESELLER_DEBT_ORDER_NOT_RETURNED',
                'Non-delivery fines require a RETURNED order.',
                [
                    'order_id' => $order->id,
                    'status' => $status?->value,
                ],
            );
        }
    }

    private function assertExistingMatchesOrder(ResellerDebtObligation $existing, Order $order): void
    {
        if ((int) $existing->order_id !== (int) $order->id) {
            throw new ResellerDebtObligationException(
                'RESELLER_DEBT_ORDER_MISMATCH',
                'Existing debt obligation does not match the source order.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                ],
            );
        }

        if ((int) $existing->reseller_id !== (int) $order->reseller_id) {
            throw new ResellerDebtObligationException(
                'RESELLER_DEBT_RESELLER_MISMATCH',
                'Order cannot be charged to a different reseller than the existing obligation.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                    'obligation_reseller_id' => $existing->reseller_id,
                    'order_reseller_id' => $order->reseller_id,
                ],
            );
        }
    }

    private function resolveEffectiveDate(Order $order): string
    {
        $market = $order->relationLoaded('market')
            ? $order->market
            : Market::query()->find($order->market_id);

        if ($market === null) {
            throw new ResellerDebtObligationException(
                'RESELLER_DEBT_MARKET_MISSING',
                'Order market is required to resolve the debt effective date.',
                ['order_id' => $order->id, 'market_id' => $order->market_id],
            );
        }

        $at = $order->returned_at ?? now();

        return $this->businessDates->businessDateString($market, $at);
    }

    private function makeObligationNumber(Order $order, string $effectiveDateYmd): string
    {
        $date = str_replace('-', '', $effectiveDateYmd);
        $base = 'RDO-RF-'.$date.'-'.$order->id;

        return Str::limit($base, 40, '');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $driverCode === 1062
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
