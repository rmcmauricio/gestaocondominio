<?php

namespace App\Models;

use App\Core\Model;
use App\Services\LicenseService;

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

        // Check which columns exist
        $checkPromoStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'promotion_id'");
        $hasPromoFields = $checkPromoStmt->rowCount() > 0;
        
        $checkLicenseStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'condominium_id'");
        $hasLicenseFields = $checkLicenseStmt->rowCount() > 0;

        // Build fields and values dynamically based on what exists
        $fields = ['user_id', 'plan_id', 'extra_condominiums', 'status', 'trial_ends_at', 
                   'current_period_start', 'current_period_end', 'payment_method'];
        $placeholders = [':user_id', ':plan_id', ':extra_condominiums', ':status', ':trial_ends_at',
                         ':current_period_start', ':current_period_end', ':payment_method'];
        $values = [
            ':user_id' => $data['user_id'],
            ':plan_id' => $data['plan_id'],
            ':extra_condominiums' => $data['extra_condominiums'] ?? 0,
            ':status' => $data['status'] ?? 'trial',
            ':trial_ends_at' => $data['trial_ends_at'] ?? null,
            ':current_period_start' => $data['current_period_start'],
            ':current_period_end' => $data['current_period_end'],
            ':payment_method' => $data['payment_method'] ?? null
        ];

        if ($hasPromoFields) {
            $fields[] = 'promotion_id';
            $fields[] = 'promotion_applied_at';
            $fields[] = 'promotion_ends_at';
            $fields[] = 'original_price_monthly';
            $placeholders[] = ':promotion_id';
            $placeholders[] = ':promotion_applied_at';
            $placeholders[] = ':promotion_ends_at';
            $placeholders[] = ':original_price_monthly';
            $values[':promotion_id'] = $data['promotion_id'] ?? null;
            $values[':promotion_applied_at'] = $data['promotion_applied_at'] ?? null;
            $values[':promotion_ends_at'] = $data['promotion_ends_at'] ?? null;
            $values[':original_price_monthly'] = $data['original_price_monthly'] ?? null;
        }

        if ($hasLicenseFields) {
            $fields[] = 'condominium_id';
            $fields[] = 'used_licenses';
            $fields[] = 'license_limit';
            $fields[] = 'extra_licenses';
            $fields[] = 'allow_overage';
            $fields[] = 'proration_mode';
            $fields[] = 'charge_minimum';
            $placeholders[] = ':condominium_id';
            $placeholders[] = ':used_licenses';
            $placeholders[] = ':license_limit';
            $placeholders[] = ':extra_licenses';
            $placeholders[] = ':allow_overage';
            $placeholders[] = ':proration_mode';
            $placeholders[] = ':charge_minimum';
            $values[':condominium_id'] = $data['condominium_id'] ?? null;
            $values[':used_licenses'] = $data['used_licenses'] ?? 0;
            $values[':license_limit'] = $data['license_limit'] ?? null;
            $values[':extra_licenses'] = $data['extra_licenses'] ?? 0;
            $values[':allow_overage'] = isset($data['allow_overage']) ? ($data['allow_overage'] ? 1 : 0) : 0;
            $values[':proration_mode'] = $data['proration_mode'] ?? 'none';
            $values[':charge_minimum'] = isset($data['charge_minimum']) ? ($data['charge_minimum'] ? 1 : 0) : 1;
        }

        $sql = "INSERT INTO subscriptions (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

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
     * Updated for license-based model
     */
    public function canCreateCondominium(int $userId): bool
    {
        // Check if user is demo user - allow up to 2 condominiums for demo
        $demoProtectionMiddleware = new \App\Middleware\DemoProtectionMiddleware();
        $isDemoUser = $demoProtectionMiddleware::isDemoUser($userId);
        
        if ($isDemoUser) {
            // For demo users, allow up to 2 condominiums
            // Count ALL condominiums where user is admin (owner or assigned), not just subscription-associated ones
            global $db;
            if (!$db) {
                return false;
            }
            
            // Count condominiums where user is owner
            $stmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM condominiums
                WHERE user_id = :user_id
                AND is_active = TRUE
            ");
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch();
            $count = $result ? (int)$result['count'] : 0;
            
            // Count condominiums from condominium_users where user is admin
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT c.id) as count
                FROM condominium_users cu
                INNER JOIN condominiums c ON c.id = cu.condominium_id
                WHERE cu.user_id = :user_id
                AND cu.role = 'admin'
                AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
                AND c.is_active = TRUE
                AND c.user_id != :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch();
            $count += $result ? (int)$result['count'] : 0;
            
            // Demo users can have up to 2 condominiums
            return $count < 2;
        }

        $subscription = $this->getActiveSubscription($userId);
        
        if (!$subscription) {
            return false;
        }

        // Get plan details
        $planModel = new Plan();
        $plan = $planModel->findById($subscription['plan_id']);
        
        if (!$plan) {
            return false;
        }

        // Check if plan is demo plan (slug = 'demo' or limit_condominios = 2 and is_active = false)
        $planSlug = $plan['slug'] ?? '';
        $planLimitCondominios = isset($plan['limit_condominios']) ? (int)$plan['limit_condominios'] : null;
        $planIsActive = isset($plan['is_active']) ? (bool)$plan['is_active'] : true;
        
        $isDemoPlan = ($planSlug === 'demo') || 
                      ($planLimitCondominios === 2 && $planIsActive === false);
        
        if ($isDemoPlan) {
            // For demo plan, count ALL condominiums where user is admin (owner or assigned)
            global $db;
            if (!$db) {
                return false;
            }
            
            // Count condominiums where user is owner
            $stmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM condominiums
                WHERE user_id = :user_id
                AND is_active = TRUE
            ");
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch();
            $count = $result ? (int)$result['count'] : 0;
            
            // Count condominiums from condominium_users where user is admin
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT c.id) as count
                FROM condominium_users cu
                INNER JOIN condominiums c ON c.id = cu.condominium_id
                WHERE cu.user_id = :user_id
                AND cu.role = 'admin'
                AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
                AND c.is_active = TRUE
                AND c.user_id != :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch();
            $count += $result ? (int)$result['count'] : 0;
            
            // Demo plan allows up to 2 condominiums
            return $count < 2;
        }

        // Get limit_condominios from plan
        $limitCondominios = isset($plan['limit_condominios']) && $plan['limit_condominios'] !== null ? (int)$plan['limit_condominios'] : null;

        // If limit is NULL, unlimited
        if ($limitCondominios === null) {
            return true;
        }

        // Count active condominiums associated with this subscription
        $count = 0;
        
        // Count direct association (Base plan)
        if ($subscription['condominium_id']) {
            $count++;
        }
        
        // Count associations via subscription_condominiums (Pro/Enterprise plans)
        $subscriptionCondominiumModel = new SubscriptionCondominium();
        $count += $subscriptionCondominiumModel->countActiveBySubscription($subscription['id']);

        // Check if limit is reached
        return $count < $limitCondominios;
    }

    /**
     * Check if user can create fraction
     * Updated for license-based model - uses LicenseService
     */
    public function canCreateFraction(int $userId, int $condominiumId): bool
    {
        $subscription = $this->getActiveSubscription($userId);
        
        if (!$subscription) {
            return false;
        }

        // Use LicenseService to validate license availability
        $licenseService = new \App\Services\LicenseService();
        $validation = $licenseService->validateLicenseAvailability($subscription['id'], 1);
        
        return $validation['available'] ?? false;
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

    /**
     * Get associated condominiums for subscription
     */
    public function getAssociatedCondominiums(int $subscriptionId): array
    {
        $subscriptionCondominiumModel = new SubscriptionCondominium();
        return $subscriptionCondominiumModel->getBySubscription($subscriptionId, 'all');
    }

    /**
     * Get active condominiums for subscription
     */
    public function getActiveCondominiums(int $subscriptionId): array
    {
        $subscriptionCondominiumModel = new SubscriptionCondominium();
        return $subscriptionCondominiumModel->getActiveBySubscription($subscriptionId);
    }

    /**
     * Calculate used licenses for subscription
     */
    public function calculateUsedLicenses(int $subscriptionId): int
    {
        if (!$this->db) {
            return 0;
        }

        $subscription = $this->findById($subscriptionId);
        if (!$subscription) {
            return 0;
        }

        $planModel = new Plan();
        $plan = $planModel->findById($subscription['plan_id']);
        if (!$plan) {
            return 0;
        }

        $planType = $plan['plan_type'] ?? null;

        if ($planType === 'condominio') {
            // Base plan: count active fractions from single condominium
            if ($subscription['condominium_id']) {
                $fractionModel = new Fraction();
                $count = $fractionModel->getActiveCountByCondominium($subscription['condominium_id']);
                // Return actual count, not minimum (minimum is only for pricing)
                return $count;
            }
        } else {
            // Pro/Enterprise: sum active fractions from all associated condominiums
            $fractionModel = new Fraction();
            $count = $fractionModel->getActiveCountBySubscription($subscriptionId);
            // Return actual count, not minimum (minimum is only for pricing)
            return $count;
        }

        return 0;
    }

    /**
     * Update used licenses cache
     */
    public function updateUsedLicenses(int $subscriptionId, int $count): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE subscriptions SET used_licenses = :count WHERE id = :id");
        return $stmt->execute([
            ':id' => $subscriptionId,
            ':count' => $count
        ]);
    }

    /**
     * Check if condominium can be attached to subscription
     */
    public function canAttachCondominium(int $subscriptionId, int $condominiumId): array
    {
        if (!$this->db) {
            return ['can' => false, 'reason' => 'Database connection not available'];
        }

        $subscription = $this->findById($subscriptionId);
        if (!$subscription) {
            return ['can' => false, 'reason' => 'Subscrição não encontrada'];
        }

        $planModel = new Plan();
        $plan = $planModel->findById($subscription['plan_id']);
        if (!$plan) {
            return ['can' => false, 'reason' => 'Plano não encontrado'];
        }

        // Check if plan allows multiple condominiums
        if ($plan['plan_type'] === 'condominio' && !($plan['allow_multiple_condos'] ?? false)) {
            // Base plan: check if already has a condominium
            if ($subscription['condominium_id']) {
                return ['can' => false, 'reason' => 'Plano Base permite apenas um condomínio'];
            }
        }

        // Check if condominium is already attached
        $subscriptionCondominiumModel = new SubscriptionCondominium();
        $existing = $subscriptionCondominiumModel->getBySubscriptionAndCondominium($subscriptionId, $condominiumId, 'active');
        if ($existing) {
            return ['can' => false, 'reason' => 'Condomínio já está associado'];
        }

        // Check license limits
        $fractionModel = new Fraction();
        $newFractions = $fractionModel->getActiveCountByCondominium($condominiumId);
        $currentLicenses = $subscription['used_licenses'] ?? 0;
        $licenseLimit = $subscription['license_limit'] ?? null;
        $allowOverage = $subscription['allow_overage'] ?? false;

        if ($licenseLimit !== null && !$allowOverage) {
            if ($currentLicenses + $newFractions > $licenseLimit) {
                return [
                    'can' => false, 
                    'reason' => "Excederia o limite de licenças ({$licenseLimit}). Atual: {$currentLicenses}, Novo condomínio: {$newFractions}"
                ];
            }
        }

        return ['can' => true, 'reason' => ''];
    }

    /**
     * Get subscription by condominium ID (for Base plan)
     */
    public function getByCondominiumId(int $condominiumId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT s.*, p.name as plan_name, p.slug as plan_slug, p.price_monthly, p.plan_type, p.license_min, p.license_limit as plan_license_limit
            FROM subscriptions s
            INNER JOIN plans p ON s.plan_id = p.id
            WHERE s.condominium_id = :condominium_id
            AND s.status IN ('trial', 'active')
            ORDER BY s.created_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetch() ?: null;
    }
}

