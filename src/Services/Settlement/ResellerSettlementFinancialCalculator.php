<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Enums\OrderPaymentMethod;
use Feeder\Core\Enums\OrderPaymentReviewStatus;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderItem;
use Feeder\Core\Models\OrderPaymentSubmission;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Support\Collection;

/**
 * Financial calculation contract for reseller settlements.
 *
 * Gross reseller profit before debt carry-forward =
 *   eligible order amount
 *   − historical supplier cost
 *   − configured courier charge (order snapshot)
 *   − applicable service charge (order snapshot)
 *   − company commission
 *
 * Eligible order amount (persisted on obligations as cod_amount_snapshot):
 *   - COD orders: orders.customer_payable_amount
 *   - Bank-transfer orders: amount from exactly one APPROVED BANK_TRANSFER submission
 *
 * Historical supplier cost = Σ (order_items.unit_cost_snapshot × quantity).
 * Never uses live product_variants.cost. Never uses courier remittance delivery charges.
 *
 * Legacy / incomplete snapshots return calculable=false; callers must not invent values.
 */
class ResellerSettlementFinancialCalculator
{
    public const EXCLUSION_AMBIGUOUS_APPROVED_PAYMENT = 'AMBIGUOUS_APPROVED_PAYMENT';

    public const EXCLUSION_NO_APPROVED_BANK_TRANSFER = 'NO_APPROVED_BANK_TRANSFER';

    public const EXCLUSION_INVALID_APPROVED_PAYMENT_AMOUNT = 'INVALID_APPROVED_PAYMENT_AMOUNT';

    public const EXCLUSION_INVALID_SUPPLIER_COST_SNAPSHOT = 'INVALID_SUPPLIER_COST_SNAPSHOT';

    public const EXCLUSION_MISSING_FINANCIAL_SNAPSHOTS = 'MISSING_FINANCIAL_SNAPSHOTS';

    public const ELIGIBLE_AMOUNT_SOURCE_COD = 'COD';

    public const ELIGIBLE_AMOUNT_SOURCE_BANK_TRANSFER = 'BANK_TRANSFER';

    public function __construct(
        private readonly MoneyNormalizer $money = new MoneyNormalizer(),
    ) {}

    /**
     * Resolve the eligible order amount used as the earning numerator.
     *
     * @return array{
     *     amount: string|null,
     *     source: 'COD'|'BANK_TRANSFER'|null,
     *     calculable: bool,
     *     exclusion_code: string|null,
     *     approved_submission_ids: list<int>
     * }
     */
    public function resolveEligibleOrderAmount(Order $order): array
    {
        $bankTransfers = $this->bankTransferSubmissions($order);

        if ($bankTransfers->isEmpty()) {
            $cod = $this->nullableMoney($order->customer_payable_amount);

            if ($cod === null) {
                return [
                    'amount' => null,
                    'source' => self::ELIGIBLE_AMOUNT_SOURCE_COD,
                    'calculable' => false,
                    'exclusion_code' => self::EXCLUSION_MISSING_FINANCIAL_SNAPSHOTS,
                    'approved_submission_ids' => [],
                ];
            }

            return [
                'amount' => $cod,
                'source' => self::ELIGIBLE_AMOUNT_SOURCE_COD,
                'calculable' => true,
                'exclusion_code' => null,
                'approved_submission_ids' => [],
            ];
        }

        $approved = $bankTransfers
            ->filter(fn (OrderPaymentSubmission $submission): bool => $this->isApprovedBankTransfer($submission))
            ->values();

        $approvedIds = $approved
            ->map(static fn (OrderPaymentSubmission $submission): int => (int) $submission->id)
            ->all();

        if ($approved->isEmpty()) {
            return [
                'amount' => null,
                'source' => self::ELIGIBLE_AMOUNT_SOURCE_BANK_TRANSFER,
                'calculable' => false,
                'exclusion_code' => self::EXCLUSION_NO_APPROVED_BANK_TRANSFER,
                'approved_submission_ids' => [],
            ];
        }

        if ($approved->count() > 1) {
            return [
                'amount' => null,
                'source' => self::ELIGIBLE_AMOUNT_SOURCE_BANK_TRANSFER,
                'calculable' => false,
                'exclusion_code' => self::EXCLUSION_AMBIGUOUS_APPROVED_PAYMENT,
                'approved_submission_ids' => $approvedIds,
            ];
        }

        /** @var OrderPaymentSubmission $only */
        $only = $approved->first();
        $rawAmount = array_key_exists('amount', $only->getAttributes())
            ? $only->getAttributes()['amount']
            : $only->getRawOriginal('amount');

        $normalized = $this->money->tryNormalizeNonNegative($rawAmount);

        if ($normalized === null) {
            return [
                'amount' => null,
                'source' => self::ELIGIBLE_AMOUNT_SOURCE_BANK_TRANSFER,
                'calculable' => false,
                'exclusion_code' => self::EXCLUSION_INVALID_APPROVED_PAYMENT_AMOUNT,
                'approved_submission_ids' => $approvedIds,
            ];
        }

        return [
            'amount' => $normalized,
            'source' => self::ELIGIBLE_AMOUNT_SOURCE_BANK_TRANSFER,
            'calculable' => true,
            'exclusion_code' => null,
            'approved_submission_ids' => $approvedIds,
        ];
    }

