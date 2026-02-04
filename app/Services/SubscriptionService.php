<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Promotion;
use App\Models\SubscriptionCondominium;
use App\Models\Condominium;
use App\Models\Fraction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InvoiceService;
use App\Services\LicenseService;
use App\Services\PricingService;

class SubscriptionService
{
    protected $planModel;
    protected $subscriptionModel;
    protected $auditService;
    protected $invoiceService;
    protected $licenseService;
    protected $pricingService;

    public function __construct()
    {
        $this->planModel = new Plan();
        $this->subscriptionModel = new Subscription();
        $this->auditService = new AuditService();
        $this->invoiceService = new InvoiceService();
        $this->licenseService = new LicenseService();
        $this->pricingService = new PricingService();
    }

    /**
     * Start trial subscription
     * 
     * @param int $userId User ID
     * @param int $planId Plan ID
     * @param int $trialDays Number of trial days (default: 14)
     * @param int $extraCondominiums DEPRECATED: Kept for backward compatibility with old Business plan. Not used in new license-based model.
     * @param int|null $promotionId Optional promotion ID
     * @param float|null $originalPrice Optional original price for promotion
     * @param int|null $condominiumId Optional condominium ID (for license-based plans)
     * @return int Subscription ID
     */
    public function startTrial(int $userId, int $planId, int $trialDays = 14, int $extraCondominiums = 0, ?int $promotionId = null, ?float $originalPrice = null, ?int $condominiumId = null): int
    {
        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            throw new \Exception("Plan not found");
        }

        $now = date('Y-m-d H:i:s');
        $trialEndsAt = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
        $periodEnd = date('Y-m-d H:i:s', strtotime("+1 month"));

        // Handle promotion
        $promotionAppliedAt = null;
        $promotionEndsAt = null;
        $finalOriginalPrice = $originalPrice ?? $plan['price_monthly'];
        $finalPrice = $finalOriginalPrice; // Default to original price
        
        if ($promotionId) {
            $promotionModel = new Promotion();
            $promotion = $promotionModel->findById($promotionId);
            
            if ($promotion && $promotion['is_active']) {
                $promotionAppliedAt = $now;
                $durationMonths = $promotion['duration_months'] ?? null;
                
                if ($durationMonths) {
                    $promotionEndsAt = date('Y-m-d H:i:s', strtotime("+{$durationMonths} months", strtotime($now)));
                }
                
                // Calculate discounted price
                if ($promotion['discount_type'] === 'percentage') {
                    $discount = ($finalOriginalPrice * $promotion['discount_value']) / 100;
                    $finalPrice = max(0, $finalOriginalPrice - $discount);
                } else {
                    // Fixed discount
                    $finalPrice = max(0, $finalOriginalPrice - $promotion['discount_value']);
                }
            } else {
                $promotionId = null; // Invalid promotion, don't apply
            }
        }

        // For new license-based plans, extraCondominiums is not used
        // Only kept for backward compatibility with old Business plan
        $planType = $plan['plan_type'] ?? null;
        
        // If plan is license-based and condominiumId is provided, use createSubscription instead
        if ($planType && $condominiumId !== null) {
            return $this->createSubscription($userId, $planId, $condominiumId, $promotionId);
        }
        
        $subscriptionData = [
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => 'trial',
            'trial_ends_at' => $trialEndsAt,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'price_monthly' => $finalPrice // Always save price (discounted or original)
        ];

        // Only set extra_condominiums for legacy plans (Business plan without plan_type)
        if (!$planType && $plan['slug'] === 'business') {
            $subscriptionData['extra_condominiums'] = $extraCondominiums;
        }

        // For license-based plans without condominium, set minimum licenses
        if ($planType) {
            $licenseMin = (int)($plan['license_min'] ?? 0);
            $subscriptionData['used_licenses'] = $licenseMin;
            $subscriptionData['license_limit'] = $plan['license_limit'] ?? $licenseMin;
            $subscriptionData['extra_licenses'] = 0;
            $subscriptionData['allow_overage'] = $plan['allow_overage'] ?? false;
        }

        // Add promotion fields if promotion is being applied
        if ($promotionId) {
            $subscriptionData['promotion_id'] = $promotionId;
            $subscriptionData['promotion_applied_at'] = $promotionAppliedAt;
            $subscriptionData['promotion_ends_at'] = $promotionEndsAt;
            $subscriptionData['original_price_monthly'] = $finalOriginalPrice;
        }

        $subscriptionId = $this->subscriptionModel->create($subscriptionData);

