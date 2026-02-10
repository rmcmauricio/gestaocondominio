<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Plan;
use App\Models\Fraction;
use App\Models\SubscriptionCondominium;

class LicenseService
{
    protected $subscriptionModel;
    protected $planModel;
    protected $fractionModel;
    protected $subscriptionCondominiumModel;

    public function __construct()
    {
        $this->subscriptionModel = new Subscription();
        $this->planModel = new Plan();
        $this->fractionModel = new Fraction();
        $this->subscriptionCondominiumModel = new SubscriptionCondominium();
    }

    /**
     * Count active licenses for a subscription
     */
    public function countActiveLicenses(int $subscriptionId): int
    {
        return $this->subscriptionModel->calculateUsedLicenses($subscriptionId);
    }

    /**
     * Count active licenses for a condominium
     */
    public function countActiveLicensesByCondominium(int $condominiumId): int
    {
        return $this->fractionModel->getActiveCountByCondominium($condominiumId);
    }

    /**
     * Validate if additional licenses can be added
     * @param int $subscriptionId Subscription ID
     * @param int $additionalLicenses Number of licenses to add
     * @param bool $isAddingExtras If true, skip minimum check (for adding extras to active subscription)
     */
    public function validateLicenseAvailability(int $subscriptionId, int $additionalLicenses, bool $isAddingExtras = false): array
    {
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            return ['available' => false, 'reason' => 'Subscrição não encontrada'];
        }

        $plan = $this->planModel->findById($subscription['plan_id']);
        if (!$plan) {
            return ['available' => false, 'reason' => 'Plano não encontrado'];
        }

        $currentLicenses = $subscription['used_licenses'] ?? 0;
        $licenseMin = (int)($plan['license_min'] ?? 0);
        // Effective limit: subscription override, then plan limit, then plan minimum (e.g. Enterprise 200)
        $licenseLimit = $subscription['license_limit'] ?? $plan['license_limit'] ?? ($licenseMin > 0 ? $licenseMin : null);
        $allowOverage = $subscription['allow_overage'] ?? false;

        // Calculate projected total
        $projectedTotal = $currentLicenses + $additionalLicenses;

        // Check minimum requirement only if NOT adding extras to an active subscription
        // When adding extras, user can add any number up to the limit (no minimum for extras)
        if (!$isAddingExtras && $licenseMin > 0 && $projectedTotal < $licenseMin) {
            return [
                'available' => false,
                'reason' => "Mínimo de {$licenseMin} licenças necessário. Projetado: {$projectedTotal}",
                'current' => $currentLicenses,
                'projected' => $projectedTotal,
                'minimum' => $licenseMin,
                'limit' => $licenseLimit,
            ];
        }

        // Check limit if set
        if ($licenseLimit !== null) {
            if ($projectedTotal > $licenseLimit && !$allowOverage) {
                return [
                    'available' => false,
                    'reason' => "Excederia o limite de {$licenseLimit} licenças. Projetado: {$projectedTotal}",
                    'current' => $currentLicenses,
                    'projected' => $projectedTotal,
                    'limit' => $licenseLimit
                ];
            }
        }

        return [
            'available' => true,
            'reason' => '',
            'current' => $currentLicenses,
            'projected' => $projectedTotal,
            'minimum' => $licenseMin,
            'limit' => $licenseLimit,
            'would_exceed' => $licenseLimit !== null && $projectedTotal > $licenseLimit
        ];
    }

    /**
     * Apply minimum charge rule
     * Returns the effective license count to charge (minimum if charge_minimum is true)
     */
    public function applyMinimumCharge(int $subscriptionId, int $usedLicenses, int $minimum): int
    {
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            return $usedLicenses;
        }

        $chargeMinimum = $subscription['charge_minimum'] ?? true;
        
        if ($chargeMinimum && $usedLicenses < $minimum) {
            return $minimum;
        }

        return $usedLicenses;
    }

    /**
     * Recalculate and update license count for subscription
     * For Base plan (condominio), applies minimum to used_licenses.
     * For Pro/Enterprise, stores actual count (minimum applied separately for pricing).
     */
    public function recalculateAndUpdate(int $subscriptionId): int
    {
        $count = $this->countActiveLicenses($subscriptionId);
        
        // For Base plan, apply minimum to used_licenses
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if ($subscription) {
            $plan = $this->planModel->findById($subscription['plan_id']);
            if ($plan && ($plan['plan_type'] ?? null) === 'condominio') {
                $licenseMin = (int)($plan['license_min'] ?? 0);
                $chargeMinimum = $subscription['charge_minimum'] ?? true;
                
                if ($chargeMinimum && $count < $licenseMin) {
                    $count = $licenseMin;
                }
            }
        }
        
        $this->subscriptionModel->updateUsedLicenses($subscriptionId, $count);
        return $count;
    }
}