    /**
     * Authoritative COD / eligible amount for settlement display helpers.
     *
     * Prefer resolveEligibleOrderAmount() when exclusion handling is required.
     */
    public function resolveCodAmount(Order $order): string
    {
        $resolved = $this->resolveEligibleOrderAmount($order);

        if ($resolved['calculable'] && $resolved['amount'] !== null) {
            return $resolved['amount'];
        }

        // Fail closed for callers that only need a display string: never fall back
        // from an invalid bank-transfer amount to customer_payable_amount.
        if ($resolved['source'] === self::ELIGIBLE_AMOUNT_SOURCE_BANK_TRANSFER) {
            return $this->formatMoney(0.0);
        }

        return $this->formatMoney((float) $order->customer_payable_amount);
    }

    /**
     * Order-time reseller service charge snapshot, or null when never captured.
     */
    public function resolveServiceChargeSnapshot(Order $order): ?string
    {
        return $this->nullableMoney($order->reseller_service_charge_snapshot);
    }

    /**
     * Order-time fixed courier charge snapshot, or null when never captured.
     *
     * Distinct from order.courier_fee_amount (weight-based customer shipping fee)
     * and from courier remittance Excel delivery charges.
     */
    public function resolveCourierChargeSnapshot(Order $order): ?string
    {
        return $this->nullableMoney($order->reseller_courier_charge_snapshot);
    }

    /**
     * Historical supplier cost = Σ (unit_cost_snapshot × quantity).
     *
     * Any missing/invalid snapshot makes the whole order non-calculable.
     * Valid 0.00 unit costs are allowed and contribute 0.00 to the total.
     *
     * @param  Collection<int, OrderItem>|null  $items
     * @return array{
     *     total: string|null,
     *     all_items_have_snapshot: bool,
     *     calculable: bool,
     *     exclusion_code: string|null,
     *     line_totals: list<array{
     *         order_item_id: int,
     *         unit_cost: string|null,
     *         quantity: int,
     *         line_cost: string|null
     *     }>
     * }
     */
    public function resolveSupplierCost(Order $order, ?Collection $items = null): array
    {
        $items ??= $order->relationLoaded('items')
            ? $order->items
            : $order->items()->get();

        $lineTotals = [];
        $sum = 0.0;
        $allHaveSnapshot = true;

        foreach ($items as $item) {
            $quantity = (int) $item->quantity;
            $raw = array_key_exists('unit_cost_snapshot', $item->getAttributes())
                ? $item->getAttributes()['unit_cost_snapshot']
                : $item->getRawOriginal('unit_cost_snapshot');

            $unit = $this->money->tryNormalizeNonNegative($raw);

            if ($unit === null || $quantity < 0) {
                $allHaveSnapshot = false;
                $lineTotals[] = [
                    'order_item_id' => (int) $item->id,
                    'unit_cost' => null,
                    'quantity' => $quantity,
                    'line_cost' => null,
                ];

                continue;
            }

            $line = $this->money->multiply($unit, $quantity);
            $sum = round($sum + (float) $line, 2);

            $lineTotals[] = [
                'order_item_id' => (int) $item->id,
                'unit_cost' => $unit,
                'quantity' => $quantity,
                'line_cost' => $line,
            ];
        }

        if (! $allHaveSnapshot) {
            return [
                'total' => null,
                'all_items_have_snapshot' => false,
                'calculable' => false,
                'exclusion_code' => self::EXCLUSION_INVALID_SUPPLIER_COST_SNAPSHOT,
                'line_totals' => $lineTotals,
            ];
        }

        return [
            'total' => $this->formatMoney($sum),
            'all_items_have_snapshot' => true,
            'calculable' => true,
            'exclusion_code' => null,
            'line_totals' => $lineTotals,
        ];
    }

