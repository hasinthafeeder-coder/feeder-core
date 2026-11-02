<?php

namespace Feeder\Core\Tests\Unit\Settlement;

use Feeder\Core\Services\Settlement\ResellerSettlementDebtOffsetCalculator;
use PHPUnit\Framework\TestCase;

class ResellerSettlementDebtOffsetCalculatorTest extends TestCase
{
    public function test_debt_lower_than_earnings_reduces_payable(): void
    {
        $result = (new ResellerSettlementDebtOffsetCalculator())->calculate('1000.00', '300.00');

        $this->assertSame('1000.00', $result['eligible_earnings']);
        $this->assertSame('300.00', $result['outstanding_debt']);
        $this->assertSame('700.00', $result['payable_earnings']);
        $this->assertSame('0.00', $result['remaining_debt']);
    }

    public function test_debt_greater_than_earnings_produces_zero_payable(): void
    {
        $result = (new ResellerSettlementDebtOffsetCalculator())->calculate('200.00', '350.00');

        $this->assertSame('0.00', $result['payable_earnings']);
        $this->assertSame('150.00', $result['remaining_debt']);
    }

    public function test_zero_earnings_preserve_outstanding_debt(): void
    {
        $result = (new ResellerSettlementDebtOffsetCalculator())->calculate('0.00', '150.00');

        $this->assertSame('0.00', $result['payable_earnings']);
        $this->assertSame('150.00', $result['remaining_debt']);
    }
}
