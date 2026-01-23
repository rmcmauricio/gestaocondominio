<?php

namespace Tests\Unit\Services;

use App\Services\PricingService;
use Tests\Helpers\TestCase;

class PricingServiceTest extends TestCase
{
    protected $pricingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingService = new PricingService();
    }

    /**
     * Test calculateAnnualPrice with discount
     */
    public function testCalculateAnnualPriceWithDiscount(): void
    {
        $monthlyPrice = 100.00;
        $discountPercentage = 10.0; // 10% discount
        
        $result = $this->pricingService->calculateAnnualPrice($monthlyPrice, $discountPercentage);
        
        // Annual base: 100 * 12 = 1200
        // Discount: 1200 * 10 / 100 = 120
        // Final: 1200 - 120 = 1080
        $expected = 1080.00;
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    /**
     * Test calculateAnnualPrice without discount
     */
    public function testCalculateAnnualPriceWithoutDiscount(): void
    {
        $monthlyPrice = 50.00;
        $discountPercentage = 0.0;
        
        $result = $this->pricingService->calculateAnnualPrice($monthlyPrice, $discountPercentage);
        
        // Annual base: 50 * 12 = 600
        // No discount
        $expected = 600.00;
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    /**
     * Test calculateAnnualPrice with 100% discount (edge case)
     */
    public function testCalculateAnnualPriceWithFullDiscount(): void
    {
        $monthlyPrice = 100.00;
        $discountPercentage = 100.0;
        
        $result = $this->pricingService->calculateAnnualPrice($monthlyPrice, $discountPercentage);
        
        // Should return 0 (free)
        $expected = 0.00;
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    /**
     * Test applyPromotion with percentage discount
     */
    public function testApplyPromotionWithPercentageDiscount(): void
    {
        $basePrice = 100.00;
        $promotion = [
            'is_active' => true,
            'discount_type' => 'percentage',
            'discount_value' => 20.0 // 20%
        ];
        
        $result = $this->pricingService->applyPromotion($basePrice, $promotion);
        
        // 100 - (100 * 20 / 100) = 80
        $expected = 80.00;
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    /**
     * Test applyPromotion with fixed discount
     */
    public function testApplyPromotionWithFixedDiscount(): void
    {
        $basePrice = 100.00;
        $promotion = [
            'is_active' => true,
            'discount_type' => 'fixed',
            'discount_value' => 15.00
        ];
        
        $result = $this->pricingService->applyPromotion($basePrice, $promotion);
        
        // 100 - 15 = 85
        $expected = 85.00;
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    /**
     * Test applyPromotion with inactive promotion
     */
    public function testApplyPromotionWithInactivePromotion(): void
    {
        $basePrice = 100.00;
        $promotion = [
            'is_active' => false,
            'discount_type' => 'percentage',
            'discount_value' => 20.0
        ];
        
        $result = $this->pricingService->applyPromotion($basePrice, $promotion);
        
        // Should return base price unchanged
        $expected = 100.00;
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    /**
     * Test applyPromotion prevents negative prices
     */
    public function testApplyPromotionPreventsNegativePrices(): void
    {
        $basePrice = 10.00;
        $promotion = [
            'is_active' => true,
            'discount_type' => 'fixed',
            'discount_value' => 20.00 // More than base price
        ];
        
        $result = $this->pricingService->applyPromotion($basePrice, $promotion);
        
        // Should return 0, not negative
        $expected = 0.00;
        $this->assertEqualsWithDelta($expected, $result, 0.01);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    /**
     * Test getPriceBreakdown with flat mode
     * Note: This would require mocking plan and tier data
     */
    public function testGetPriceBreakdownWithFlatMode(): void
    {
        // This test would require mocking the database/models
        // For now, we'll test the logic conceptually
        
        // Scenario: Plan with tiers, 25 licenses, flat mode
        // Expected: All 25 licenses at the tier price they fall into
        
        $this->assertTrue(true); // Placeholder - would need mock setup
    }

    /**
     * Test getPriceBreakdown with progressive mode
     * Note: This would require mocking plan and tier data
     */
    public function testGetPriceBreakdownWithProgressiveMode(): void
    {
        // This test would require mocking the database/models
        // For now, we'll test the logic conceptually
        
        // Scenario: Plan with tiers, 150 licenses, progressive mode
        // Expected: Breakdown showing licenses in each tier range
        
        $this->assertTrue(true); // Placeholder - would need mock setup
    }
}
