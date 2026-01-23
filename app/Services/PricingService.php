<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanPricingTier;

class PricingService
{
    protected $planModel;
    protected $tierModel;

    public function __construct()
    {
        $this->planModel = new Plan();
        $this->tierModel = new PlanPricingTier();
    }

    /**
     * Calculate tiered price for a plan and license count
     */
    public function calculateTieredPrice(int $planId, int $licenseCount, string $mode = 'flat'): float
    {
        return $this->planModel->calculatePrice($planId, $licenseCount, $mode);
    }

    /**
     * Get detailed price breakdown by tiers
     */
    public function getPriceBreakdown(int $planId, int $licenseCount, string $mode = 'flat'): array
    {
        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            return ['total' => 0, 'breakdown' => [], 'tiers' => []];
        }

        $tiers = $this->tierModel->getByPlanId($planId, true);
        
        if (empty($tiers)) {
            // No tiers, use base price
            $basePrice = (float)($plan['price_monthly'] ?? 0);
            return [
                'total' => $basePrice,
                'breakdown' => [
                    [
                        'tier' => 'base',
                        'licenses' => $licenseCount,
                        'price_per_license' => $basePrice / max(1, $licenseCount),
                        'subtotal' => $basePrice
                    ]
                ],
                'tiers' => []
            ];
        }

        $pricingMode = $plan['pricing_mode'] ?? 'flat';
        if ($mode !== 'flat' && $mode !== 'progressive') {
            $mode = $pricingMode;
        }

        $breakdown = [];
        $total = 0.0;

        if ($mode === 'flat') {
            // Flat: all licenses at the tier price they fall into
            $tier = $this->tierModel->findTierForCount($planId, $licenseCount);
            if ($tier) {
                $pricePerLicense = (float)$tier['price_per_license'];
                $subtotal = $pricePerLicense * $licenseCount;
                $total = $subtotal;
                
                $breakdown[] = [
                    'tier' => $tier,
                    'tier_range' => $tier['min_licenses'] . ($tier['max_licenses'] ? '-' . $tier['max_licenses'] : '+'),
                    'licenses' => $licenseCount,
                    'price_per_license' => $pricePerLicense,
                    'subtotal' => $subtotal
                ];
            }
        } else {
            // Progressive: calculate by summing each tier range
            usort($tiers, function($a, $b) {
                return $a['min_licenses'] <=> $b['min_licenses'];
            });

            $remainingLicenses = $licenseCount;
            
            foreach ($tiers as $tier) {
                if ($remainingLicenses <= 0) {
                    break;
                }

                $tierMin = $tier['min_licenses'];
                $tierMax = $tier['max_licenses'] ?? PHP_INT_MAX;
                $tierPrice = (float)$tier['price_per_license'];

                if ($licenseCount >= $tierMin) {
                    // Calculate licenses in this tier
                    $licensesInTier = 0;
                    
                    if ($tierMax === null || $licenseCount <= $tierMax) {
                        // All remaining licenses fall in this tier
                        $licensesInTier = $remainingLicenses;
                    } else {
                        // Partial: calculate how many licenses in this tier range
                        $licensesInTier = min($remainingLicenses, $tierMax - max($tierMin - 1, $licenseCount - $remainingLicenses) + 1);
                    }
                    
                    if ($licensesInTier > 0) {
                        $subtotal = $tierPrice * $licensesInTier;
                        $total += $subtotal;
                        
                        $breakdown[] = [
                            'tier' => $tier,
                            'tier_range' => $tierMin . ($tierMax === null ? '+' : ($tierMax === PHP_INT_MAX ? '+' : '-' . $tierMax)),
                            'licenses' => $licensesInTier,
                            'price_per_license' => $tierPrice,
                            'subtotal' => $subtotal
                        ];
                        
                        $remainingLicenses -= $licensesInTier;
                    }
                }
            }
        }

        return [
            'total' => $total,
            'breakdown' => $breakdown,
            'tiers' => $tiers,
            'mode' => $mode,
            'license_count' => $licenseCount
        ];
    }

    /**
     * Calculate annual price with discount
     */
    public function calculateAnnualPrice(float $monthlyPrice, float $discountPercentage): float
    {
        $annualBase = $monthlyPrice * 12;
        $discount = ($annualBase * $discountPercentage) / 100;
        return $annualBase - $discount;
    }

    /**
     * Apply promotion discount to base price
     */
    public function applyPromotion(float $basePrice, array $promotion): float
    {
        if (!isset($promotion['is_active']) || !$promotion['is_active']) {
            return $basePrice;
        }

        if ($promotion['discount_type'] === 'percentage') {
            $discount = ($basePrice * $promotion['discount_value']) / 100;
            return max(0, $basePrice - $discount);
        } else {
            // Fixed discount
            return max(0, $basePrice - $promotion['discount_value']);
        }
    }
}
