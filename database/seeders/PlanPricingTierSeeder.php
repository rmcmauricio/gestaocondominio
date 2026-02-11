<?php

class PlanPricingTierSeeder
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function run(): void
    {
        // Get plan IDs by slug
        $planIds = $this->getPlanIds();

        if (empty($planIds)) {
            return; // Plans not found
        }

        // Seed pricing tiers for Condomínio plan
        if (isset($planIds['condominio'])) {
            $this->seedCondominioTiers($planIds['condominio']);
        }

        // Seed pricing tiers for Profissional plan
        if (isset($planIds['professional'])) {
            $this->seedProfissionalTiers($planIds['professional']);
        }

        // Seed pricing tiers for Enterprise plan
        if (isset($planIds['enterprise'])) {
            $this->seedEnterpriseTiers($planIds['enterprise']);
        }
    }

    protected function getPlanIds(): array
    {
        $stmt = $this->db->query("SELECT id, slug FROM plans WHERE slug IN ('condominio', 'professional', 'enterprise')");
        $plans = $stmt->fetchAll();

        $ids = [];
        foreach ($plans as $plan) {
            $ids[$plan['slug']] = $plan['id'];
        }

        return $ids;
    }

    protected function seedCondominioTiers(int $planId): void
    {
        $tiers = [
            ['min_licenses' => 10, 'max_licenses' => 19, 'price_per_license' => 1.00, 'sort_order' => 1],
            ['min_licenses' => 20, 'max_licenses' => 39, 'price_per_license' => 0.95, 'sort_order' => 2],
            ['min_licenses' => 40, 'max_licenses' => null, 'price_per_license' => 0.90, 'sort_order' => 3],
        ];

        $this->insertTiers($planId, $tiers);
    }

    protected function seedProfissionalTiers(int $planId): void
    {
        $tiers = [
            ['min_licenses' => 50, 'max_licenses' => 199, 'price_per_license' => 0.85, 'sort_order' => 1],
            ['min_licenses' => 200, 'max_licenses' => 499, 'price_per_license' => 0.80, 'sort_order' => 2],
            ['min_licenses' => 500, 'max_licenses' => null, 'price_per_license' => 0.75, 'sort_order' => 3],
        ];

        $this->insertTiers($planId, $tiers);
    }

    protected function seedEnterpriseTiers(int $planId): void
    {
        // Enterprise tiers - o mais barato a 0.65€
        $tiers = [
            ['min_licenses' => 200, 'max_licenses' => 499, 'price_per_license' => 0.80, 'sort_order' => 1],
            ['min_licenses' => 500, 'max_licenses' => 1999, 'price_per_license' => 0.75, 'sort_order' => 2],
            ['min_licenses' => 2000, 'max_licenses' => null, 'price_per_license' => 0.65, 'sort_order' => 3],
        ];

        $this->insertTiers($planId, $tiers);
    }

    protected function insertTiers(int $planId, array $tiers): void
    {
        $checkStmt = $this->db->prepare("
            SELECT id FROM plan_pricing_tiers
            WHERE plan_id = :plan_id AND min_licenses = :min_licenses
            LIMIT 1
        ");

        $insertStmt = $this->db->prepare("
            INSERT INTO plan_pricing_tiers (
                plan_id, min_licenses, max_licenses, price_per_license,
                is_active, sort_order
            )
            VALUES (
                :plan_id, :min_licenses, :max_licenses, :price_per_license,
                :is_active, :sort_order
            )
        ");

        $updateStmt = $this->db->prepare("
            UPDATE plan_pricing_tiers SET
                max_licenses = :max_licenses,
                price_per_license = :price_per_license,
                is_active = :is_active,
                sort_order = :sort_order,
                updated_at = NOW()
            WHERE plan_id = :plan_id AND min_licenses = :min_licenses
        ");

        foreach ($tiers as $tier) {
            // Check if tier already exists
            $checkStmt->execute([
                ':plan_id' => $planId,
                ':min_licenses' => $tier['min_licenses']
            ]);
            $existing = $checkStmt->fetch();

            $tierData = [
                ':plan_id' => $planId,
                ':min_licenses' => $tier['min_licenses'],
                ':max_licenses' => $tier['max_licenses'],
                ':price_per_license' => $tier['price_per_license'],
                ':is_active' => true,
                ':sort_order' => $tier['sort_order']
            ];

            if ($existing) {
                // Update existing tier
                $updateStmt->execute($tierData);
            } else {
                // Insert new tier
                $insertStmt->execute($tierData);
            }
        }
    }
}
