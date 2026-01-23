<?php

namespace App\Middleware;

use App\Models\Subscription;
use App\Models\Condominium;
use App\Models\SubscriptionCondominium;

class SubscriptionAccessMiddleware
{
    /**
     * Check if condominium has active subscription
     */
    public static function hasActiveSubscription(int $condominiumId): bool
    {
        global $db;
        if (!$db) {
            return false;
        }

        // Check Base plan direct association
        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->getByCondominiumId($condominiumId);
        if ($subscription && in_array($subscription['status'], ['trial', 'active'])) {
            return true;
        }

        // Check Pro/Enterprise association
        $subscriptionCondominiumModel = new SubscriptionCondominium();
        $association = $subscriptionCondominiumModel->getByCondominium($condominiumId);
        if ($association) {
            $subscription = $subscriptionModel->findById($association['subscription_id']);
            if ($subscription && in_array($subscription['status'], ['trial', 'active'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if condominium is locked
     */
    public static function isCondominiumLocked(int $condominiumId): bool
    {
        $condominiumModel = new Condominium();
        return $condominiumModel->isLocked($condominiumId);
    }

    /**
     * Validate license limits before operation
     */
    public static function validateLicenseLimits(int $subscriptionId, int $additionalLicenses = 0): array
    {
        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->findById($subscriptionId);
        
        if (!$subscription) {
            return ['valid' => false, 'reason' => 'Subscrição não encontrada'];
        }

        $licenseService = new \App\Services\LicenseService();
        $validation = $licenseService->validateLicenseAvailability($subscriptionId, $additionalLicenses);
        
        return [
            'valid' => $validation['available'],
            'reason' => $validation['reason'] ?? ''
        ];
    }

    /**
     * Handle middleware check
     */
    public static function handle(int $condominiumId): bool
    {
        // Check if condominium is locked
        if (self::isCondominiumLocked($condominiumId)) {
            $_SESSION['error'] = 'Este condomínio está bloqueado. Contacte o suporte para mais informações.';
            return false;
        }

        // Check if condominium has active subscription
        if (!self::hasActiveSubscription($condominiumId)) {
            $_SESSION['error'] = 'Este condomínio não tem uma subscrição ativa.';
            return false;
        }

        return true;
    }
}