    /**
     * Sum of unit_company_commission_snapshot × quantity across order items.
     *
     * Items with null/invalid commission snapshots are skipped (legacy-safe).
     *
     * @param  Collection<int, OrderItem>|null  $items
     * @return array{
     *     total: string|null,
     *     all_items_have_snapshot: bool,
     *     line_totals: list<array{
     *         order_item_id: int,
     *         unit_commission: string|null,
     *         quantity: int,
     *         line_commission: string|null
     *     }>
     * }
     */
    public function resolveCompanyCommission(Order $order, ?Collection $items = null): array
    {
        $items ??= $order->relationLoaded('items')
            ? $order->items
            : $order->items()->get();

        $lineTotals = [];
        $sum = 0.0;
        $allHaveSnapshot = true;
        $hasAnyValid = false;

        foreach ($items as $item) {
            $unit = $this->nullableMoney($item->unit_company_commission_snapshot);
            $quantity = (int) $item->quantity;

            if ($unit === null) {
                $allHaveSnapshot = false;
                $lineTotals[] = [
                    'order_item_id' => (int) $item->id,
                    'unit_commission' => null,
                    'quantity' => $quantity,
                    'line_commission' => null,
                ];

                continue;
            }

            $line = round(((float) $unit) * $quantity, 2);
            $sum = round($sum + $line, 2);
            $hasAnyValid = true;

            $lineTotals[] = [
                'order_item_id' => (int) $item->id,
                'unit_commission' => $unit,
                'quantity' => $quantity,
                'line_commission' => $this->formatMoney($line),
            ];
        }

        return [
            'total' => ($hasAnyValid && $allHaveSnapshot) ? $this->formatMoney($sum) : ($hasAnyValid ? $this->formatMoney($sum) : null),
            'all_items_have_snapshot' => $allHaveSnapshot,
            'line_totals' => $lineTotals,
        ];
    }

    /**
     * @return array{
     *     cod_amount: string|null,
     *     eligible_amount_source: 'COD'|'BANK_TRANSFER'|null,
     *     supplier_cost: string|null,
     *     service_charge: string|null,
     *     courier_charge: string|null,
     *     company_commission: string|null,
     *     company_commission_complete: bool,
     *     gross_reseller_profit: string|null,
     *     calculable: bool,
     *     missing: list<string>,
     *     exclusion_code: string|null
     * }
     */
    public function calculateGrossResellerProfitBeforeDebt(Order $order): array
    {
        $items = $order->relationLoaded('items')
            ? $order->items
            : null;

        $eligible = $this->resolveEligibleOrderAmount($order);
        $supplierCost = $this->resolveSupplierCost($order, $items);
        $serviceCharge = $this->resolveServiceChargeSnapshot($order);
        $courierCharge = $this->resolveCourierChargeSnapshot($order);
        $commission = $this->resolveCompanyCommission($order, $items);

        $missing = [];
        $exclusionCode = null;

        if (! $eligible['calculable']) {
            $exclusionCode = $eligible['exclusion_code'];
            if ($eligible['source'] === self::ELIGIBLE_AMOUNT_SOURCE_COD) {
                $missing[] = 'customer_payable_amount';
            } elseif ($eligible['exclusion_code'] === self::EXCLUSION_NO_APPROVED_BANK_TRANSFER) {
                $missing[] = 'approved_bank_transfer';
            } elseif ($eligible['exclusion_code'] === self::EXCLUSION_AMBIGUOUS_APPROVED_PAYMENT) {
                $missing[] = 'ambiguous_approved_bank_transfer';
            } elseif ($eligible['exclusion_code'] === self::EXCLUSION_INVALID_APPROVED_PAYMENT_AMOUNT) {
                $missing[] = 'approved_bank_transfer_amount';
            }
        }

        if (! $supplierCost['calculable']) {
            $missing[] = 'unit_cost_snapshot';
            $exclusionCode ??= $supplierCost['exclusion_code'];
        }

        if ($serviceCharge === null) {
            $missing[] = 'reseller_service_charge_snapshot';
            $exclusionCode ??= self::EXCLUSION_MISSING_FINANCIAL_SNAPSHOTS;
        }

        if ($courierCharge === null) {
            $missing[] = 'reseller_courier_charge_snapshot';
            $exclusionCode ??= self::EXCLUSION_MISSING_FINANCIAL_SNAPSHOTS;
        }

        if (! $commission['all_items_have_snapshot'] || $commission['total'] === null) {
            $missing[] = 'unit_company_commission_snapshot';
            $exclusionCode ??= self::EXCLUSION_MISSING_FINANCIAL_SNAPSHOTS;
        }

        $calculable = $missing === [];

        $gross = null;

        if ($calculable) {
            $gross = $this->formatMoney(
                (float) $eligible['amount']
                - (float) $supplierCost['total']
                - (float) $courierCharge
                - (float) $serviceCharge
                - (float) $commission['total']
            );
        }

        return [
            'cod_amount' => $eligible['amount'],
            'eligible_amount_source' => $eligible['source'],
            'supplier_cost' => $supplierCost['total'],
            'service_charge' => $serviceCharge,
            'courier_charge' => $courierCharge,
            'company_commission' => $commission['total'],
            'company_commission_complete' => $commission['all_items_have_snapshot'],
            'gross_reseller_profit' => $gross,
            'calculable' => $calculable,
            'missing' => $missing,
            'exclusion_code' => $calculable ? null : $exclusionCode,
        ];
    }

