<?php

namespace App\Models;

use App\Core\Model;

class Plan extends Model
{
    protected $table = 'plans';

    /**
     * Get all active plans
     */
    public function getActivePlans(): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT * FROM plans 
            WHERE is_active = TRUE 
            ORDER BY sort_order ASC, price_monthly ASC
        ");

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find plan by slug
     */
    public function findBySlug(string $slug): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM plans WHERE slug = :slug AND is_active = TRUE LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find plan by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM plans WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get plan features
     */
    public function getFeatures(int $planId): array
    {
        $plan = $this->findById($planId);
        if (!$plan || empty($plan['features'])) {
            return [];
        }

        return json_decode($plan['features'], true) ?: [];
    }

    /**
     * Check if plan has feature
     */
    public function hasFeature(int $planId, string $feature): bool
    {
        $features = $this->getFeatures($planId);
        return isset($features[$feature]) && $features[$feature] === true;
    }
}





