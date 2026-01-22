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

    /**
     * Get all plans (active and inactive)
     */
    public function getAll(): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT * FROM plans 
            ORDER BY sort_order ASC, price_monthly ASC
        ");

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create new plan
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO plans (
                name, slug, description, price_monthly, price_yearly,
                limit_condominios, limit_fracoes, features, is_active, sort_order
            )
            VALUES (
                :name, :slug, :description, :price_monthly, :price_yearly,
                :limit_condominios, :limit_fracoes, :features, :is_active, :sort_order
            )
        ");

        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':description' => $data['description'] ?? null,
            ':price_monthly' => $data['price_monthly'],
            ':price_yearly' => $data['price_yearly'] ?? null,
            ':limit_condominios' => $data['limit_condominios'] ?? null,
            ':limit_fracoes' => $data['limit_fracoes'] ?? null,
            ':features' => isset($data['features']) ? json_encode($data['features']) : null,
            ':is_active' => $data['is_active'] ?? true,
            ':sort_order' => $data['sort_order'] ?? 0
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update plan
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        $allowedFields = [
            'name', 'slug', 'description', 'price_monthly', 'price_yearly',
            'limit_condominios', 'limit_fracoes', 'features', 'is_active', 'sort_order'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'features' && is_array($data[$field])) {
                    $fields[] = "{$field} = :{$field}";
                    $params[":{$field}"] = json_encode($data[$field]);
                } else {
                    $fields[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $data[$field];
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE plans SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Toggle plan active status
     */
    public function toggleActive(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $plan = $this->findById($id);
        if (!$plan) {
            return false;
        }

        $newStatus = !$plan['is_active'];
        $stmt = $this->db->prepare("UPDATE plans SET is_active = :is_active WHERE id = :id");
        return $stmt->execute([
            ':id' => $id,
            ':is_active' => $newStatus ? 1 : 0
        ]);
    }

    /**
     * Delete plan (check for active subscriptions first)
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Check if plan has active subscriptions
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM subscriptions 
            WHERE plan_id = :plan_id 
            AND status IN ('trial', 'active')
        ");
        $stmt->execute([':plan_id' => $id]);
        $result = $stmt->fetch();

        if ($result && $result['count'] > 0) {
            throw new \Exception("Não é possível deletar o plano pois existem subscrições ativas associadas.");
        }

        $stmt = $this->db->prepare("DELETE FROM plans WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}





