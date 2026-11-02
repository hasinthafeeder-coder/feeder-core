<?php

namespace Feeder\Core\Tests\Unit\Settlement;

use Feeder\Core\Enums\ResellerNonDeliveryFineCondition;
use Feeder\Core\Enums\ResellerReturnReason;
use Feeder\Core\Services\Settlement\ResellerNonDeliveryFineSettingsService;
use Feeder\Core\Services\Settlement\ResellerReturnFineCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pure condition/amount selection tests (no Laravel container).
 * Full order eligibility is covered by feeder-supplier / feeder-admin feature tests.
 */
class ResellerReturnFineCalculatorTest extends TestCase
{
    private ResellerReturnFineCalculator $calculator;

    /**
     * @var array{base: string, after_hours: string, processing_override: string, combined: string}
     */
    private array $lkrSchedule = [
        'base' => '15.00',
        'after_hours' => '100.00',
        'processing_override' => '100.00',
        'combined' => '200.00',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $settings = $this->createMock(ResellerNonDeliveryFineSettingsService::class);
        $this->calculator = new ResellerReturnFineCalculator($settings);
    }

    public function test_default_non_delivery_fine_is_base_total(): void
    {
        $condition = $this->calculator->resolveCondition(false, false);

        $this->assertSame(ResellerNonDeliveryFineCondition::BASE, $condition);
        $this->assertSame('15.00', $this->calculator->amountForCondition($condition, $this->lkrSchedule));
    }

    public function test_per_reseller_base_override_applies_only_to_base(): void
    {
        $base = $this->calculator->amountForCondition(
            ResellerNonDeliveryFineCondition::BASE,
            $this->lkrSchedule,
            '25.00',
        );
        $afterHours = $this->calculator->amountForCondition(
            ResellerNonDeliveryFineCondition::AFTER_HOURS,
            $this->lkrSchedule,
            '25.00',
        );
        $combined = $this->calculator->amountForCondition(
            ResellerNonDeliveryFineCondition::COMBINED,
            $this->lkrSchedule,
            '25.00',
        );

        $this->assertSame('25.00', $base);
        $this->assertSame('100.00', $afterHours);
        $this->assertSame('200.00', $combined);
    }

    public function test_out_of_office_hours_total_fine(): void
    {
        $condition = $this->calculator->resolveCondition(true, false);

        $this->assertSame(ResellerNonDeliveryFineCondition::AFTER_HOURS, $condition);
        $this->assertSame('100.00', $this->calculator->amountForCondition($condition, $this->lkrSchedule));
    }

    public function test_processing_order_override_total_fine(): void
    {
        $condition = $this->calculator->resolveCondition(false, true);

        $this->assertSame(ResellerNonDeliveryFineCondition::PROCESSING_OVERRIDE, $condition);
        $this->assertSame('100.00', $this->calculator->amountForCondition($condition, $this->lkrSchedule));
    }

    public function test_both_conditions_produce_combined_total_not_additive(): void
    {
        $condition = $this->calculator->resolveCondition(true, true);

        $this->assertSame(ResellerNonDeliveryFineCondition::COMBINED, $condition);
        $this->assertSame('200.00', $this->calculator->amountForCondition($condition, $this->lkrSchedule));
        $this->assertNotSame('215.00', $this->calculator->amountForCondition($condition, $this->lkrSchedule));
    }

    public function test_ordinary_return_is_base_fine_only(): void
    {
        $condition = $this->calculator->resolveCondition(false, false);

        $this->assertSame(ResellerNonDeliveryFineCondition::BASE, $condition);
        $this->assertSame('15.00', $this->calculator->amountForCondition($condition, $this->lkrSchedule));
    }

    public function test_return_reason_labels_follow_condition_mapping(): void
    {
        $this->assertSame(
            ResellerReturnReason::OTHER,
            $this->calculator->resolveReturnReason(false, false),
        );
        $this->assertSame(
            ResellerReturnReason::OUT_OF_OFFICE,
            $this->calculator->resolveReturnReason(true, false),
        );
        $this->assertSame(
            ResellerReturnReason::DUPLICATE,
            $this->calculator->resolveReturnReason(false, true),
        );
        $this->assertSame(
            ResellerReturnReason::OTHER,
            $this->calculator->resolveReturnReason(true, true),
        );
    }
}
