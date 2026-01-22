<?php

namespace App\Models;

use App\Core\Model;

class Subscription extends Model
{
    protected $table = 'subscriptions';

    /**
     * Get active subscription for user
     */
    public function getActiveSubscription(int $userId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT s.*, p.name as plan_name, p.slug as plan_slug, p.price_monthly, p.limit_condominios, p.limit_fracoes, p.features
            FROM subscriptions s
            INNER JOIN plans p ON s.plan_id = p.id
            WHERE s.user_id = :user_id 
            AND s.status IN ('trial', 'active')
            ORDER BY s.created_at DESC
            LIMIT 1
        ");
        
        // Note: extra_condominiums is included in s.*

        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get pending subscription for user
     */
    public function getPendingSubscription(int $userId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT s.*, p.name as plan_name, p.slug as plan_slug, p.price_monthly, p.limit_condominios, p.limit_fracoes, p.features
            FROM subscriptions s
            INNER JOIN plans p ON s.plan_id = p.id
            WHERE s.user_id = :user_id 
            AND s.status = 'pending'
            ORDER BY s.created_at DESC
            LIMIT 1
        ");
        
        // Note: extra_condominiums is included in s.*

        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all subscriptions for user (active + pending)
     */
    public function getAllUserSubscriptions(int $userId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT s.*, p.name as plan_name, p.slug as plan_slug, p.price_monthly, p.limit_condominios, p.limit_fracoes, p.features
            FROM subscriptions s
            INNER JOIN plans p ON s.plan_id = p.id
            WHERE s.user_id = :user_id 
            AND s.status IN ('trial', 'active', 'pending')
            ORDER BY 
                CASE s.status
                    WHEN 'active' THEN 1
                    WHEN 'trial' THEN 2
                    WHEN 'pending' THEN 3
                    ELSE 4
                END,
                s.created_at DESC
        ");
        
        // Note: extra_condominiums is included in s.*

        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create subscription
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check if promotion columns exist
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'promotion_id'");
        $hasPromoFields = $checkStmt->rowCount() > 0;

        if ($hasPromoFields) {
            $stmt = $this->db->prepare("
                INSERT INTO subscriptions (
                    user_id, plan_id, extra_condominiums, status, trial_ends_at, 
                    current_period_start, current_period_end, payment_method,
                    promotion_id, promotion_applied_at, promotion_ends_at, original_price_monthly
                )
                VALUES (
                    :user_id, :plan_id, :extra_condominiums, :status, :trial_ends_at,
                    :current_period_start, :current_period_end, :payment_method,
                    :promotion_id, :promotion_applied_at, :promotion_ends_at, :original_price_monthly
                )
            ");

            $stmt->execute([
                ':user_id' => $data['user_id'],
                ':plan_id' => $data['plan_id'],
                ':extra_condominiums' => $data['extra_condominiums'] ?? 0,
                ':status' => $data['status'] ?? 'trial',
                ':trial_ends_at' => $data['trial_ends_at'] ?? null,
                ':current_period_start' => $data['current_period_start'],
                ':current_period_end' => $data['current_period_end'],
                ':payment_method' => $data['payment_method'] ?? null,
                ':promotion_id' => $data['promotion_id'] ?? null,
                ':promotion_applied_at' => $data['promotion_applied_at'] ?? null,
                ':promotion_ends_at' => $data['promotion_ends_at'] ?? null,
                ':original_price_monthly' => $data['original_price_monthly'] ?? null
            ]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO subscriptions (
                    user_id, plan_id, extra_condominiums, status, trial_ends_at, 
                    current_period_start, current_period_end, payment_method
                )
                VALUES (
                    :user_id, :plan_id, :extra_condominiums, :status, :trial_ends_at,
                    :current_period_start, :current_period_end, :payment_method
                )
            ");

            $stmt->execute([
                ':user_id' => $data['user_id'],
                ':plan_id' => $data['plan_id'],
                ':extra_condominiums' => $data['extra_condominiums'] ?? 0,
                ':status' => $data['status'] ?? 'trial',
                ':trial_ends_at' => $data['trial_ends_at'] ?? null,
                ':current_period_start' => $data['current_period_start'],
                ':current_period_end' => $data['current_period_end'],
                ':payment_method' => $data['payment_method'] ?? null
            ]);
        }

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update subscription
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[":$key"] = $value;
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE subscriptions SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Check if subscription is active
     */
    public function isActive(int $subscriptionId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT status FROM subscriptions 
            WHERE id = :id 
            AND status IN ('trial', 'active')
            AND current_period_end > NOW()
        ");

        $stmt->execute([':id' => $subscriptionId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Check if user can create condominium
     */
    public function canCreateCondominium(int $userId): bool
    {
        $subscription = $this->getActiveSubscription($userId);
        
        if (!$subscription) {
            return false;
        }

        // BUSINESS plan has unlimited condominiums
        if ($subscription['limit_condominios'] === null) {
            return true;
        }

        // Count existing condominiums
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM condominiums 
            WHERE user_id = :user_id AND is_active = TRUE
        ");
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch();

        return ($result['count'] ?? 0) < $subscription['limit_condominios'];
    }

    /**
     * Check if user can create fraction
     */
    public function canCreateFraction(int $userId, int $condominiumId): bool
    {
        $subscription = $this->getActiveSubscription($userId);
        
        if (!$subscription) {
            return false;
        }

        // BUSINESS plan has unlimited fractions
        if ($subscription['limit_fracoes'] === null) {
            return true;
        }

        // Count existing fractions in condominium
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM fractions 
            WHERE condominium_id = :condominium_id AND is_active = TRUE
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $result = $stmt->fetch();

        return ($result['count'] ?? 0) < $subscription['limit_fracoes'];
    }

    /**
     * Check if subscription has feature
     */
    public function hasFeature(int $userId, string $feature): bool
    {
        $subscription = $this->getActiveSubscription($userId);
        
        if (!$subscription) {
            return false;
        }

        $features = json_decode($subscription['features'] ?? '{}', true);
        return isset($features[$feature]) && $features[$feature] === true;
    }

    /**
     * Cancel subscription
     */
    public function cancel(int $subscriptionId): bool
    {
        return $this->update($subscriptionId, [
            'status' => 'canceled',
            'canceled_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Suspend subscription
     */
    public function suspend(int $subscriptionId): bool
    {
        return $this->update($subscriptionId, [
            'status' => 'suspended'
        ]);
    }

    /**
     * Reactivate subscription
     */
    public function reactivate(int $subscriptionId, string $periodEnd): bool
    {
        return $this->update($subscriptionId, [
            'status' => 'active',
            'current_period_end' => $periodEnd
        ]);
    }

    /**
     * Find subscription by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM subscriptions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get price with promotion applied (if promotion is still active)
     * Returns the effective monthly price considering active promotions
     */
    public function getPriceWithPromotion(int $subscriptionId, float $basePrice): float
    {
        if (!$this->db) {
            return $basePrice;
        }

        $subscription = $this->findById($subscriptionId);
        if (!$subscription || !isset($subscription['promotion_id']) || !$subscription['promotion_id']) {
            return $basePrice;
        }

        // Check if promotion is still active
        $now = date('Y-m-d H:i:s');
        if (!$subscription['promotion_ends_at'] || $subscription['promotion_ends_at'] < $now) {
            // Promotion expired, return original price
            return $subscription['original_price_monthly'] ?? $basePrice;
        }

        // Promotion is still active, calculate discounted price
        $promotionModel = new Promotion();
        $promotion = $promotionModel->findById($subscription['promotion_id']);
        
        if (!$promotion) {
            return $subscription['original_price_monthly'] ?? $basePrice;
        }

        // Use original price if available, otherwise use current base price
        $priceToDiscount = $subscription['original_price_monthly'] ?? $basePrice;
        
        if ($promotion['discount_type'] === 'percentage') {
            $discount = ($priceToDiscount * $promotion['discount_value']) / 100;
            return max(0, $priceToDiscount - $discount);
        } else {
            // Fixed discount
            return max(0, $priceToDiscount - $promotion['discount_value']);
        }
    }

    /**
     * Check and expire promotions that have ended
     * Should be called periodically (e.g., at the start of each billing period)
     */
    public function checkAndExpirePromotions(): int
    {
        if (!$this->db) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        
        // Find subscriptions with expired promotions
        $stmt = $this->db->prepare("
            SELECT id, original_price_monthly, promotion_id
            FROM subscriptions
            WHERE promotion_id IS NOT NULL
            AND promotion_ends_at IS NOT NULL
            AND promotion_ends_at <= :now
            AND status IN ('trial', 'active')
        ");
        
        $stmt->execute([':now' => $now]);
        $expiredSubscriptions = $stmt->fetchAll() ?: [];
        
        $expiredCount = 0;
        
        foreach ($expiredSubscriptions as $subscription) {
            // Clear promotion fields and restore original price
            $updateStmt = $this->db->prepare("
                UPDATE subscriptions 
                SET promotion_id = NULL,
                    promotion_applied_at = NULL,
                    promotion_ends_at = NULL,
                    original_price_monthly = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            if ($updateStmt->execute([':id' => $subscription['id']])) {
                $expiredCount++;
            }
        }
        
        return $expiredCount;
    }
}