    /**
     * Legacy gross used only to verify pre-Phase-1 obligations that lack supplier_cost_snapshot.
     *
     * Does not use historical supplier cost. Never used for new payable earnings.
     *
     * @return array{
     *     cod_amount: string|null,
     *     service_charge: string|null,
     *     courier_charge: string|null,
     *     company_commission: string|null,
     *     gross_reseller_profit: string|null,
     *     calculable: bool,
     *     missing: list<string>
     * }
     */
    public function calculateLegacyGrossWithoutSupplierCost(Order $order): array
    {
        $items = $order->relationLoaded('items')
            ? $order->items
            : null;

        // Pre-Phase-1 obligations always snapshotted customer_payable as COD.
        $cod = $this->nullableMoney($order->customer_payable_amount);
        $serviceCharge = $this->resolveServiceChargeSnapshot($order);
        $courierCharge = $this->resolveCourierChargeSnapshot($order);
        $commission = $this->resolveCompanyCommission($order, $items);

        $missing = [];

        if ($cod === null) {
            $missing[] = 'customer_payable_amount';
        }
        if ($serviceCharge === null) {
            $missing[] = 'reseller_service_charge_snapshot';
        }
        if ($courierCharge === null) {
            $missing[] = 'reseller_courier_charge_snapshot';
        }
        if (! $commission['all_items_have_snapshot'] || $commission['total'] === null) {
            $missing[] = 'unit_company_commission_snapshot';
        }

        $calculable = $missing === [];
        $gross = null;

        if ($calculable) {
            $gross = $this->formatMoney(
                (float) $cod
                - (float) $serviceCharge
                - (float) $courierCharge
                - (float) $commission['total']
            );
        }

        return [
            'cod_amount' => $cod,
            'service_charge' => $serviceCharge,
            'courier_charge' => $courierCharge,
            'company_commission' => $commission['total'],
            'gross_reseller_profit' => $gross,
            'calculable' => $calculable,
            'missing' => $missing,
        ];
    }

    /**
     * @return Collection<int, OrderPaymentSubmission>
     */
    private function bankTransferSubmissions(Order $order): Collection
    {
        $submissions = $order->relationLoaded('paymentSubmissions')
            ? $order->paymentSubmissions
            : $order->paymentSubmissions()->get();

        return $submissions
            ->filter(function (OrderPaymentSubmission $submission): bool {
                $method = $submission->method instanceof OrderPaymentMethod
                    ? $submission->method
                    : OrderPaymentMethod::tryFrom((string) $submission->method);

                return $method === OrderPaymentMethod::BANK_TRANSFER;
            })
            ->values();
    }

    private function isApprovedBankTransfer(OrderPaymentSubmission $submission): bool
    {
        $method = $submission->method instanceof OrderPaymentMethod
            ? $submission->method
            : OrderPaymentMethod::tryFrom((string) $submission->method);

        $review = $submission->review_status instanceof OrderPaymentReviewStatus
            ? $submission->review_status
            : OrderPaymentReviewStatus::tryFrom((string) $submission->review_status);

        return $method === OrderPaymentMethod::BANK_TRANSFER
            && $review === OrderPaymentReviewStatus::APPROVED;
    }

    private function nullableMoney(mixed $value): ?string
    {
        return $this->money->tryNormalizeNonNegative($value);
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
