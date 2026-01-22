<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Promotion;
use App\Services\AuditService;
use App\Services\InvoiceService;

class SubscriptionService
{
    protected $planModel;
    protected $subscriptionModel;
    protected $auditService;
    protected $invoiceService;

    public function __construct()
    {
        $this->planModel = new Plan();
        $this->subscriptionModel = new Subscription();
        $this->auditService = new AuditService();
        $this->invoiceService = new InvoiceService();
    }

    /**
     * Start trial subscription
     */
    public function startTrial(int $userId, int $planId, int $trialDays = 14, int $extraCondominiums = 0, ?int $promotionId = null, ?float $originalPrice = null): int
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
        
        if ($promotionId) {
            $promotionModel = new Promotion();
            $promotion = $promotionModel->findById($promotionId);
            
            if ($promotion && $promotion['is_active']) {
                $promotionAppliedAt = $now;
                $durationMonths = $promotion['duration_months'] ?? null;
                
                if ($durationMonths) {
                    $promotionEndsAt = date('Y-m-d H:i:s', strtotime("+{$durationMonths} months", strtotime($now)));
                }
            } else {
                $promotionId = null; // Invalid promotion, don't apply
            }
        }

        $subscriptionData = [
            'user_id' => $userId,
            'plan_id' => $planId,
            'extra_condominiums' => $extraCondominiums,
            'status' => 'trial',
            'trial_ends_at' => $trialEndsAt,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd
        ];

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
                
                $updateData['promotion_id'] = $promotionId;
                $updateData['promotion_applied_at'] = $promotionAppliedAt;
                $updateData['promotion_ends_at'] = $promotionEndsAt;
                $updateData['original_price_monthly'] = $newPlan['price_monthly'];
            }
        } else {
            // Clear promotion fields if no promotion is being applied
            $updateData['promotion_id'] = null;
            $updateData['promotion_applied_at'] = null;
            $updateData['promotion_ends_at'] = null;
            $updateData['original_price_monthly'] = null;
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

        // Calculate total with extra condominiums if Business plan
        // IMPORTANT: Extras are NOT discounted, only base plan price is discounted
        $totalAmount = $finalPrice;
        if ($newPlan['slug'] === 'business' && $extraCondominiums > 0) {
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
                'extra_condominiums' => $extraCondominiums,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd
            ];

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
                'extra_condominiums' => $extraCondominiums,
                'status' => 'pending',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd
            ];

            if ($finalPromotionId) {
                $subscriptionData['promotion_id'] = $finalPromotionId;
                $subscriptionData['promotion_applied_at'] = $promotionAppliedAt;
                $subscriptionData['promotion_ends_at'] = $promotionEndsAt;
                $subscriptionData['original_price_monthly'] = $originalPrice;
            }

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
}

