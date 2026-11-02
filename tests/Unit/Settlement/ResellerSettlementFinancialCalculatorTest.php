<?php

namespace Feeder\Core\Tests\Unit\Settlement;

use Feeder\Core\Enums\OrderPaymentMethod;
use Feeder\Core\Enums\OrderPaymentReviewStatus;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderItem;
use Feeder\Core\Models\OrderPaymentSubmission;
use Feeder\Core\Services\Settlement\ResellerSettlementFinancialCalculator;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class ResellerSettlementFinancialCalculatorTest extends TestCase
{
    public function test_gross_profit_deducts_historical_supplier_cost(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order([
            'customer_payable_amount' => '1000.00',
            'reseller_service_charge_snapshot' => '75.00',
            'reseller_courier_charge_snapshot' => '120.00',
            'courier_fee_amount' => '999.00',
        ]);

        $items = new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 2,
                'unit_company_commission_snapshot' => '150.00',
                'unit_cost_snapshot' => '50.00',
            ]),
            new OrderItem([
                'id' => 2,
                'quantity' => 1,
                'unit_company_commission_snapshot' => '50.00',
                'unit_cost_snapshot' => '40.00',
            ]),
        ]);

        $order->setRelation('items', $items);
        $order->setRelation('paymentSubmissions', new Collection());

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        // 1000 − supplier(100+40) − courier 120 − service 75 − commission 350 = 315
        $this->assertTrue($result['calculable']);
        $this->assertSame('1000.00', $result['cod_amount']);
        $this->assertSame('COD', $result['eligible_amount_source']);
        $this->assertSame('140.00', $result['supplier_cost']);
        $this->assertSame('75.00', $result['service_charge']);
        $this->assertSame('120.00', $result['courier_charge']);
        $this->assertSame('350.00', $result['company_commission']);
        $this->assertSame('315.00', $result['gross_reseller_profit']);
        $this->assertSame([], $result['missing']);
        $this->assertNotEquals('250.00', $result['courier_charge']);
    }

    public function test_multiple_items_multiply_historical_cost_by_quantity(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order([
            'customer_payable_amount' => '2000.00',
            'reseller_service_charge_snapshot' => '0.00',
            'reseller_courier_charge_snapshot' => '0.00',
        ]);
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 3,
                'unit_company_commission_snapshot' => '10.00',
                'unit_cost_snapshot' => '25.50',
            ]),
            new OrderItem([
                'id' => 2,
                'quantity' => 2,
                'unit_company_commission_snapshot' => '5.00',
                'unit_cost_snapshot' => '100.00',
            ]),
        ]));
        $order->setRelation('paymentSubmissions', new Collection());

        $cost = $calculator->resolveSupplierCost($order);
        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertTrue($cost['calculable']);
        $this->assertSame('76.50', $cost['line_totals'][0]['line_cost']);
        $this->assertSame('200.00', $cost['line_totals'][1]['line_cost']);
        $this->assertSame('276.50', $cost['total']);
        // 2000 − 276.50 − 0 − 0 − 40 = 1683.50
        $this->assertSame('1683.50', $result['gross_reseller_profit']);
    }

    public function test_cod_eligible_amount_uses_customer_payable(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order(['customer_payable_amount' => '880.25']);
        $order->setRelation('paymentSubmissions', new Collection());

        $resolved = $calculator->resolveEligibleOrderAmount($order);

        $this->assertTrue($resolved['calculable']);
        $this->assertSame('COD', $resolved['source']);
        $this->assertSame('880.25', $resolved['amount']);
    }

    public function test_bank_transfer_eligible_amount_uses_single_approved_submission(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order(['customer_payable_amount' => '9999.00']);
        $order->setRelation('paymentSubmissions', new Collection([
            $this->submission(1, OrderPaymentReviewStatus::APPROVED, '750.00'),
        ]));
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 1,
                'unit_company_commission_snapshot' => '50.00',
                'unit_cost_snapshot' => '100.00',
            ]),
        ]));
        $order->forceFill([
            'reseller_service_charge_snapshot' => '25.00',
            'reseller_courier_charge_snapshot' => '30.00',
        ]);

        $resolved = $calculator->resolveEligibleOrderAmount($order);
        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertTrue($resolved['calculable']);
        $this->assertSame('BANK_TRANSFER', $resolved['source']);
        $this->assertSame('750.00', $resolved['amount']);
        $this->assertSame('750.00', $result['cod_amount']);
        // 750 − 100 − 30 − 25 − 50 = 545
        $this->assertSame('545.00', $result['gross_reseller_profit']);
        $this->assertNotSame('9999.00', $result['cod_amount']);
    }

    public function test_approved_bank_transfer_amount_is_used_without_counting_cod_twice(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order(['customer_payable_amount' => '1500.00']);
        $order->setRelation('paymentSubmissions', new Collection([
            $this->submission(1, OrderPaymentReviewStatus::APPROVED, '1000.00'),
        ]));
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 1,
                'unit_company_commission_snapshot' => '50.00',
                'unit_cost_snapshot' => '400.00',
            ]),
        ]));
        $order->forceFill([
            'reseller_service_charge_snapshot' => '50.00',
            'reseller_courier_charge_snapshot' => '100.00',
        ]);

        $resolved = $calculator->resolveEligibleOrderAmount($order);
        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertTrue($resolved['calculable']);
        $this->assertSame('BANK_TRANSFER', $resolved['source']);
        $this->assertSame('1000.00', $resolved['amount']);
        $this->assertSame('1000.00', $result['cod_amount']);
        $this->assertSame('400.00', $result['supplier_cost']);
        $this->assertSame('100.00', $result['courier_charge']);
        $this->assertSame('50.00', $result['service_charge']);
        $this->assertSame('50.00', $result['company_commission']);
        $this->assertSame('400.00', $result['gross_reseller_profit']);
        $this->assertNotSame('1500.00', $result['cod_amount']);

        $formulaA = number_format(1000.00 - 100.00 - 400.00 - 400.00, 2, '.', '');
        $this->assertSame('100.00', $formulaA);
        $this->assertSame('300.00', number_format((float) $result['gross_reseller_profit'] - (float) $formulaA, 2, '.', ''));
    }

    public function test_pending_bank_transfer_without_approved_is_ineligible(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order(['customer_payable_amount' => '1000.00']);
        $order->setRelation('paymentSubmissions', new Collection([
            $this->submission(1, OrderPaymentReviewStatus::PENDING_REVIEW, '1000.00'),
        ]));
        $order->setRelation('items', $this->calculableItems());
        $order->forceFill([
            'reseller_service_charge_snapshot' => '10.00',
            'reseller_courier_charge_snapshot' => '10.00',
        ]);

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertFalse($result['calculable']);
        $this->assertSame('BANK_TRANSFER', $result['eligible_amount_source']);
        $this->assertSame(
            ResellerSettlementFinancialCalculator::EXCLUSION_NO_APPROVED_BANK_TRANSFER,
            $result['exclusion_code']
        );
        $this->assertNull($result['cod_amount']);
        $this->assertNull($result['gross_reseller_profit']);
        $this->assertNotSame('1000.00', $result['cod_amount']);
    }

    public function test_rejected_bank_transfer_without_approved_is_ineligible(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order(['customer_payable_amount' => '1000.00']);
        $order->setRelation('paymentSubmissions', new Collection([
            $this->submission(1, OrderPaymentReviewStatus::REJECTED, '1000.00'),
        ]));
        $order->setRelation('items', $this->calculableItems());
        $order->forceFill([
            'reseller_service_charge_snapshot' => '10.00',
            'reseller_courier_charge_snapshot' => '10.00',
        ]);

        $resolved = $calculator->resolveEligibleOrderAmount($order);
        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertFalse($resolved['calculable']);
        $this->assertSame('BANK_TRANSFER', $resolved['source']);
        $this->assertSame(
            ResellerSettlementFinancialCalculator::EXCLUSION_NO_APPROVED_BANK_TRANSFER,
            $resolved['exclusion_code']
        );
        $this->assertSame([], $resolved['approved_submission_ids']);
        $this->assertFalse($result['calculable']);
        $this->assertSame(
            ResellerSettlementFinancialCalculator::EXCLUSION_NO_APPROVED_BANK_TRANSFER,
            $result['exclusion_code']
        );
        $this->assertNull($result['cod_amount']);
        $this->assertNull($result['gross_reseller_profit']);
        $this->assertNotSame('1000.00', $result['cod_amount']);
    }

    public function test_multiple_approved_bank_transfers_are_ambiguous(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order(['customer_payable_amount' => '1000.00']);
        $order->setRelation('paymentSubmissions', new Collection([
            $this->submission(1, OrderPaymentReviewStatus::APPROVED, '500.00'),
            $this->submission(2, OrderPaymentReviewStatus::APPROVED, '500.00'),
        ]));
        $order->setRelation('items', $this->calculableItems());
        $order->forceFill([
            'reseller_service_charge_snapshot' => '10.00',
            'reseller_courier_charge_snapshot' => '10.00',
        ]);

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertFalse($result['calculable']);
        $this->assertSame(
            ResellerSettlementFinancialCalculator::EXCLUSION_AMBIGUOUS_APPROVED_PAYMENT,
            $result['exclusion_code']
        );
        $this->assertNull($result['gross_reseller_profit']);
    }

    public function test_invalid_approved_transfer_amount_does_not_fall_back_to_cod(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order(['customer_payable_amount' => '1000.00']);
        $order->setRelation('paymentSubmissions', new Collection([
            $this->submission(1, OrderPaymentReviewStatus::APPROVED, null),
        ]));
        $order->setRelation('items', $this->calculableItems());
        $order->forceFill([
            'reseller_service_charge_snapshot' => '10.00',
            'reseller_courier_charge_snapshot' => '10.00',
        ]);

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertFalse($result['calculable']);
        $this->assertSame(
            ResellerSettlementFinancialCalculator::EXCLUSION_INVALID_APPROVED_PAYMENT_AMOUNT,
            $result['exclusion_code']
        );
        $this->assertNull($result['cod_amount']);
        $this->assertNotSame('1000.00', $result['cod_amount']);
    }

    public function test_missing_supplier_cost_on_any_item_makes_order_non_calculable(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order([
            'customer_payable_amount' => '500.00',
            'reseller_service_charge_snapshot' => '75.00',
            'reseller_courier_charge_snapshot' => '50.00',
        ]);
        $order->setRelation('paymentSubmissions', new Collection());
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 1,
                'unit_company_commission_snapshot' => '20.00',
                'unit_cost_snapshot' => '10.00',
            ]),
            new OrderItem([
                'id' => 2,
                'quantity' => 1,
                'unit_company_commission_snapshot' => '20.00',
                'unit_cost_snapshot' => null,
            ]),
        ]));

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertFalse($result['calculable']);
        $this->assertSame(
            ResellerSettlementFinancialCalculator::EXCLUSION_INVALID_SUPPLIER_COST_SNAPSHOT,
            $result['exclusion_code']
        );
        $this->assertContains('unit_cost_snapshot', $result['missing']);
        $this->assertNull($result['gross_reseller_profit']);
    }

    public function test_valid_zero_supplier_cost_snapshots_remain_calculable(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order([
            'customer_payable_amount' => '200.00',
            'reseller_service_charge_snapshot' => '10.00',
            'reseller_courier_charge_snapshot' => '20.00',
        ]);
        $order->setRelation('paymentSubmissions', new Collection());
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 2,
                'unit_company_commission_snapshot' => '15.00',
                'unit_cost_snapshot' => '0.00',
            ]),
        ]));

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertTrue($result['calculable']);
        $this->assertSame('0.00', $result['supplier_cost']);
        // 200 − 0 − 20 − 10 − 30 = 140
        $this->assertSame('140.00', $result['gross_reseller_profit']);
    }

    public function test_live_variant_cost_is_never_read_from_order_item_attributes(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $item = new OrderItem([
            'id' => 1,
            'quantity' => 1,
            'unit_company_commission_snapshot' => '10.00',
            'unit_cost_snapshot' => '40.00',
        ]);
        // Simulate a related live cost that must be ignored.
        $item->setAttribute('live_variant_cost_ignored', '999.00');

        $order = new Order([
            'customer_payable_amount' => '100.00',
            'reseller_service_charge_snapshot' => '0.00',
            'reseller_courier_charge_snapshot' => '0.00',
        ]);
        $order->setRelation('items', new Collection([$item]));
        $order->setRelation('paymentSubmissions', new Collection());

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertSame('40.00', $result['supplier_cost']);
        $this->assertSame('50.00', $result['gross_reseller_profit']);
    }

    public function test_legacy_orders_with_missing_snapshots_are_not_calculable(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order([
            'customer_payable_amount' => '500.00',
            'reseller_service_charge_snapshot' => null,
            'reseller_courier_charge_snapshot' => null,
        ]);
        $order->setRelation('paymentSubmissions', new Collection());
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 1,
                'unit_company_commission_snapshot' => '150.00',
                'unit_cost_snapshot' => '40.00',
            ]),
        ]));

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertFalse($result['calculable']);
        $this->assertNull($result['gross_reseller_profit']);
        $this->assertContains('reseller_service_charge_snapshot', $result['missing']);
        $this->assertContains('reseller_courier_charge_snapshot', $result['missing']);
    }

    public function test_courier_fee_amount_is_not_used_as_fixed_courier_charge(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order([
            'customer_payable_amount' => '800.00',
            'reseller_service_charge_snapshot' => '75.00',
            'reseller_courier_charge_snapshot' => '50.00',
            'courier_fee_amount' => '400.00',
        ]);
        $order->setRelation('paymentSubmissions', new Collection());
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 1,
                'unit_company_commission_snapshot' => '100.00',
                'unit_cost_snapshot' => '20.00',
            ]),
        ]));

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        // Uses 50 snapshot, not 400 courier_fee_amount → 800 − 20 − 50 − 75 − 100 = 555
        $this->assertSame('50.00', $result['courier_charge']);
        $this->assertSame('555.00', $result['gross_reseller_profit']);
    }

    public function test_zero_cod_and_zero_deductions_remain_calculable(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order([
            'customer_payable_amount' => '0.00',
            'reseller_service_charge_snapshot' => '0.00',
            'reseller_courier_charge_snapshot' => '0.00',
        ]);
        $order->setRelation('paymentSubmissions', new Collection());
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 2,
                'unit_company_commission_snapshot' => '0.00',
                'unit_cost_snapshot' => '0.00',
            ]),
        ]));

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertTrue($result['calculable']);
        $this->assertSame('0.00', $result['cod_amount']);
        $this->assertSame('0.00', $result['supplier_cost']);
        $this->assertSame('0.00', $result['gross_reseller_profit']);
    }

    public function test_missing_item_commission_snapshot_is_not_calculable(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order([
            'customer_payable_amount' => '500.00',
            'reseller_service_charge_snapshot' => '75.00',
            'reseller_courier_charge_snapshot' => '50.00',
        ]);
        $order->setRelation('paymentSubmissions', new Collection());
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 1,
                'unit_company_commission_snapshot' => null,
                'unit_cost_snapshot' => '10.00',
            ]),
        ]));

        $result = $calculator->calculateGrossResellerProfitBeforeDebt($order);

        $this->assertFalse($result['calculable']);
        $this->assertNull($result['gross_reseller_profit']);
        $this->assertContains('unit_company_commission_snapshot', $result['missing']);
    }

    public function test_legacy_gross_helper_omits_supplier_cost(): void
    {
        $calculator = new ResellerSettlementFinancialCalculator();

        $order = new Order([
            'customer_payable_amount' => '1000.00',
            'reseller_service_charge_snapshot' => '75.00',
            'reseller_courier_charge_snapshot' => '120.00',
        ]);
        $order->setRelation('items', new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 2,
                'unit_company_commission_snapshot' => '150.00',
                'unit_cost_snapshot' => '50.00',
            ]),
            new OrderItem([
                'id' => 2,
                'quantity' => 1,
                'unit_company_commission_snapshot' => '50.00',
                'unit_cost_snapshot' => '40.00',
            ]),
        ]));

        $legacy = $calculator->calculateLegacyGrossWithoutSupplierCost($order);

        // Pre-Phase-1: 1000 − 75 − 120 − 350 = 455 (supplier cost ignored)
        $this->assertTrue($legacy['calculable']);
        $this->assertSame('455.00', $legacy['gross_reseller_profit']);
    }

    /**
     * @return Collection<int, OrderItem>
     */
    private function calculableItems(): Collection
    {
        return new Collection([
            new OrderItem([
                'id' => 1,
                'quantity' => 1,
                'unit_company_commission_snapshot' => '10.00',
                'unit_cost_snapshot' => '10.00',
            ]),
        ]);
    }

    private function submission(int $id, OrderPaymentReviewStatus $status, mixed $amount): OrderPaymentSubmission
    {
        $submission = new OrderPaymentSubmission([
            'method' => OrderPaymentMethod::BANK_TRANSFER,
            'review_status' => $status,
            'amount' => $amount,
        ]);
        $submission->id = $id;
        $submission->syncOriginal();

        return $submission;
    }
}
