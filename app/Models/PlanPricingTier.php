<?php

namespace App\Models;

use App\Core\Model;

class PlanPricingTier extends Model
{
    protected $table = 'plan_pricing_tiers';

    /**
     * Get pricing tiers for a plan
     */
    public function getByPlanId(int $planId, bool $activeOnly = true): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT * FROM plan_pricing_tiers WHERE plan_id = :plan_id";
        if ($activeOnly) {
            $sql .= " AND is_active = TRUE";
        }
        $sql .= " ORDER BY sort_order ASC, min_licenses ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':plan_id' => $planId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find the pricing tier that applies to a specific license count
     */
    public function findTierForCount(int $planId, int $count): ?array
    {
        if (!$this->db) {
            return null;
        }

        // Get all active tiers
        $tiers = $this->getByPlanId($planId, true);
        
        if (empty($tiers)) {
            return null;
        }
        
        // Sort tiers by min_licenses descending to find the highest applicable tier first
        usort($tiers, function($a, $b) {
            return $b['min_licenses'] <=> $a['min_licenses'];
        });
        
        // Find the tier where count falls within range
        foreach ($tiers as $tier) {
            if ($count >= $tier['min_licenses']) {
                // Check if there's a max limit
                if ($tier['max_licenses'] === null || $count <= $tier['max_licenses']) {
                    return $tier;
                }
            }
        }

        // If no tier found, return the lowest tier (shouldn't happen if data is correct)
        // Reset sort to ascending to get the lowest tier
        usort($tiers, function($a, $b) {
            return $a['min_licenses'] <=> $b['min_licenses'];
        });
        return $tiers[0];
    }

    /**
     * Create new pricing tier
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO plan_pricing_tiers (
                plan_id, min_licenses, max_licenses, price_per_license, 
                is_active, sort_order
            )
            VALUES (
                :plan_id, :min_licenses, :max_licenses, :price_per_license,
                :is_active, :sort_order
            )
        ");

        $stmt->execute([
            ':plan_id' => $data['plan_id'],
            ':min_licenses' => $data['min_licenses'],
            ':max_licenses' => $data['max_licenses'] ?? null,
            ':price_per_license' => $data['price_per_license'],
            ':is_active' => $data['is_active'] ?? true,
            ':sort_order' => $data['sort_order'] ?? 0
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update pricing tier
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
            if (is_bool($value)) {
                $params[":$key"] = $value ? 1 : 0;
            } else {
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE plan_pricing_tiers SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Delete pricing tier
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM plan_pricing_tiers WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Find tier by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM plan_pricing_tiers WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
