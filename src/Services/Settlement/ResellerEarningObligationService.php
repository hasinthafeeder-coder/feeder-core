<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Exceptions\ResellerEarningObligationException;
use Feeder\Core\Models\CourierReceiptImport;
use Feeder\Core\Models\CourierReceiptImportRow;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ResellerEarningObligation;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Services\ResellerServiceChargeService;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records auditable positive reseller earning obligations.
 *
 * Idempotent on source_idempotency_key and order_id.
 * Never creates reseller payments, mutates debt, or rewrites financial history.
 */
class ResellerEarningObligationService
{
    public function __construct(
        private readonly ResellerEarningEligibilityEvaluator $eligibility,
        private readonly ResellerSettlementFinancialCalculator $financialCalculator,
        private readonly MoneyNormalizer $money,
        private readonly FinancialDailySummaryUpdater $financialDailySummaries,
        private readonly ResellerServiceChargeService $serviceCharges,
    ) {}

    public static function earningIdempotencyKey(int $orderId): string
    {
        return 'earning:order:'.$orderId;
    }

    /**
     * Persist an earning obligation for a remittance-confirmed DELIVERED order.
     *
     * Safe to call from within an outer DB transaction (courier receipt confirm).
     *
     * @return array{
     *     created: bool,
     *     skipped: bool,
     *     skip_reason: string|null,
     *     skip_code: string|null,
     *     obligation: ResellerEarningObligation|null
     * }
     */
    public function recordEarningForConfirmedDelivery(
        Order $order,
        CourierReceiptImport $import,
        CourierReceiptImportRow $row,
        ?int $recordedBy = null,
    ): array {
        $orderId = (int) $order->id;
        $joinCallerTransaction = DB::transactionLevel() > 0;

        $run = function () use ($orderId, $import, $row, $recordedBy) {
            $locked = Order::query()
                ->with(['items', 'market', 'currency', 'shipment', 'paymentSubmissions'])
                ->lockForUpdate()
                ->find($orderId);

            if ($locked === null) {
                throw new ResellerEarningObligationException(
                    'RESELLER_EARNING_ORDER_NOT_FOUND',
                    'Order not found for earning recording.',
                    ['order_id' => $orderId],
                );
            }

            $idempotencyKey = self::earningIdempotencyKey((int) $locked->id);

            $existing = ResellerEarningObligation::query()
                ->where('source_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertExistingMatchesOrder($existing, $locked);

                return [
                    'created' => false,
                    'skipped' => false,
                    'skip_reason' => null,
                    'skip_code' => null,
                    'obligation' => $existing,
                ];
            }

            $byOrder = ResellerEarningObligation::query()
                ->where('order_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($byOrder !== null) {
                $this->assertExistingMatchesOrder($byOrder, $locked);

                return [
                    'created' => false,
                    'skipped' => false,
                    'skip_reason' => null,
                    'skip_code' => null,
                    'obligation' => $byOrder,
                ];
            }

            // Capture the configured market/reseller service charge once onto the
            // order before eligibility/financial calculation. Never rewrite an
            // existing snapshot or an already-persisted earning obligation.
            $this->ensureOrderServiceChargeSnapshot($locked);

            $evaluation = $this->eligibility->evaluate($locked, $row);

            if (! $evaluation['eligible'] || ! $evaluation['calculable']) {
                return [
                    'created' => false,
                    'skipped' => true,
                    'skip_reason' => $evaluation['exclusion_reason'],
                    'skip_code' => $evaluation['exclusion_code'],
                    'obligation' => null,
                ];
            }

            $financial = $evaluation['financial'];
            $businessDate = (string) $evaluation['business_date'];
            $currencyCode = (string) $evaluation['currency_code'];

            // Components are non-negative; gross may be zero (or theoretically negative).
            $gross = number_format((float) $financial['gross_reseller_profit'], 2, '.', '');

            try {
                $obligation = ResellerEarningObligation::query()->create([
                    'obligation_number' => $this->makeObligationNumber($locked, $businessDate),
                    'reseller_id' => (int) $locked->reseller_id,
                    'reseller_company_id' => $locked->reseller_company_id !== null
                        ? (int) $locked->reseller_company_id
                        : null,
                    'order_id' => (int) $locked->id,
                    'market_id' => (int) $locked->market_id,
                    'currency_id' => (int) $locked->currency_id,
                    'currency_code_snapshot' => $currencyCode,
                    'business_date' => $businessDate,
                    'cod_amount_snapshot' => $this->money->normalizeNonNegative(
                        $financial['cod_amount'],
                        'cod_amount_snapshot',
                    ),
                    'supplier_cost_snapshot' => $this->money->normalizeNonNegative(
                        $financial['supplier_cost'],
                        'supplier_cost_snapshot',
                    ),
                    'service_charge_snapshot' => $this->money->normalizeNonNegative(
                        $financial['service_charge'],
                        'service_charge_snapshot',
                    ),
                    'courier_charge_snapshot' => $this->money->normalizeNonNegative(
                        $financial['courier_charge'],
                        'courier_charge_snapshot',
                    ),
                    'company_commission_snapshot' => $this->money->normalizeNonNegative(
                        $financial['company_commission'],
                        'company_commission_snapshot',
                    ),
                    'gross_reseller_profit' => $gross,
                    'courier_receipt_import_id' => (int) $import->id,
                    'courier_receipt_import_row_id' => (int) $row->id,
                    'source_type' => ResellerEarningObligation::SOURCE_COURIER_RECEIPT_CONFIRMATION,
                    'source_idempotency_key' => $idempotencyKey,
                    'recorded_by' => $recordedBy,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $recovered = ResellerEarningObligation::query()
                    ->where('source_idempotency_key', $idempotencyKey)
                    ->first()
                    ?? ResellerEarningObligation::query()
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
                    'skip_code' => null,
                    'obligation' => $recovered,
                ];
            }

            $this->financialDailySummaries->recordEarningObligation($obligation);

            return [
                'created' => true,
                'skipped' => false,
                'skip_reason' => null,
                'skip_code' => null,
                'obligation' => $obligation,
            ];
        };

        return $joinCallerTransaction ? $run() : DB::transaction($run);
    }

    /**
     * Safe correction path: never silently rewrite or delete financial history.
     *
     * @return array{
     *     corrected: bool,
     *     unresolved: bool,
     *     message: string,
     *     obligation: ResellerEarningObligation|null
     * }
     */
    public function reportUnsupportedDeliveryCorrection(Order $order): array
    {
        $existing = ResellerEarningObligation::query()
            ->where('order_id', $order->id)
            ->first();

        if ($existing === null) {
            return [
                'corrected' => false,
                'unresolved' => false,
                'message' => 'No earning obligation exists; no financial correction required.',
                'obligation' => null,
            ];
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status === OrderStatus::DELIVERED) {
            return [
                'corrected' => false,
                'unresolved' => false,
                'message' => 'Order remains DELIVERED; existing earning obligation preserved.',
                'obligation' => $existing,
            ];
        }

        return [
            'corrected' => false,
            'unresolved' => true,
            'message' => 'Delivery confirmation reversal/correction would rewrite financial history. '
                .'Original earning obligation preserved; manual review required.',
            'obligation' => $existing,
        ];
    }

    /**
     * Persist the configured service charge onto an order that never captured one.
     *
     * Uses the market default (LKR 75 seeded for Sri Lanka) or the reseller's
     * market-specific override (including 0.00). Does not invent an LKR amount for
     * another market. Leaves an already-captured snapshot untouched.
     */
    private function ensureOrderServiceChargeSnapshot(Order $order): void
    {
        $raw = array_key_exists('reseller_service_charge_snapshot', $order->getAttributes())
            ? $order->getAttributes()['reseller_service_charge_snapshot']
            : $order->getRawOriginal('reseller_service_charge_snapshot');

        if ($raw !== null && $raw !== '') {
            return;
        }

        if ($order->reseller_id === null || $order->market_id === null) {
            return;
        }

        $order->loadMissing('market');
        $market = $order->market;

        if ($market === null) {
            return;
        }

        $reseller = User::query()->find((int) $order->reseller_id);
        $charge = $reseller !== null
            ? $this->serviceCharges->resolveServiceCharge($reseller, $market)
            : $this->serviceCharges->getDefaultCharge($market);

        $order->forceFill([
            'reseller_service_charge_snapshot' => $charge,
        ])->save();
    }

    /**
     * Fail closed: never silently accept a persisted obligation that conflicts with
     * the order's immutable identity or recalculated financial basis.
     *
     * Matching identity + financial snapshots remain idempotent. Conflicts throw
     * ResellerEarningObligationException so outer remittance confirmation rolls back.
     */
    private function assertExistingMatchesOrder(ResellerEarningObligation $existing, Order $order): void
    {
        if ((int) $existing->order_id !== (int) $order->id) {
            throw new ResellerEarningObligationException(
                'RESELLER_EARNING_ORDER_MISMATCH',
                'Existing earning obligation conflicts with the source order identity. '
                .'Confirmation was not applied. Manual review is required.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                ],
            );
        }

        if ((int) $existing->reseller_id !== (int) $order->reseller_id) {
            throw new ResellerEarningObligationException(
                'RESELLER_EARNING_RESELLER_MISMATCH',
                'Existing earning obligation is attributed to a different reseller than the order. '
                .'Confirmation was not applied. Manual review is required.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                    'obligation_reseller_id' => $existing->reseller_id,
                    'order_reseller_id' => $order->reseller_id,
                ],
            );
        }

        if ((int) $existing->market_id !== (int) $order->market_id) {
            throw new ResellerEarningObligationException(
                'RESELLER_EARNING_MARKET_MISMATCH',
                'Existing earning obligation market does not match the order market. '
                .'Confirmation was not applied. Manual review is required.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                    'obligation_market_id' => $existing->market_id,
                    'order_market_id' => $order->market_id,
                ],
            );
        }