        if ($subscriptionId) {
            $promoText = $promotionId ? " com promoção ID: {$promotionId}" : "";
            // Log subscription creation
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
                'action' => 'subscription_trial_started',
                'new_plan_id' => $planId,
                'new_status' => 'trial',
                'new_period_start' => $now,
                'new_period_end' => $periodEnd,
                'description' => "Período experimental iniciado. Plano ID: {$planId}. Duração: {$trialDays} dias{$promoText}"
            ]);
        }

        return $subscriptionId;
    }

    /**
     * Upgrade subscription
     */
    public function upgrade(int $userId, int $newPlanId, ?int $promotionId = null): bool
    {
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);
        if (!$subscription) {
            throw new \Exception("No active subscription found");
        }

        $newPlan = $this->planModel->findById($newPlanId);
        if (!$newPlan) {
            throw new \Exception("New plan not found");
        }

        // Calculate prorated amount if needed
        // For now, just update the plan
        
        $now = date('Y-m-d H:i:s');
        $periodEnd = date('Y-m-d H:i:s', strtotime("+1 month"));

        $oldPlanId = $subscription['plan_id'];
        $oldStatus = $subscription['status'];
        $oldPeriodStart = $subscription['current_period_start'] ?? null;
        $oldPeriodEnd = $subscription['current_period_end'] ?? null;

        // Keep the current status (don't force 'active')
        // If it's trial, keep trial; if it's active, keep active
        $newStatus = $oldStatus;

        $updateData = [
            'plan_id' => $newPlanId,
            'status' => $newStatus,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd
        ];

        // Handle promotion if provided
        if ($promotionId) {
            $promotionModel = new Promotion();
            $promotion = $promotionModel->findById($promotionId);
            
            if ($promotion && $promotion['is_active']) {
                $promotionAppliedAt = $now;
                $durationMonths = $promotion['duration_months'] ?? null;
                $promotionEndsAt = null;
                
                if ($durationMonths) {
                    $promotionEndsAt = date('Y-m-d H:i:s', strtotime("+{$durationMonths} months", strtotime($now)));
                }
                
                // Calculate discounted price
                $originalPrice = $newPlan['price_monthly'];
                $finalPrice = $originalPrice;
                if ($promotion['discount_type'] === 'percentage') {
                    $discount = ($originalPrice * $promotion['discount_value']) / 100;
                    $finalPrice = max(0, $originalPrice - $discount);
                } else {
                    // Fixed discount
                    $finalPrice = max(0, $originalPrice - $promotion['discount_value']);
                }
                
                $updateData['promotion_id'] = $promotionId;
                $updateData['promotion_applied_at'] = $promotionAppliedAt;
                $updateData['promotion_ends_at'] = $promotionEndsAt;
                $updateData['original_price_monthly'] = $originalPrice;
                $updateData['price_monthly'] = $finalPrice; // Save discounted price
            }
        } else {
            // Clear promotion fields if no promotion is being applied
            $updateData['promotion_id'] = null;
            $updateData['promotion_applied_at'] = null;
            $updateData['promotion_ends_at'] = null;
            $updateData['original_price_monthly'] = null;
            $updateData['price_monthly'] = $newPlan['price_monthly']; // Use plan price when no promotion
        }

        $success = $this->subscriptionModel->update($subscription['id'], $updateData);

        if ($success) {
            $promoText = $promotionId ? " com promoção ID: {$promotionId}" : "";
            // Log subscription upgrade/plan change
            $this->auditService->logSubscription([
                'subscription_id' => $subscription['id'],
                'user_id' => $userId,
                'action' => 'subscription_upgraded',
                'old_plan_id' => $oldPlanId,
                'new_plan_id' => $newPlanId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'old_period_start' => $oldPeriodStart,
                'new_period_start' => $now,
                'old_period_end' => $oldPeriodEnd,
                'new_period_end' => $periodEnd,
                'description' => "Subscrição atualizada. Plano: {$oldPlanId} → {$newPlanId}. Status mantido: {$oldStatus}{$promoText}"
            ]);
        }

        return $success;
    }

    /**
     * Renew subscription
     */
    public function renew(int $subscriptionId): bool
    {
        $subscription = $this->subscriptionModel->getActiveSubscription(
            $this->getUserIdFromSubscription($subscriptionId)
        );
        
        if (!$subscription) {
            return false;
        }

        // Check and expire promotions before renewal
        $this->subscriptionModel->checkAndExpirePromotions();

        $now = date('Y-m-d H:i:s');
        $periodEnd = date('Y-m-d H:i:s', strtotime("+1 month"));

        $oldStatus = $subscription['status'];
        $oldPeriodStart = $subscription['current_period_start'] ?? null;
        $oldPeriodEnd = $subscription['current_period_end'] ?? null;
        $userId = $subscription['user_id'] ?? 0;

        // Re-fetch subscription to get updated promotion status
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        
        $updateData = [
            'status' => 'active',
            'current_period_start' => $now,
            'current_period_end' => $periodEnd
        ];
        
        // If promotion expired, ensure it's cleared
        if ($subscription && isset($subscription['promotion_ends_at']) && $subscription['promotion_ends_at']) {
            if (strtotime($subscription['promotion_ends_at']) <= strtotime($now)) {
                $updateData['promotion_id'] = null;
                $updateData['promotion_applied_at'] = null;
                $updateData['promotion_ends_at'] = null;
            }
        }

        $success = $this->subscriptionModel->update($subscriptionId, $updateData);

        if ($success) {
            $promoText = '';
            if ($subscription && isset($subscription['promotion_id']) && $subscription['promotion_id']) {
                $promoText = " (com promoção ativa)";
            } elseif ($subscription && isset($subscription['promotion_ends_at']) && strtotime($subscription['promotion_ends_at']) <= strtotime($now)) {
                $promoText = " (promoção expirada, renovado ao preço original)";
            }
            
            // Log subscription renewal
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
                'action' => 'subscription_renewed',
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'old_period_start' => $oldPeriodStart,
                'new_period_start' => $now,
                'old_period_end' => $oldPeriodEnd,
                'new_period_end' => $periodEnd,
                'description' => "Subscrição renovada automaticamente. Novo período: {$now} até {$periodEnd}{$promoText}"
            ]);
        }

        return $success;
    }

    /**
     * Check subscription limits
     */
    public function checkLimits(int $userId): array
    {
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);
        
        if (!$subscription) {
            return [
                'has_subscription' => false,
                'can_create_condominium' => false,
                'can_create_fraction' => false
            ];
        }

        return [
            'has_subscription' => true,
            'plan' => $subscription,
            'can_create_condominium' => $this->subscriptionModel->canCreateCondominium($userId),
            'can_create_fraction' => true, // Will be checked per condominium
            'has_feature' => function($feature) use ($userId) {
                return $this->subscriptionModel->hasFeature($userId, $feature);
            }
        ];
    }

    /**
     * Change subscription plan - creates pending subscription instead of updating existing
     * 
     * @param int $userId User ID
     * @param int $newPlanId New plan ID
     * @param int|null $promotionId Optional promotion ID
     * @param int $extraCondominiums DEPRECATED: Kept for backward compatibility with old Business plan. Not used in new license-based model.
     * @return int Subscription ID
     */
    public function changePlan(int $userId, int $newPlanId, ?int $promotionId = null, int $extraCondominiums = 0): int
    {
        // Get active subscription (can be null if user has no active subscription)
        $activeSubscription = $this->subscriptionModel->getActiveSubscription($userId);
        
        // Check if there's already a pending subscription
        $pendingSubscription = $this->subscriptionModel->getPendingSubscription($userId);
        
        $newPlan = $this->planModel->findById($newPlanId);
        if (!$newPlan) {
            throw new \Exception("New plan not found");
        }

        $now = date('Y-m-d H:i:s');
        $periodStart = $now;
        $periodEnd = date('Y-m-d H:i:s', strtotime("+1 month"));

        // Calculate price with promotion (discount applies ONLY to base plan, not extras)
        $originalPrice = $newPlan['price_monthly'];
        $finalPrice = $originalPrice;
        $promotionAppliedAt = null;
        $promotionEndsAt = null;
        $finalPromotionId = null;

        if ($promotionId) {
            $promotionModel = new Promotion();
            $promotion = $promotionModel->findById($promotionId);
            
            if ($promotion && $promotion['is_active']) {
                $finalPromotionId = $promotionId;
                $promotionAppliedAt = $now;
                $durationMonths = $promotion['duration_months'] ?? null;
                
                if ($durationMonths) {
                    $promotionEndsAt = date('Y-m-d H:i:s', strtotime("+{$durationMonths} months", strtotime($now)));
                }
                
                // Calculate discounted price (ONLY for base plan, extras are NOT discounted)
                if ($promotion['discount_type'] === 'percentage') {
                    $discount = ($originalPrice * $promotion['discount_value']) / 100;
                    $finalPrice = max(0, $originalPrice - $discount);
                } else {
                    // Fixed discount
                    $finalPrice = max(0, $originalPrice - $promotion['discount_value']);
                }
            }
        }

        // Calculate total with extra condominiums if Business plan (legacy)
        // IMPORTANT: Extras are NOT discounted, only base plan price is discounted
        // For new license-based plans, extraCondominiums is ignored
        $planType = $newPlan['plan_type'] ?? null;
        $totalAmount = $finalPrice;
        if (!$planType && $newPlan['slug'] === 'business' && $extraCondominiums > 0) {
            $extraCondominiumsPricingModel = new \App\Models\PlanExtraCondominiumsPricing();
            $pricePerCondominium = $extraCondominiumsPricingModel->getPriceForCondominiums(
                $newPlanId, 
                $extraCondominiums
            );
            if ($pricePerCondominium !== null) {
                // Add full price for extras (no discount)
                $totalAmount += $pricePerCondominium * $extraCondominiums;
            }
        }

        // If pending subscription exists, update it; otherwise create new
        if ($pendingSubscription) {
            $subscriptionData = [
                'plan_id' => $newPlanId,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd
            ];

            // Only set extra_condominiums for legacy Business plan
            if (!$planType && $newPlan['slug'] === 'business') {
                $subscriptionData['extra_condominiums'] = $extraCondominiums;
            }

            if ($finalPromotionId) {
                $subscriptionData['promotion_id'] = $finalPromotionId;
                $subscriptionData['promotion_applied_at'] = $promotionAppliedAt;
                $subscriptionData['promotion_ends_at'] = $promotionEndsAt;
                $subscriptionData['original_price_monthly'] = $originalPrice;
            } else {
                $subscriptionData['promotion_id'] = null;
                $subscriptionData['promotion_applied_at'] = null;
                $subscriptionData['promotion_ends_at'] = null;
                $subscriptionData['original_price_monthly'] = null;
            }
            $subscriptionData['price_monthly'] = $finalPrice; // Always save price (discounted or original)

            $this->subscriptionModel->update($pendingSubscription['id'], $subscriptionData);
            $subscriptionId = $pendingSubscription['id'];

            // Cancel existing pending invoice and create new one
            global $db;
            $db->prepare("UPDATE invoices SET status = 'canceled' WHERE subscription_id = :id AND status = 'pending'")
               ->execute([':id' => $subscriptionId]);
        } else {
            // Create new pending subscription
            $subscriptionData = [
                'user_id' => $userId,
                'plan_id' => $newPlanId,
                'status' => 'pending',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd
            ];

            // Only set extra_condominiums for legacy Business plan
            if (!$planType && $newPlan['slug'] === 'business') {
                $subscriptionData['extra_condominiums'] = $extraCondominiums;
            }

            if ($finalPromotionId) {
                $subscriptionData['promotion_id'] = $finalPromotionId;
                $subscriptionData['promotion_applied_at'] = $promotionAppliedAt;
                $subscriptionData['promotion_ends_at'] = $promotionEndsAt;
                $subscriptionData['original_price_monthly'] = $originalPrice;
            }
            $subscriptionData['price_monthly'] = $finalPrice; // Always save price (discounted or original)

            $subscriptionId = $this->subscriptionModel->create($subscriptionData);
        }

        // Create invoice for pending subscription
        $invoiceMetadata = [
            'is_plan_change' => true
        ];
        if ($activeSubscription) {
            $invoiceMetadata['old_subscription_id'] = $activeSubscription['id'];
            $invoiceMetadata['old_plan_id'] = $activeSubscription['plan_id'];
        }
        $this->invoiceService->createInvoice($subscriptionId, $totalAmount, $invoiceMetadata);

        // Log the plan change request
        $promoText = $finalPromotionId ? " com promoção ID: {$finalPromotionId}" : "";
        $logData = [
            'subscription_id' => $subscriptionId,
            'user_id' => $userId,
            'action' => 'plan_change_requested',
            'new_plan_id' => $newPlanId,
            'new_status' => 'pending'
        ];
        
        if ($activeSubscription) {
            $logData['old_plan_id'] = $activeSubscription['plan_id'];
            $logData['old_status'] = $activeSubscription['status'];
            $description = "Alteração de plano solicitada. Plano: {$activeSubscription['plan_id']} → {$newPlanId}. Subscrição pendente criada aguardando pagamento{$promoText}";
        } else {
            $description = "Escolha de plano solicitada. Plano: {$newPlanId}. Subscrição pendente criada aguardando pagamento{$promoText}";
        }
        
        $logData['description'] = $description;
        $this->auditService->logSubscription($logData);

        return $subscriptionId;
    }

    /**
     * Activate pending subscription and cancel old one (if exists)
     */
    public function activatePendingSubscription(int $pendingSubscriptionId, ?int $oldSubscriptionId = null): bool
    {
        $pendingSubscription = $this->subscriptionModel->findById($pendingSubscriptionId);
        if (!$pendingSubscription || $pendingSubscription['status'] !== 'pending') {
            throw new \Exception("Pending subscription not found or invalid");
        }

        $now = date('Y-m-d H:i:s');
        $periodStart = $now;
        $periodEnd = date('Y-m-d H:i:s', strtotime('+1 month', strtotime($periodStart)));
        
        // If old subscription exists, use its period_end if valid
        if ($oldSubscriptionId) {
            $oldSubscription = $this->subscriptionModel->findById($oldSubscriptionId);
            if ($oldSubscription && $oldSubscription['current_period_end']) {
                // Use current_period_end of old subscription if valid
                if (strtotime($oldSubscription['current_period_end']) >= time()) {
                    $periodStart = $oldSubscription['current_period_end'];
                    $periodEnd = date('Y-m-d H:i:s', strtotime('+1 month', strtotime($periodStart)));
                }
            }
        }

        // Activate pending subscription
        $updateData = [
            'status' => 'active',
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd
        ];

        // Keep promotion fields if they exist
        if (isset($pendingSubscription['promotion_id']) && $pendingSubscription['promotion_id']) {
            $updateData['promotion_id'] = $pendingSubscription['promotion_id'];
            $updateData['promotion_applied_at'] = $pendingSubscription['promotion_applied_at'];
            $updateData['promotion_ends_at'] = $pendingSubscription['promotion_ends_at'];
            $updateData['original_price_monthly'] = $pendingSubscription['original_price_monthly'];
        }

        $success = $this->subscriptionModel->update($pendingSubscriptionId, $updateData);

        if ($success) {
            // Cancel old subscription if it exists
            if ($oldSubscriptionId) {
                $oldSubscription = $this->subscriptionModel->findById($oldSubscriptionId);
                if ($oldSubscription) {
                    $this->subscriptionModel->cancel($oldSubscriptionId);
                    
                    // Log old subscription cancellation
                    $this->auditService->logSubscription([
                        'subscription_id' => $oldSubscriptionId,
                        'user_id' => $oldSubscription['user_id'],
                        'action' => 'subscription_canceled_by_plan_change',
                        'old_status' => $oldSubscription['status'],
                        'new_status' => 'canceled',
                        'description' => "Subscrição cancelada devido à ativação de nova subscrição (ID: {$pendingSubscriptionId})"
                    ]);
                }
            }

            // Log activation
            $promoText = '';
            if (isset($pendingSubscription['promotion_id']) && $pendingSubscription['promotion_id']) {
                $promoText = " com promoção ID: {$pendingSubscription['promotion_id']}";
            }
            
            $description = "Subscrição pendente ativada após pagamento.";
            if ($oldSubscriptionId) {
                $description .= " Plano antigo cancelado.";
            }
            $description .= " Novo período: {$periodStart} até {$periodEnd}{$promoText}";

            $this->auditService->logSubscription([
                'subscription_id' => $pendingSubscriptionId,
                'user_id' => $pendingSubscription['user_id'],
                'action' => 'pending_subscription_activated',
                'old_status' => 'pending',
                'new_status' => 'active',
                'old_period_start' => $pendingSubscription['current_period_start'],
                'new_period_start' => $periodStart,
                'old_period_end' => $pendingSubscription['current_period_end'],
                'new_period_end' => $periodEnd,
                'description' => $description
            ]);
        }

        return $success;
    }

    /**
     * Check if trial is expired for user
     */
    public function isTrialExpired(int $userId): bool
    {
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);
        
        if (!$subscription) {
            return false; // No subscription means no trial to expire
        }

        // Only check trial expiration if status is 'trial'
        if ($subscription['status'] !== 'trial') {
            return false;
        }

        // Check if trial_ends_at has passed
        if (isset($subscription['trial_ends_at']) && $subscription['trial_ends_at']) {
            $trialEndsAt = strtotime($subscription['trial_ends_at']);
            $now = time();
            return $trialEndsAt < $now;
        }

        return false;
    }

    /**
     * Check if user has active subscription (not trial, not expired)
     */
    public function hasActiveSubscription(int $userId): bool
    {
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);
        
        if (!$subscription) {
            return false;
        }

        // Active subscription must have status 'active' and not be expired
        if ($subscription['status'] === 'active') {
            $periodEnd = strtotime($subscription['current_period_end']);
            $now = time();
            return $periodEnd > $now;
        }

        return false;
    }

    protected function getUserIdFromSubscription(int $subscriptionId): int
    {
        global $db;
        if ($db) {
            $stmt = $db->prepare("SELECT user_id FROM subscriptions WHERE id = :id");
            $stmt->execute([':id' => $subscriptionId]);
            $result = $stmt->fetch();
            return $result['user_id'] ?? 0;
        }
        return 0;
    }

    /**
     * Create subscription with license-based model
     */
    public function createSubscription(int $userId, int $planId, ?int $condominiumId = null, ?int $promotionId = null): int
    {
        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            throw new \Exception("Plano não encontrado");
        }

        $planType = $plan['plan_type'] ?? null;
        $allowMultipleCondos = $plan['allow_multiple_condos'] ?? false;

        // Validate condominium requirement
        // For condominos, allow creating subscription without condominium
        // The condominium will be associated later when user creates a new condominium
        $userModel = new User();
        $user = $userModel->findById($userId);
        $userRole = $user['role'] ?? null;

        // For "condominio" plans, only require condominiumId if user is not condomino
        if ($planType === 'condominio' && !$condominiumId && $userRole !== 'condomino') {
            throw new \Exception("Plano Condomínio requer um condomínio associado");
        }

        // For Base plan, validate single condominium
        if ($planType === 'condominio' && $allowMultipleCondos === false) {
            // Check if user already has a subscription with this condominium
            $existing = $this->subscriptionModel->getByCondominiumId($condominiumId);
            if ($existing) {
                throw new \Exception("Este condomínio já está associado a outra subscrição");
            }
        }

        $now = date('Y-m-d H:i:s');
        $periodEnd = date('Y-m-d H:i:s', strtotime("+1 month"));

        // Calculate initial license count
        $usedLicenses = 0;
        if ($condominiumId) {
            $fractionModel = new Fraction();
            $usedLicenses = $fractionModel->getActiveCountByCondominium($condominiumId);
        }

        $licenseMin = (int)($plan['license_min'] ?? 0);
        // If no condominium provided (condomino subscribing), use minimum licenses
        if (!$condominiumId) {
            $usedLicenses = $licenseMin; // Use minimum for new subscriptions without condominium
        } elseif ($usedLicenses < $licenseMin) {
            $usedLicenses = $licenseMin; // Apply minimum
        }

        // Calculate initial license limit: license_min + extra_licenses (initially 0)
        // If plan has a specific limit, use that; otherwise use license_min as base
        $initialExtraLicenses = 0;
        $initialLicenseLimit = $plan['license_limit'] ?? ($licenseMin + $initialExtraLicenses);

        // Handle promotion
        $promotionAppliedAt = null;
        $promotionEndsAt = null;
        $finalPromotionId = null;
        $originalPrice = null;

        if ($promotionId) {
            $promotionModel = new Promotion();
            $promotion = $promotionModel->findById($promotionId);
            
            if ($promotion && $promotion['is_active']) {
                $finalPromotionId = $promotionId;
                $promotionAppliedAt = $now;
                $durationMonths = $promotion['duration_months'] ?? null;
                
                if ($durationMonths) {
                    $promotionEndsAt = date('Y-m-d H:i:s', strtotime("+{$durationMonths} months", strtotime($now)));
                }
            }
        }

        // Create subscription
        $subscriptionData = [
            'user_id' => $userId,
            'plan_id' => $planId,
            'condominium_id' => ($planType === 'condominio' && !$allowMultipleCondos) ? $condominiumId : null,
            'used_licenses' => $usedLicenses,
            'license_limit' => $initialLicenseLimit,
            'extra_licenses' => $initialExtraLicenses,
            'allow_overage' => $plan['allow_overage'] ?? false,
            'proration_mode' => 'none',
            'charge_minimum' => true,
            'status' => 'trial',
            'trial_ends_at' => date('Y-m-d H:i:s', strtotime("+14 days")),
            'current_period_start' => $now,
            'current_period_end' => $periodEnd
        ];

        $originalPriceValue = $originalPrice ?? $plan['price_monthly'];
        $finalPrice = $originalPriceValue; // Default to original price
        
        if ($finalPromotionId) {
            // Calculate discounted price
            if ($promotion['discount_type'] === 'percentage') {
                $discount = ($originalPriceValue * $promotion['discount_value']) / 100;
                $finalPrice = max(0, $originalPriceValue - $discount);
            } else {
                // Fixed discount
                $finalPrice = max(0, $originalPriceValue - $promotion['discount_value']);
            }
            
            $subscriptionData['promotion_id'] = $finalPromotionId;
            $subscriptionData['promotion_applied_at'] = $promotionAppliedAt;
            $subscriptionData['promotion_ends_at'] = $promotionEndsAt;
            $subscriptionData['original_price_monthly'] = $originalPriceValue;
        }
        $subscriptionData['price_monthly'] = $finalPrice; // Always save price (discounted or original)

        $subscriptionId = $this->subscriptionModel->create($subscriptionData);

        // Create association for Pro/Enterprise plans
        if ($planType !== 'condominio' || $allowMultipleCondos) {
            if ($condominiumId) {
                $subscriptionCondominiumModel = new SubscriptionCondominium();
                $subscriptionCondominiumModel->attach($subscriptionId, $condominiumId, $userId);
            }
        } else {
            // Base plan: update condominium subscription_id
            if ($condominiumId) {
                $condominiumModel = new Condominium();
                global $db;
                $db->prepare("UPDATE condominiums SET subscription_id = :subscription_id WHERE id = :id")
                   ->execute([':subscription_id' => $subscriptionId, ':id' => $condominiumId]);
            }
        }

        // Log creation
        $this->auditService->logSubscription([
            'subscription_id' => $subscriptionId,
            'user_id' => $userId,
            'action' => 'subscription_created',
            'new_plan_id' => $planId,
            'new_status' => 'trial',
            'description' => "Subscrição criada. Plano: {$plan['name']}, Licenças: {$usedLicenses}"
        ]);

        return $subscriptionId;
    }

    /**
     * Attach condominium to subscription
     */
    public function attachCondominium(int $subscriptionId, int $condominiumId, ?int $userId = null): bool
    {
        global $db;
        
        try {
            $db->beginTransaction();

            // Validate attachment
            $validation = $this->subscriptionModel->canAttachCondominium($subscriptionId, $condominiumId);
            if (!$validation['can']) {
                throw new \Exception($validation['reason']);
            }

            $subscription = $this->subscriptionModel->findById($subscriptionId);
            if (!$subscription) {
                throw new \Exception("Subscrição não encontrada");
            }

            $plan = $this->planModel->findById($subscription['plan_id']);
            if (!$plan) {
                throw new \Exception("Plano não encontrado");
            }

            // Attach condominium
            $subscriptionCondominiumModel = new SubscriptionCondominium();
            $subscriptionCondominiumModel->attach($subscriptionId, $condominiumId, $userId);

            // Recalculate licenses
            $this->recalculateUsedLicenses($subscriptionId);

            // Log attachment
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => $userId ?? $subscription['user_id'],
                'action' => 'condominium_attached',
                'description' => "Condomínio ID {$condominiumId} associado à subscrição"
            ]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Detach condominium from subscription
     */
    public function detachCondominium(int $subscriptionId, int $condominiumId, ?int $userId = null, ?string $reason = null): bool
    {
        global $db;
        
        try {
            $db->beginTransaction();

            $subscription = $this->subscriptionModel->findById($subscriptionId);
            if (!$subscription) {
                throw new \Exception("Subscrição não encontrada");
            }

            $plan = $this->planModel->findById($subscription['plan_id']);
            if (!$plan) {
                throw new \Exception("Plano não encontrado");
            }

            // For Base plan, cannot detach the only condominium
            if (($plan['plan_type'] ?? null) === 'condominio' && !($plan['allow_multiple_condos'] ?? false)) {
                throw new \Exception("Plano Base não permite desassociar o único condomínio");
            }

            // Check if this is the only condominium
            $subscriptionCondominiumModel = new SubscriptionCondominium();
            $activeCount = $subscriptionCondominiumModel->countActiveBySubscription($subscriptionId);
            if ($activeCount <= 1) {
                throw new \Exception("Não é possível desassociar o último condomínio da subscrição");
            }

            // Detach condominium
            $subscriptionCondominiumModel->detach($subscriptionId, $condominiumId, $userId, $reason);

            // Lock condominium (default behavior)
            $condominiumModel = new Condominium();
            $condominiumModel->lock($condominiumId, $userId, $reason ?? "Desassociado da subscrição");

            // Recalculate licenses
            $this->recalculateUsedLicenses($subscriptionId);

            // Log detachment
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => $userId ?? $subscription['user_id'],
                'action' => 'condominium_detached',
                'description' => "Condomínio ID {$condominiumId} desassociado da subscrição. Motivo: " . ($reason ?? 'Não especificado')
            ]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Transfer licenses from one subscription to another when admin changes
     * 
     * @param int $condominiumId Condomínio que está a ser transferido
     * @param int $fromSubscriptionId Subscrição atual (do admin antigo)
     * @param int $toSubscriptionId Subscrição nova (do admin novo)
     * @param int $transferredByUserId ID do utilizador que está a fazer a transferência
     * @param bool $isProfessionalTransfer Se true, transfere licenças; se false, apenas transfere role
     * @return bool
     */
    public function transferCondominiumLicenses(
        int $condominiumId, 
        int $fromSubscriptionId, 
        int $toSubscriptionId, 
        int $transferredByUserId,
        bool $isProfessionalTransfer = false
    ): bool {
        global $db;
        
        try {
            $db->beginTransaction();

            // Validate subscriptions exist
            $fromSubscription = $this->subscriptionModel->findById($fromSubscriptionId);
            $toSubscription = $this->subscriptionModel->findById($toSubscriptionId);
            
            if (!$fromSubscription || !$toSubscription) {
                throw new \Exception("Uma ou ambas as subscrições não foram encontradas");
            }

            // Validate condominium exists
            $condominiumModel = new Condominium();
            $condominium = $condominiumModel->findById($condominiumId);
            if (!$condominium) {
                throw new \Exception("Condomínio não encontrado");
            }

            // If not professional transfer, just return true (no license transfer needed)
            if (!$isProfessionalTransfer) {
                $db->commit();
                return true;
            }

            // Validate from subscription has this condominium associated
            $fromPlan = $this->planModel->findById($fromSubscription['plan_id']);
            $fromPlanType = $fromPlan['plan_type'] ?? null;
            $fromAllowMultipleCondos = $fromPlan['allow_multiple_condos'] ?? false;

            $isAssociated = false;
            if ($fromPlanType === 'condominio' && !$fromAllowMultipleCondos) {
                // Base plan: check condominium_id
                $isAssociated = ($fromSubscription['condominium_id'] == $condominiumId);
            } else {
                // Professional/Enterprise: check subscription_condominiums
                $subscriptionCondominiumModel = new SubscriptionCondominium();
                $association = $subscriptionCondominiumModel->getBySubscriptionAndCondominium(
                    $fromSubscriptionId, 
                    $condominiumId, 
                    'active'
                );
                $isAssociated = ($association !== null);
            }

            if (!$isAssociated) {
                throw new \Exception("O condomínio não está associado à subscrição de origem");
            }

            // Calculate active fractions for this condominium
            $fractionModel = new Fraction();
            $activeFractions = $fractionModel->getActiveCountByCondominium($condominiumId);

            // Validate destination subscription has capacity
            $toPlan = $this->planModel->findById($toSubscription['plan_id']);
            $toPlanType = $toPlan['plan_type'] ?? null;
            
            if ($toPlanType) {
                // License-based plan: check capacity
                $currentUsedLicenses = $this->subscriptionModel->calculateUsedLicenses($toSubscriptionId);
                $licenseLimit = $toSubscription['license_limit'] ?? null;
                $allowOverage = $toSubscription['allow_overage'] ?? false;
                
                if ($licenseLimit && !$allowOverage) {
                    $newUsedLicenses = $currentUsedLicenses + $activeFractions;
                    if ($newUsedLicenses > $licenseLimit) {
                        throw new \Exception("A subscrição de destino não tem capacidade suficiente. Necessário: {$newUsedLicenses}, Limite: {$licenseLimit}");
                    }
                }
            }

            // Remove licenses from fromSubscription
            $fromUsedLicenses = $this->subscriptionModel->calculateUsedLicenses($fromSubscriptionId);
            $newFromUsedLicenses = max(0, $fromUsedLicenses - $activeFractions);
            
            // Update fromSubscription used_licenses
            $this->subscriptionModel->update($fromSubscriptionId, [
                'used_licenses' => $newFromUsedLicenses
            ]);

            // Detach condominium from fromSubscription
            if ($fromPlanType === 'condominio' && !$fromAllowMultipleCondos) {
                // Base plan: clear condominium_id
                global $db;
                $db->prepare("UPDATE condominiums SET subscription_id = NULL WHERE id = :id")
                   ->execute([':id' => $condominiumId]);
                
                $this->subscriptionModel->update($fromSubscriptionId, [
                    'condominium_id' => null
                ]);
            } else {
                // Professional/Enterprise: detach via SubscriptionCondominium
                $subscriptionCondominiumModel = new SubscriptionCondominium();
                $subscriptionCondominiumModel->detach(
                    $fromSubscriptionId, 
                    $condominiumId, 
                    $transferredByUserId, 
                    "Transferência de administração para admin profissional"
                );
            }

            // Attach condominium to toSubscription
            $toAllowMultipleCondos = $toPlan['allow_multiple_condos'] ?? false;
            
            if ($toPlanType === 'condominio' && !$toAllowMultipleCondos) {
                // Base plan: associate directly
                // Check if toSubscription already has a condominium
                if ($toSubscription['condominium_id']) {
                    throw new \Exception("A subscrição de destino já tem um condomínio associado (plano Base permite apenas um)");
                }
                
                global $db;
                $db->prepare("UPDATE condominiums SET subscription_id = :subscription_id WHERE id = :id")
                   ->execute([':subscription_id' => $toSubscriptionId, ':id' => $condominiumId]);
                
                $this->subscriptionModel->update($toSubscriptionId, [
                    'condominium_id' => $condominiumId
                ]);
            } else {
                // Professional/Enterprise: attach via SubscriptionCondominium
                $subscriptionCondominiumModel = new SubscriptionCondominium();
                $subscriptionCondominiumModel->attach($toSubscriptionId, $condominiumId, $transferredByUserId);
            }

            // Recalculate licenses for both subscriptions
            $this->recalculateUsedLicenses($fromSubscriptionId);
            $this->recalculateUsedLicenses($toSubscriptionId);

            // Cancel fromSubscription if it has no more condominiums (only for Base plans)
            if ($fromPlanType === 'condominio' && !$fromAllowMultipleCondos) {
                // Base plan: if no condominium_id, cancel subscription
                $updatedFromSubscription = $this->subscriptionModel->findById($fromSubscriptionId);
                if (!$updatedFromSubscription['condominium_id']) {
                    $this->subscriptionModel->cancel($fromSubscriptionId);
                }
            } else {
                // Professional/Enterprise: check if has any active condominiums
                $subscriptionCondominiumModel = new SubscriptionCondominium();
                $activeCount = $subscriptionCondominiumModel->countActiveBySubscription($fromSubscriptionId);
                if ($activeCount === 0) {
                    // No more condominiums, but don't cancel Professional/Enterprise automatically
                    // They might want to add more later
                }
            }

            // Log transfer
            $this->auditService->logSubscription([
                'subscription_id' => $toSubscriptionId,
                'user_id' => $toSubscription['user_id'],
                'action' => 'license_transfer',
                'description' => "Transferência de licenças do condomínio ID {$condominiumId} da subscrição {$fromSubscriptionId} para {$toSubscriptionId}. Frações ativas: {$activeFractions}. Transferido por: {$transferredByUserId}"
            ]);

            $this->auditService->logSubscription([
                'subscription_id' => $fromSubscriptionId,
                'user_id' => $fromSubscription['user_id'],
                'action' => 'license_transfer_out',
                'description' => "Licenças transferidas para subscrição {$toSubscriptionId}. Condomínio ID: {$condominiumId}. Frações: {$activeFractions}"
            ]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Recalculate used licenses for subscription
     */
    public function recalculateUsedLicenses(int $subscriptionId): int
    {
        return $this->licenseService->recalculateAndUpdate($subscriptionId);
    }

    /**
     * Check if fraction can be activated
     */
    public function canActivateFraction(int $condominiumId, int $fractionId): array
    {
        // Get subscription for condominium
        $subscription = null;
        $subscriptionCondominiumModel = new SubscriptionCondominium();
        $association = $subscriptionCondominiumModel->getByCondominium($condominiumId);
        
        if ($association) {
            $subscription = $this->subscriptionModel->findById($association['subscription_id']);
        } else {
            // Check Base plan direct association
            $subscription = $this->subscriptionModel->getByCondominiumId($condominiumId);
        }

        if (!$subscription) {
            return ['can' => false, 'reason' => 'Condomínio não tem subscrição ativa', 'would_exceed' => 0];
        }

        // Get current license count
        $currentLicenses = $subscription['used_licenses'] ?? 0;
        
        // Check if activating this fraction would exceed limits
        $validation = $this->licenseService->validateLicenseAvailability($subscription['id'], 1);
        
        if (!$validation['available']) {
            return [
                'can' => false,
                'reason' => $validation['reason'],
                'would_exceed' => $validation['projected'] ?? $currentLicenses + 1
            ];
        }

        return [
            'can' => true,
            'reason' => '',
            'would_exceed' => 0
        ];
    }

    /**
     * Get pricing preview for subscription
     */
    public function getSubscriptionPricingPreview(int $subscriptionId, ?int $projectedUnits = null): array
    {
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            throw new \Exception("Subscrição não encontrada");
        }

        $plan = $this->planModel->findById($subscription['plan_id']);
        if (!$plan) {
            throw new \Exception("Plano não encontrado");
        }

        $licenseCount = $projectedUnits ?? ($subscription['used_licenses'] ?? 0);
        $licenseMin = (int)($plan['license_min'] ?? 0);
        $chargeMinimum = $subscription['charge_minimum'] ?? true;

        // Apply minimum if needed
        if ($chargeMinimum && $licenseCount < $licenseMin) {
            $licenseCount = $licenseMin;
        }

        // Get price breakdown
        $breakdown = $this->pricingService->getPriceBreakdown($subscription['plan_id'], $licenseCount);

        // Calculate annual price if applicable
        $annualDiscount = (float)($plan['annual_discount_percentage'] ?? 0);
        $monthlyPrice = $breakdown['total'];
        $annualPrice = $this->pricingService->calculateAnnualPrice($monthlyPrice, $annualDiscount);

        return [
            'subscription_id' => $subscriptionId,
            'plan_id' => $subscription['plan_id'],
            'plan_name' => $plan['name'],
            'license_count' => $projectedUnits ?? ($subscription['used_licenses'] ?? 0),
            'effective_license_count' => $licenseCount,
            'license_minimum' => $licenseMin,
            'license_limit' => $subscription['license_limit'],
            'monthly_price' => $monthlyPrice,
            'annual_price' => $annualPrice,
            'annual_discount' => $annualDiscount,
            'breakdown' => $breakdown,
            'applied_minimum' => $chargeMinimum && ($projectedUnits ?? ($subscription['used_licenses'] ?? 0)) < $licenseMin
        ];
    }

    /**
     * Get current charge for subscription
     */
    public function getCurrentCharge(int $subscriptionId): array
    {
        return $this->getSubscriptionPricingPreview($subscriptionId);
    }

    /**
     * Validate subscription limits
     */
    public function validateSubscriptionLimits(int $subscriptionId): array
    {
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            return ['valid' => false, 'issues' => ['Subscrição não encontrada']];
        }

        $plan = $this->planModel->findById($subscription['plan_id']);
        if (!$plan) {
            return ['valid' => false, 'issues' => ['Plano não encontrado']];
        }

        $issues = [];
        $usedLicenses = $subscription['used_licenses'] ?? 0;
        $licenseMin = (int)($plan['license_min'] ?? 0);
        $licenseLimit = $subscription['license_limit'] ?? null;
        $allowOverage = $subscription['allow_overage'] ?? false;

        // Check minimum
        if ($usedLicenses < $licenseMin) {
            $issues[] = "Licenças usadas ({$usedLicenses}) abaixo do mínimo ({$licenseMin})";
        }

        // Check limit
        if ($licenseLimit !== null && $usedLicenses > $licenseLimit && !$allowOverage) {
            $issues[] = "Licenças usadas ({$usedLicenses}) excedem o limite ({$licenseLimit})";
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'used_licenses' => $usedLicenses,
            'license_min' => $licenseMin,
            'license_limit' => $licenseLimit
        ];
    }

    /**
     * Get subscriptions expiring in X days
     * 
     * @param int $days Number of days before expiration
     * @return array List of subscriptions expiring soon
     */
    public function getSubscriptionsExpiringInDays(int $days = 7): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $startDate = date('Y-m-d H:i:s');
        $endDate = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $stmt = $db->prepare("
            SELECT s.*, p.name as plan_name, p.slug as plan_slug, u.name as user_name, u.email as user_email
            FROM subscriptions s
            INNER JOIN plans p ON s.plan_id = p.id
            INNER JOIN users u ON s.user_id = u.id
            WHERE s.status = 'active'
            AND s.current_period_end >= :start_date
            AND s.current_period_end <= :end_date
            ORDER BY s.current_period_end ASC
        ");

        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get expired subscriptions
     * 
     * @return array List of expired subscriptions
     */
    public function getExpiredSubscriptions(): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare("
            SELECT s.*, p.name as plan_name, p.slug as plan_slug, u.name as user_name, u.email as user_email
            FROM subscriptions s
            INNER JOIN plans p ON s.plan_id = p.id
            INNER JOIN users u ON s.user_id = u.id
            WHERE s.status = 'active'
            AND s.current_period_end < :now
            ORDER BY s.current_period_end ASC
        ");

        $stmt->execute([':now' => $now]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Send renewal reminder email
     * 
     * @param int $subscriptionId Subscription ID
     * @param int $daysBefore Days before expiration
     * @return bool Success
     */
    public function sendRenewalReminder(int $subscriptionId, int $daysBefore = 7): bool
    {
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            return false;
        }

        $plan = $this->planModel->findById($subscription['plan_id']);
        if (!$plan) {
            return false;
        }

        $userModel = new \App\Models\User();
        $user = $userModel->findById($subscription['user_id']);
        if (!$user || empty($user['email'])) {
            return false;
        }

        // Check if reminder was already sent today
        global $db;
        $stmt = $db->prepare("
            SELECT id FROM notifications
            WHERE user_id = :user_id
            AND type = 'subscription_renewal_reminder'
            AND DATE(created_at) = CURDATE()
            AND link LIKE :link_pattern
        ");
        $stmt->execute([
            ':user_id' => $subscription['user_id'],
            ':link_pattern' => '%subscription%'
        ]);
        
        if ($stmt->fetch()) {
            // Already sent today
            return true;
        }

        // Calculate monthly price
        $pricingService = new PricingService();
        $licenseLimit = $subscription['license_limit'] ?? $plan['license_min'] ?? 0;
        $pricingBreakdown = $pricingService->getPriceBreakdown(
            $plan['id'],
            $licenseLimit,
            $plan['pricing_mode'] ?? 'flat'
        );
        $monthlyPrice = $pricingBreakdown['total'];

        // Format expiration date
        $expirationDate = date('d/m/Y', strtotime($subscription['current_period_end']));
        $daysLeft = ceil((strtotime($subscription['current_period_end']) - time()) / 86400);

        // Send email via NotificationService
        $notificationService = new \App\Services\NotificationService();
        $emailService = new \App\Core\EmailService();
        
        $subject = "Renovação de Subscrição - {$daysLeft} dia(s) restante(s)";
        $message = "A sua subscrição do plano {$plan['name']} expira em {$daysLeft} dia(s) ({$expirationDate}).\n\n";
        $message .= "Valor mensal: €" . number_format($monthlyPrice, 2, ',', '.') . "\n\n";
        $message .= "Para renovar a sua subscrição e evitar o bloqueio do acesso, efetue o pagamento através do link abaixo.";
        
        $link = BASE_URL . 'subscription';

        // Create notification
        $notificationService->createNotification(
            $subscription['user_id'],
            null,
            'subscription_renewal_reminder',
            "Renovação de Subscrição - {$daysLeft} dia(s)",
            $message,
            $link
        );

        // Send email using template from database
        $html = $emailService->renderTemplate('subscription_renewal_reminder', [
            'nome' => $user['name'],
            'planName' => $plan['name'],
            'expirationDate' => $expirationDate,
            'daysLeft' => (string)$daysLeft,
            'monthlyPrice' => number_format($monthlyPrice, 2, ',', '.'),
            'link' => $link,
            'baseUrl' => BASE_URL
        ]);
        $text = $emailService->renderTextTemplate('subscription_renewal_reminder', [
            'nome' => $user['name'],
            'planName' => $plan['name'],
            'expirationDate' => $expirationDate,
            'daysLeft' => (string)$daysLeft,
            'monthlyPrice' => number_format($monthlyPrice, 2, ',', '.'),
            'link' => $link,
            'baseUrl' => BASE_URL
        ]);
        
        if (!empty($html)) {
            // Get template subject if available
            $emailTemplateModel = new \App\Models\EmailTemplate();
            $template = $emailTemplateModel->findByKey('subscription_renewal_reminder');
            if ($template && !empty($template['subject'])) {
                $templateSubject = $template['subject'];
                $templateSubject = str_replace('{daysLeft}', (string)$daysLeft, $templateSubject);
                $templateSubject = str_replace('{planName}', $plan['name'], $templateSubject);
                $templateSubject = str_replace('{expirationDate}', $expirationDate, $templateSubject);
                $subject = $templateSubject;
            }
        } else {
            // Fallback to inline HTML
            $html = "
                <p>A sua subscrição do plano <strong>{$plan['name']}</strong> expira em <strong>{$daysLeft} dia(s)</strong> ({$expirationDate}).</p>
                <p><strong>Valor mensal:</strong> €" . number_format($monthlyPrice, 2, ',', '.') . "</p>
                <p>Para renovar a sua subscrição e evitar o bloqueio do acesso, efetue o pagamento através do link abaixo.</p>
                <p><a href=\"{$link}\" style=\"display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;\">Renovar Subscrição</a></p>
                <p><small>Se não renovar até {$expirationDate}, o acesso à gestão dos condomínios será bloqueado.</small></p>
            ";
            $text = $message;
        }

        return $emailService->sendEmail(
            $user['email'],
            $subject,
            $html,
            $text,
            'notification',
            $subscription['user_id']
        );
    }

    /**
     * Expire subscription and lock associated condominiums
     * 
     * @param int $subscriptionId Subscription ID
     * @return bool Success
     */
    public function expireSubscription(int $subscriptionId): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        try {
            $db->beginTransaction();

            $subscription = $this->subscriptionModel->findById($subscriptionId);
            if (!$subscription) {
                throw new \Exception("Subscription not found");
            }

            // Update subscription status to expired
            $this->subscriptionModel->update($subscriptionId, [
                'status' => 'expired'
            ]);

            // Get all condominiums associated with this subscription
            $condominiumModel = new Condominium();
            $subscriptionCondominiumModel = new SubscriptionCondominium();
            
            // Base plan: direct association
            if ($subscription['condominium_id']) {
                $condominiumModel->lock(
                    $subscription['condominium_id'],
                    null,
                    'Subscrição expirada - pagamento pendente'
                );
            }

            // Pro/Enterprise: via subscription_condominiums
            $associatedCondominiums = $subscriptionCondominiumModel->getActiveBySubscription($subscriptionId);
            foreach ($associatedCondominiums as $association) {
                $condominiumModel->lock(
                    $association['condominium_id'],
                    null,
                    'Subscrição expirada - pagamento pendente'
                );
            }

            // Create notification
            $notificationService = new \App\Services\NotificationService();
            $notificationService->createNotification(
                $subscription['user_id'],
                null,
                'subscription_expired',
                'Subscrição Expirada',
                'A sua subscrição expirou. O acesso à gestão dos condomínios foi bloqueado até efetuar o pagamento.',
                BASE_URL . 'subscription'
            );

            // Send email if preferences allow
            $userModel = new \App\Models\User();
            $user = $userModel->findById($subscription['user_id']);
            if ($user && !empty($user['email'])) {
                $emailService = new \App\Core\EmailService();
                $plan = $this->planModel->findById($subscription['plan_id']);
                
                $subject = "Subscrição Expirada";
                $message = "A sua subscrição do plano {$plan['name']} expirou.\n\n";
                $message .= "O acesso à gestão dos condomínios foi bloqueado até efetuar o pagamento.\n\n";
                $message .= "Para reativar a sua subscrição, efetue o pagamento através do link abaixo.";
                
                // Send email using template from database
                $html = $emailService->renderTemplate('subscription_expired', [
                    'nome' => $user['name'],
                    'planName' => $plan['name'],
                    'link' => BASE_URL . 'subscription',
                    'baseUrl' => BASE_URL
                ]);
                $text = $emailService->renderTextTemplate('subscription_expired', [
                    'nome' => $user['name'],
                    'planName' => $plan['name'],
                    'link' => BASE_URL . 'subscription',
                    'baseUrl' => BASE_URL
                ]);
                
                if (!empty($html)) {
                    // Get template subject if available
                    $emailTemplateModel = new \App\Models\EmailTemplate();
                    $template = $emailTemplateModel->findByKey('subscription_expired');
                    if ($template && !empty($template['subject'])) {
                        $templateSubject = $template['subject'];
                        $templateSubject = str_replace('{planName}', $plan['name'], $templateSubject);
                        $subject = $templateSubject;
                    }
                } else {
                    // Fallback to inline HTML
                    $html = "
                        <p>A sua subscrição do plano <strong>{$plan['name']}</strong> expirou.</p>
                        <p>O acesso à gestão dos condomínios foi bloqueado até efetuar o pagamento.</p>
                        <p>Para reativar a sua subscrição, efetue o pagamento através do link abaixo.</p>
                        <p><a href=\"" . BASE_URL . "subscription\" style=\"display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;\">Renovar Subscrição</a></p>
                    ";
                    $text = $message;
                }

                $emailService->sendEmail(
                    $user['email'],
                    $subject,
                    $html,
                    $text,
                    'notification',
                    $subscription['user_id']
                );
            }

            // Log expiration
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => $subscription['user_id'],
                'action' => 'subscription_expired',
                'old_status' => $subscription['status'],
                'new_status' => 'expired',
                'description' => "Subscrição expirada automaticamente. Condomínios bloqueados."
            ]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error expiring subscription {$subscriptionId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculate backpayment months for expired subscription
     * 
     * @param int $subscriptionId Subscription ID
     * @return int Number of months to pay (including current month)
     */
    public function calculateBackpaymentMonths(int $subscriptionId): int
    {
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            return 0;
        }

        $periodEnd = strtotime($subscription['current_period_end']);
        $now = time();

        if ($periodEnd >= $now) {
            // Not expired yet
            return 0;
        }

        // Calculate difference in months
        $startDate = new \DateTime($subscription['current_period_end']);
        $endDate = new \DateTime();
        
        $diff = $startDate->diff($endDate);
        $months = ($diff->y * 12) + $diff->m;
        
        // If there are remaining days, add one more month
        if ($diff->d > 0 || $diff->h > 0 || $diff->i > 0) {
            $months++;
        }

        // Always add at least 1 month (current month)
        return max(1, $months);
    }
}

