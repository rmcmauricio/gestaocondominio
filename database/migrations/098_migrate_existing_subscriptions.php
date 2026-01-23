<?php

/**
 * Migration: Migrate existing subscriptions to license-based model
 * 
 * This migration maps old plans to new license-based plans and:
 * - Associates existing condominiums to subscriptions
 * - Calculates initial license counts
 * - Creates default pricing tiers if they don't exist
 */
class MigrateExistingSubscriptions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if new columns exist
        $checkStmt = $this->db->query("SHOW COLUMNS FROM plans LIKE 'plan_type'");
        if ($checkStmt->rowCount() === 0) {
            return; // New model not yet implemented
        }

        // Map old plan slugs to new plan types
        $planMapping = [
            'start' => 'condominio',
            'pro' => 'professional',
            'business' => 'enterprise'
        ];

        // Get all subscriptions that need migration
        $stmt = $this->db->query("
            SELECT s.*, p.slug as plan_slug, p.plan_type
            FROM subscriptions s
            INNER JOIN plans p ON s.plan_id = p.id
            WHERE s.status IN ('trial', 'active', 'pending')
            AND (s.used_licenses IS NULL OR s.used_licenses = 0)
        ");
        $subscriptions = $stmt->fetchAll() ?: [];

        foreach ($subscriptions as $subscription) {
            $planSlug = $subscription['plan_slug'];
            $planType = $subscription['plan_type'];
            
            // Skip if already migrated
            if ($planType && $subscription['used_licenses'] > 0) {
                continue;
            }

            // Get plan details
            $planStmt = $this->db->prepare("SELECT * FROM plans WHERE id = :id");
            $planStmt->execute([':id' => $subscription['plan_id']]);
            $plan = $planStmt->fetch();

            if (!$plan) {
                continue;
            }

            // Calculate used licenses
            $usedLicenses = 0;
            $licenseMin = (int)($plan['license_min'] ?? 0);

            if ($planType === 'condominio') {
                // Base plan: count from single condominium
                if ($subscription['condominium_id']) {
                    $fractionStmt = $this->db->prepare("
                        SELECT COUNT(*) as count 
                        FROM fractions 
                        WHERE condominium_id = :condominium_id 
                        AND is_active = TRUE
                        AND (archived_at IS NULL OR archived_at = '')
                        AND (license_consumed IS NULL OR license_consumed = TRUE)
                    ");
                    $fractionStmt->execute([':condominium_id' => $subscription['condominium_id']]);
                    $result = $fractionStmt->fetch();
                    $usedLicenses = (int)($result['count'] ?? 0);
                }
            } else {
                // Pro/Enterprise: sum from all associated condominiums
                $subscriptionCondominiumStmt = $this->db->prepare("
                    SELECT condominium_id 
                    FROM subscription_condominiums 
                    WHERE subscription_id = :subscription_id 
                    AND status = 'active'
                ");
                $subscriptionCondominiumStmt->execute([':subscription_id' => $subscription['id']]);
                $condominiums = $subscriptionCondominiumStmt->fetchAll() ?: [];

                foreach ($condominiums as $condo) {
                    $fractionStmt = $this->db->prepare("
                        SELECT COUNT(*) as count 
                        FROM fractions 
                        WHERE condominium_id = :condominium_id 
                        AND is_active = TRUE
                        AND (archived_at IS NULL OR archived_at = '')
                        AND (license_consumed IS NULL OR license_consumed = TRUE)
                    ");
                    $fractionStmt->execute([':condominium_id' => $condo['condominium_id']]);
                    $result = $fractionStmt->fetch();
                    $usedLicenses += (int)($result['count'] ?? 0);
                }
            }

            // Apply minimum
            if ($usedLicenses < $licenseMin) {
                $usedLicenses = $licenseMin;
            }

            // Update subscription
            $updateStmt = $this->db->prepare("
                UPDATE subscriptions 
                SET used_licenses = :used_licenses,
                    license_limit = :license_limit,
                    allow_overage = :allow_overage,
                    charge_minimum = TRUE,
                    proration_mode = 'none'
                WHERE id = :id
            ");

            $updateStmt->execute([
                ':used_licenses' => $usedLicenses,
                ':license_limit' => $plan['license_limit'] ?? null,
                ':allow_overage' => $plan['allow_overage'] ?? false ? 1 : 0,
                ':id' => $subscription['id']
            ]);

            // For Base plan, ensure condominium_id is set if not already
            if ($planType === 'condominio' && !$subscription['condominium_id']) {
                // Try to find user's first condominium
                $condoStmt = $this->db->prepare("
                    SELECT id FROM condominiums 
                    WHERE user_id = :user_id 
                    AND is_active = TRUE 
                    ORDER BY created_at ASC 
                    LIMIT 1
                ");
                $condoStmt->execute([':user_id' => $subscription['user_id']]);
                $condo = $condoStmt->fetch();

                if ($condo) {
                    $this->db->prepare("
                        UPDATE subscriptions 
                        SET condominium_id = :condominium_id 
                        WHERE id = :id
                    ")->execute([
                        ':condominium_id' => $condo['id'],
                        ':id' => $subscription['id']
                    ]);

                    // Also update condominium subscription_id
                    $this->db->prepare("
                        UPDATE condominiums 
                        SET subscription_id = :subscription_id 
                        WHERE id = :id
                    ")->execute([
                        ':subscription_id' => $subscription['id'],
                        ':id' => $condo['id']
                    ]);
                }
            }

            // For Pro/Enterprise, create associations if they don't exist
            if ($planType !== 'condominio' && $plan['allow_multiple_condos']) {
                // Check if user has condominiums not yet associated
                $userCondosStmt = $this->db->prepare("
                    SELECT c.id 
                    FROM condominiums c
                    LEFT JOIN subscription_condominiums sc ON sc.condominium_id = c.id 
                        AND sc.subscription_id = :subscription_id 
                        AND sc.status = 'active'
                    WHERE c.user_id = :user_id 
                    AND c.is_active = TRUE
                    AND sc.id IS NULL
                    LIMIT 5
                ");
                $userCondosStmt->execute([
                    ':subscription_id' => $subscription['id'],
                    ':user_id' => $subscription['user_id']
                ]);
                $userCondos = $userCondosStmt->fetchAll() ?: [];

                foreach ($userCondos as $condo) {
                    // Check license limits before associating
                    $fractionStmt = $this->db->prepare("
                        SELECT COUNT(*) as count 
                        FROM fractions 
                        WHERE condominium_id = :condominium_id 
                        AND is_active = TRUE
                    ");
                    $fractionStmt->execute([':condominium_id' => $condo['id']]);
                    $fractionResult = $fractionStmt->fetch();
                    $newFractions = (int)($fractionResult['count'] ?? 0);

                    $licenseLimit = $plan['license_limit'] ?? null;
                    $allowOverage = $plan['allow_overage'] ?? false;

                    if ($licenseLimit && !$allowOverage) {
                        if ($usedLicenses + $newFractions > $licenseLimit) {
                            continue; // Skip this condominium
                        }
                    }

                    // Create association
                    $this->db->prepare("
                        INSERT INTO subscription_condominiums (
                            subscription_id, condominium_id, status, attached_at
                        )
                        VALUES (
                            :subscription_id, :condominium_id, 'active', NOW()
                        )
                    ")->execute([
                        ':subscription_id' => $subscription['id'],
                        ':condominium_id' => $condo['id']
                    ]);

                    $usedLicenses += $newFractions;
                }

                // Update used licenses again after associations
                if ($usedLicenses < $licenseMin) {
                    $usedLicenses = $licenseMin;
                }

                $this->db->prepare("
                    UPDATE subscriptions 
                    SET used_licenses = :used_licenses 
                    WHERE id = :id
                ")->execute([
                    ':used_licenses' => $usedLicenses,
                    ':id' => $subscription['id']
                ]);
            }
        }

        // Ensure pricing tiers exist for all license-based plans
        $this->ensurePricingTiers();
    }

    public function down(): void
    {
        // Reset license-based fields (optional - be careful with this)
        // This would reset all subscriptions to 0 licenses
        // $this->db->exec("UPDATE subscriptions SET used_licenses = 0, license_limit = NULL, allow_overage = FALSE");
    }

    protected function ensurePricingTiers(): void
    {
        // Check if pricing tiers table exists
        $checkStmt = $this->db->query("SHOW TABLES LIKE 'plan_pricing_tiers'");
        if ($checkStmt->rowCount() === 0) {
            return; // Table doesn't exist yet
        }

        // Get all license-based plans
        $plansStmt = $this->db->query("
            SELECT id, slug, plan_type, license_min 
            FROM plans 
            WHERE plan_type IS NOT NULL 
            AND plan_type != ''
        ");
        $plans = $plansStmt->fetchAll() ?: [];

        foreach ($plans as $plan) {
            // Check if plan has pricing tiers
            $tierCheckStmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM plan_pricing_tiers 
                WHERE plan_id = :plan_id
            ");
            $tierCheckStmt->execute([':plan_id' => $plan['id']]);
            $tierResult = $tierCheckStmt->fetch();

            if ($tierResult && (int)$tierResult['count'] > 0) {
                continue; // Already has tiers
            }

            // Create default tiers based on plan type
            $tiers = $this->getDefaultTiers($plan['plan_type'], $plan['license_min']);

            foreach ($tiers as $tier) {
                $this->db->prepare("
                    INSERT INTO plan_pricing_tiers (
                        plan_id, min_licenses, max_licenses, price_per_license,
                        is_active, sort_order
                    )
                    VALUES (
                        :plan_id, :min_licenses, :max_licenses, :price_per_license,
                        TRUE, :sort_order
                    )
                ")->execute([
                    ':plan_id' => $plan['id'],
                    ':min_licenses' => $tier['min_licenses'],
                    ':max_licenses' => $tier['max_licenses'],
                    ':price_per_license' => $tier['price_per_license'],
                    ':sort_order' => $tier['sort_order']
                ]);
            }
        }
    }

    protected function getDefaultTiers(string $planType, int $licenseMin): array
    {
        switch ($planType) {
            case 'condominio':
                return [
                    ['min_licenses' => 10, 'max_licenses' => 14, 'price_per_license' => 1.00, 'sort_order' => 1],
                    ['min_licenses' => 15, 'max_licenses' => 19, 'price_per_license' => 0.90, 'sort_order' => 2],
                    ['min_licenses' => 20, 'max_licenses' => 29, 'price_per_license' => 0.80, 'sort_order' => 3],
                    ['min_licenses' => 30, 'max_licenses' => 39, 'price_per_license' => 0.70, 'sort_order' => 4],
                    ['min_licenses' => 40, 'max_licenses' => null, 'price_per_license' => 0.65, 'sort_order' => 5],
                ];
            case 'professional':
                return [
                    ['min_licenses' => 50, 'max_licenses' => 99, 'price_per_license' => 0.60, 'sort_order' => 1],
                    ['min_licenses' => 100, 'max_licenses' => 199, 'price_per_license' => 0.50, 'sort_order' => 2],
                    ['min_licenses' => 200, 'max_licenses' => 499, 'price_per_license' => 0.40, 'sort_order' => 3],
                    ['min_licenses' => 500, 'max_licenses' => null, 'price_per_license' => 0.30, 'sort_order' => 4],
                ];
            case 'enterprise':
                return [
                    ['min_licenses' => 200, 'max_licenses' => 499, 'price_per_license' => 0.35, 'sort_order' => 1],
                    ['min_licenses' => 500, 'max_licenses' => 999, 'price_per_license' => 0.28, 'sort_order' => 2],
                    ['min_licenses' => 1000, 'max_licenses' => 1999, 'price_per_license' => 0.22, 'sort_order' => 3],
                    ['min_licenses' => 2000, 'max_licenses' => null, 'price_per_license' => 0.18, 'sort_order' => 4],
                ];
            default:
                return [];
        }
    }
}