        if ((int) $existing->currency_id !== (int) $order->currency_id) {
            throw new ResellerEarningObligationException(
                'RESELLER_EARNING_CURRENCY_MISMATCH',
                'Existing earning obligation currency does not match the order currency. '
                .'Confirmation was not applied. Manual review is required.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                    'obligation_currency_id' => $existing->currency_id,
                    'order_currency_id' => $order->currency_id,
                ],
            );
        }

        $order->loadMissing(['items', 'currency', 'paymentSubmissions']);
        $currencyCode = strtoupper(trim((string) (
            $order->currency_code_snapshot
            ?: $order->currency?->iso_code
            ?: ''
        )));
        $obligationCurrency = strtoupper(trim((string) $existing->currency_code_snapshot));

        if ($currencyCode !== '' && $obligationCurrency !== $currencyCode) {
            throw new ResellerEarningObligationException(
                'RESELLER_EARNING_CURRENCY_CODE_MISMATCH',
                'Existing earning obligation currency code does not match the order. '
                .'Confirmation was not applied. Manual review is required.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                ],
            );
        }

        // Pre-Phase-1 obligations have null supplier_cost_snapshot. Preserve them:
        // verify against the legacy COD−service−courier−commission basis only, and
        // never invent a historical supplier cost of zero.
        $rawSupplierCost = array_key_exists('supplier_cost_snapshot', $existing->getAttributes())
            ? $existing->getAttributes()['supplier_cost_snapshot']
            : $existing->getRawOriginal('supplier_cost_snapshot');

        if ($rawSupplierCost === null || $rawSupplierCost === '') {
            $this->assertLegacyObligationFinancialBasis($existing, $order);

            return;
        }

        $financial = $this->financialCalculator->calculateGrossResellerProfitBeforeDebt($order);

        if (! $financial['calculable'] || $financial['gross_reseller_profit'] === null) {
            throw new ResellerEarningObligationException(
                'RESELLER_EARNING_FINANCIAL_BASIS_UNVERIFIABLE',
                'Existing earning obligation cannot be verified because required order financial '
                .'snapshots are incomplete. Confirmation was not applied. Manual review is required.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                    'missing' => $financial['missing'],
                ],
            );
        }

        $comparisons = [
            'cod_amount' => [
                number_format((float) $existing->cod_amount_snapshot, 2, '.', ''),
                (string) $financial['cod_amount'],
            ],
            'supplier_cost' => [
                number_format((float) $existing->supplier_cost_snapshot, 2, '.', ''),
                (string) $financial['supplier_cost'],
            ],
            'service_charge' => [
                number_format((float) $existing->service_charge_snapshot, 2, '.', ''),
                (string) $financial['service_charge'],
            ],
            'courier_charge' => [
                number_format((float) $existing->courier_charge_snapshot, 2, '.', ''),
                (string) $financial['courier_charge'],
            ],
            'company_commission' => [
                number_format((float) $existing->company_commission_snapshot, 2, '.', ''),
                (string) $financial['company_commission'],
            ],
            'gross_reseller_profit' => [
                number_format((float) $existing->gross_reseller_profit, 2, '.', ''),
                (string) $financial['gross_reseller_profit'],
            ],
        ];

        $mismatchedFields = [];
        foreach ($comparisons as $field => [$persisted, $recalculated]) {
            if ($persisted !== $recalculated) {
                $mismatchedFields[] = $field;
            }
        }

        if ($mismatchedFields !== []) {
            throw new ResellerEarningObligationException(
                'RESELLER_EARNING_GROSS_MISMATCH',
                'Existing earning obligation financial amounts conflict with the current order '
                .'financial basis. The original obligation was not changed. Confirmation was not '
                .'applied. Manual review is required.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                    'mismatched_fields' => $mismatchedFields,
                ],
            );
        }
    }

    /**
     * Safe verification for obligations recorded before supplier_cost_snapshot existed.
     */
    private function assertLegacyObligationFinancialBasis(
        ResellerEarningObligation $existing,
        Order $order,
    ): void {
        $financial = $this->financialCalculator->calculateLegacyGrossWithoutSupplierCost($order);

        if (! $financial['calculable'] || $financial['gross_reseller_profit'] === null) {
            throw new ResellerEarningObligationException(
                'RESELLER_EARNING_FINANCIAL_BASIS_UNVERIFIABLE',
                'Legacy earning obligation (null supplier_cost_snapshot) cannot be verified because '
                .'required order financial snapshots are incomplete. Confirmation was not applied. '
                .'Manual review is required. Historical supplier cost was not invented as zero.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                    'missing' => $financial['missing'],
                    'supplier_cost_snapshot' => null,
                ],
            );
        }

        $comparisons = [
            'cod_amount' => [
                number_format((float) $existing->cod_amount_snapshot, 2, '.', ''),
                (string) $financial['cod_amount'],
            ],
            'service_charge' => [
                number_format((float) $existing->service_charge_snapshot, 2, '.', ''),
                (string) $financial['service_charge'],
            ],
            'courier_charge' => [
                number_format((float) $existing->courier_charge_snapshot, 2, '.', ''),
                (string) $financial['courier_charge'],
            ],
            'company_commission' => [
                number_format((float) $existing->company_commission_snapshot, 2, '.', ''),
                (string) $financial['company_commission'],
            ],
            'gross_reseller_profit' => [
                number_format((float) $existing->gross_reseller_profit, 2, '.', ''),
                (string) $financial['gross_reseller_profit'],
            ],
        ];

        $mismatchedFields = [];
        foreach ($comparisons as $field => [$persisted, $recalculated]) {
            if ($persisted !== $recalculated) {
                $mismatchedFields[] = $field;
            }
        }

        if ($mismatchedFields !== []) {
            throw new ResellerEarningObligationException(
                'RESELLER_EARNING_GROSS_MISMATCH',
                'Legacy earning obligation financial amounts conflict with the pre-Phase-1 order '
                .'financial basis. The original obligation was not changed and supplier cost was '
                .'not backfilled. Confirmation was not applied. Manual review is required.',
                [
                    'obligation_id' => $existing->id,
                    'order_id' => $order->id,
                    'mismatched_fields' => $mismatchedFields,
                    'supplier_cost_snapshot' => null,
                ],
            );
        }
    }

    private function makeObligationNumber(Order $order, string $businessDateYmd): string
    {
        $date = str_replace('-', '', $businessDateYmd);
        $base = 'REO-'.$date.'-'.$order->id;

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
