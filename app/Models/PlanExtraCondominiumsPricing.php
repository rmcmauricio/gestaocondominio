<?php

namespace App\Models;

use App\Core\Model;

class PlanExtraCondominiumsPricing extends Model
{
    protected $table = 'plan_extra_condominiums_pricing';

    /**
     * Get all pricing tiers for a plan
     */
    public function getByPlanId(int $planId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT p.*, pl.name as plan_name
            FROM plan_extra_condominiums_pricing p
            INNER JOIN plans pl ON pl.id = p.plan_id
            WHERE p.plan_id = :plan_id
            ORDER BY p.sort_order ASC, p.min_condominios ASC
        ");

        $stmt->execute([':plan_id' => $planId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get pricing tier by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT p.*, pl.name as plan_name
            FROM plan_extra_condominiums_pricing p
            INNER JOIN plans pl ON pl.id = p.plan_id
            WHERE p.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get price per condominium for a given number of condominiums
     */
    public function getPriceForCondominiums(int $planId, int $numCondominios): ?float
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT price_per_condominium
            FROM plan_extra_condominiums_pricing
            WHERE plan_id = :plan_id
            AND is_active = TRUE
            AND min_condominios <= :num_condominios
            AND (max_condominios IS NULL OR max_condominios >= :num_condominios2)
            ORDER BY min_condominios DESC
            LIMIT 1
        ");

        $stmt->execute([
            ':plan_id' => $planId,
            ':num_condominios' => $numCondominios,
            ':num_condominios2' => $numCondominios
        ]);

        $result = $stmt->fetch();
        return $result ? (float)$result['price_per_condominium'] : null;
    }

    /**
     * Create pricing tier
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO plan_extra_condominiums_pricing (
                plan_id, min_condominios, max_condominios, price_per_condominium, is_active, sort_order
            )
            VALUES (
                :plan_id, :min_condominios, :max_condominios, :price_per_condominium, :is_active, :sort_order
            )
        ");

        $stmt->execute([
            ':plan_id' => $data['plan_id'],
            ':min_condominios' => $data['min_condominios'],
            ':max_condominios' => $data['max_condominios'] ?? null,
            ':price_per_condominium' => $data['price_per_condominium'],
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

        $allowedFields = [
            'plan_id', 'min_condominios', 'max_condominios', 'price_per_condominium', 'is_active', 'sort_order'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'max_condominios' && $data[$field] === '') {
                    $fields[] = "{$field} = NULL";
                } else {
                    $fields[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $data[$field];
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE plan_extra_condominiums_pricing SET " . implode(', ', $fields) . " WHERE id = :id";
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

        $stmt = $this->db->prepare("DELETE FROM plan_extra_condominiums_pricing WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Toggle active status
     */
    public function toggleActive(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $tier = $this->findById($id);
        if (!$tier) {
            return false;
        }

        $newStatus = !$tier['is_active'];
        $stmt = $this->db->prepare("UPDATE plan_extra_condominiums_pricing SET is_active = :is_active WHERE id = :id");
        return $stmt->execute([
            ':id' => $id,
            ':is_active' => $newStatus ? 1 : 0
        ]);
    }
}
