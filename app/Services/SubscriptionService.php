<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AuditService;

class SubscriptionService
{
    protected $planModel;
    protected $subscriptionModel;
    protected $auditService;

    public function __construct()
    {
        $this->planModel = new Plan();
        $this->subscriptionModel = new Subscription();
        $this->auditService = new AuditService();
    }

    /**
     * Start trial subscription
     */
    public function startTrial(int $userId, int $planId, int $trialDays = 14): int
    {
        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            throw new \Exception("Plan not found");
        }

        $now = date('Y-m-d H:i:s');
        $trialEndsAt = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
        $periodEnd = date('Y-m-d H:i:s', strtotime("+1 month"));

        $subscriptionId = $this->subscriptionModel->create([
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => 'trial',
            'trial_ends_at' => $trialEndsAt,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd
        ]);

        if ($subscriptionId) {
            // Log subscription creation
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
                'action' => 'subscription_trial_started',
                'new_plan_id' => $planId,
                'new_status' => 'trial',
                'new_period_start' => $now,
                'new_period_end' => $periodEnd,
                'description' => "Período experimental iniciado. Plano ID: {$planId}. Duração: {$trialDays} dias"
            ]);
        }

        return $subscriptionId;
    }

    /**
     * Upgrade subscription
     */
    public function upgrade(int $userId, int $newPlanId): bool
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

        $success = $this->subscriptionModel->update($subscription['id'], [
            'plan_id' => $newPlanId,
            'status' => 'active',
            'current_period_start' => $now,
            'current_period_end' => $periodEnd
        ]);

        if ($success) {
            // Log subscription upgrade/plan change
            $this->auditService->logSubscription([
                'subscription_id' => $subscription['id'],
                'user_id' => $userId,
                'action' => 'subscription_upgraded',
                'old_plan_id' => $oldPlanId,
                'new_plan_id' => $newPlanId,
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'old_period_start' => $oldPeriodStart,
                'new_period_start' => $now,
                'old_period_end' => $oldPeriodEnd,
                'new_period_end' => $periodEnd,
                'description' => "Subscrição atualizada. Plano: {$oldPlanId} → {$newPlanId}. Status: {$oldStatus} → active"
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

        $now = date('Y-m-d H:i:s');
        $periodEnd = date('Y-m-d H:i:s', strtotime("+1 month"));

        $oldStatus = $subscription['status'];
        $oldPeriodStart = $subscription['current_period_start'] ?? null;
        $oldPeriodEnd = $subscription['current_period_end'] ?? null;
        $userId = $subscription['user_id'] ?? 0;

        $success = $this->subscriptionModel->update($subscription['id'], [
            'status' => 'active',
            'current_period_start' => $now,
            'current_period_end' => $periodEnd
        ]);

        if ($success) {
            // Log subscription renewal
            $this->auditService->logSubscription([
                'subscription_id' => $subscription['id'],
                'user_id' => $userId,
                'action' => 'subscription_renewed',
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'old_period_start' => $oldPeriodStart,
                'new_period_start' => $now,
                'old_period_end' => $oldPeriodEnd,
                'new_period_end' => $periodEnd,
                'description' => "Subscrição renovada automaticamente. Novo período: {$now} até {$periodEnd}"
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
     * Change subscription plan
     */
    public function changePlan(int $userId, int $newPlanId): bool
    {
        // Same as upgrade, but can be used for downgrades too
        return $this->upgrade($userId, $newPlanId);
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

