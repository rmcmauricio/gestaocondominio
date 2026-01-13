<?php

namespace Tests\Unit\Services;

use App\Services\FeeService;
use Tests\Helpers\TestCase;

class FeeServiceTest extends TestCase
{
    protected $feeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feeService = new FeeService();
    }

    /**
     * Test calculateFeeAmount with valid values
     */
    public function testCalculateFeeAmountWithValidValues(): void
    {
        $totalAmount = 1000.00;
        $fractionPermillage = 100.0; // 100‰
        $totalPermillage = 1000.0; // 1000‰ total

        $result = $this->feeService->calculateFeeAmount($totalAmount, $fractionPermillage, $totalPermillage);

        // 1000 * 100 / 1000 = 100
        $this->assertEquals(100.0, $result);
    }

    /**
     * Test calculateFeeAmount with different permillage values
     */
    public function testCalculateFeeAmountWithDifferentPermillage(): void
    {
        $totalAmount = 1200.00;
        $fractionPermillage = 250.0; // 250‰
        $totalPermillage = 1000.0; // 1000‰ total

        $result = $this->feeService->calculateFeeAmount($totalAmount, $fractionPermillage, $totalPermillage);

        // 1200 * 250 / 1000 = 300
        $this->assertEquals(300.0, $result);
    }

    /**
     * Test calculateFeeAmount returns zero when total permillage is zero
     */
    public function testCalculateFeeAmountReturnsZeroWhenTotalPermillageIsZero(): void
    {
        $totalAmount = 1000.00;
        $fractionPermillage = 100.0;
        $totalPermillage = 0.0;

        $result = $this->feeService->calculateFeeAmount($totalAmount, $fractionPermillage, $totalPermillage);

        $this->assertEquals(0.0, $result);
    }

    /**
     * Test calculateFeeAmount handles decimal values correctly
     */
    public function testCalculateFeeAmountHandlesDecimalValues(): void
    {
        $totalAmount = 1234.56;
        $fractionPermillage = 123.45;
        $totalPermillage = 1000.0;

        $result = $this->feeService->calculateFeeAmount($totalAmount, $fractionPermillage, $totalPermillage);

        $expected = (1234.56 * 123.45) / 1000.0;
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    /**
     * Test calculateFeeAmount with very small values
     */
    public function testCalculateFeeAmountWithSmallValues(): void
    {
        $totalAmount = 0.01;
        $fractionPermillage = 1.0;
        $totalPermillage = 1000.0;

        $result = $this->feeService->calculateFeeAmount($totalAmount, $fractionPermillage, $totalPermillage);

        $expected = (0.01 * 1.0) / 1000.0;
        $this->assertEqualsWithDelta($expected, $result, 0.0001);
    }

    /**
     * Test calculateFeeAmount with large values
     */
    public function testCalculateFeeAmountWithLargeValues(): void
    {
        $totalAmount = 1000000.00;
        $fractionPermillage = 500.0;
        $totalPermillage = 1000.0;

        $result = $this->feeService->calculateFeeAmount($totalAmount, $fractionPermillage, $totalPermillage);

        // 1000000 * 500 / 1000 = 500000
        $this->assertEquals(500000.0, $result);
    }

    /**
     * Test calculateFeeAmount handles zero fraction permillage
     */
    public function testCalculateFeeAmountHandlesZeroFractionPermillage(): void
    {
        $totalAmount = 1000.00;
        $fractionPermillage = 0.0;
        $totalPermillage = 1000.0;

        $result = $this->feeService->calculateFeeAmount($totalAmount, $fractionPermillage, $totalPermillage);

        $this->assertEquals(0.0, $result);
    }
}
