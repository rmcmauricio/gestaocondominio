<?php

namespace App\Models;

use App\Core\Model;

class Promotion extends Model
{
    protected $table = 'promotions';

    /**
     * Get all promotions
     */
    public function getAll(): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT p.*, pl.name as plan_name
            FROM promotions p
            LEFT JOIN plans pl ON pl.id = p.plan_id
            ORDER BY p.created_at DESC
        ");

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get active promotions
     */
    public function getActive(): array
    {
        if (!$this->db) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            SELECT p.*, pl.name as plan_name
            FROM promotions p
            LEFT JOIN plans pl ON pl.id = p.plan_id
            WHERE p.is_active = TRUE
            AND p.start_date <= :now
            AND p.end_date >= :now
            AND (p.max_uses IS NULL OR p.used_count < p.max_uses)
            ORDER BY p.created_at DESC
        ");

        $stmt->execute([':now' => $now]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find promotion by code
     */
    public function findByCode(string $code): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT p.*, pl.name as plan_name
            FROM promotions p
            LEFT JOIN plans pl ON pl.id = p.plan_id
            WHERE p.code = :code
            LIMIT 1
        ");
        $stmt->execute([':code' => $code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find promotion by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT p.*, pl.name as plan_name
            FROM promotions p
            LEFT JOIN plans pl ON pl.id = p.plan_id
            WHERE p.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get active promotions for a specific plan
     */
    public function getActiveForPlan(int $planId): array
    {
        if (!$this->db) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            SELECT p.*, pl.name as plan_name
            FROM promotions p
            LEFT JOIN plans pl ON pl.id = p.plan_id
            WHERE p.is_active = TRUE
            AND p.start_date <= :now
            AND p.end_date >= :now
            AND (p.max_uses IS NULL OR p.used_count < p.max_uses)
            AND (p.plan_id IS NULL OR p.plan_id = :plan_id)
            ORDER BY p.created_at DESC
        ");

        $stmt->execute([':now' => $now, ':plan_id' => $planId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get visible promotions for a specific plan (promotions that should be displayed automatically)
     */
    public function getVisibleForPlan(int $planId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            SELECT p.*, pl.name as plan_name
            FROM promotions p
            LEFT JOIN plans pl ON pl.id = p.plan_id
            WHERE p.is_active = TRUE
            AND p.is_visible = TRUE
            AND p.start_date <= :now
            AND p.end_date >= :now
            AND (p.max_uses IS NULL OR p.used_count < p.max_uses)
            AND (p.plan_id IS NULL OR p.plan_id = :plan_id)
            ORDER BY p.created_at DESC
            LIMIT 1
        ");

        $stmt->execute([':now' => $now, ':plan_id' => $planId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Validate promotion code for a specific plan
     * Returns array with 'valid' => bool and 'promotion' => array|null or 'error' => string
     */
    public function validateCode(string $code, int $planId, ?int $userId = null): array
    {
        if (!$this->db) {
            return ['valid' => false, 'error' => 'Database connection not available'];
        }

        $promotion = $this->findByCode($code);
        
        if (!$promotion) {
            return ['valid' => false, 'error' => 'Código de promoção inválido'];
        }

        // Check if promotion is active
        if (!$promotion['is_active']) {
            return ['valid' => false, 'error' => 'Esta promoção não está ativa'];
        }

        // Check date range
        $now = date('Y-m-d H:i:s');
        if ($promotion['start_date'] > $now) {
            return ['valid' => false, 'error' => 'Esta promoção ainda não começou'];
        }
        
        if ($promotion['end_date'] < $now) {
            return ['valid' => false, 'error' => 'Esta promoção já expirou'];
        }

        // Check max uses
        if ($promotion['max_uses'] !== null && $promotion['used_count'] >= $promotion['max_uses']) {
            return ['valid' => false, 'error' => 'Esta promoção já atingiu o limite de utilizações'];
        }

        // Check if promotion applies to this plan
        if ($promotion['plan_id'] !== null && $promotion['plan_id'] != $planId) {
            return ['valid' => false, 'error' => 'Este código não se aplica ao plano selecionado'];
        }

        // Check if user already used this code (optional - can be implemented later)
        if ($userId !== null) {
            $checkStmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM subscriptions 
                WHERE user_id = :user_id 
                AND promotion_id = :promotion_id
            ");
            $checkStmt->execute([':user_id' => $userId, ':promotion_id' => $promotion['id']]);
            $result = $checkStmt->fetch();
            if ($result && $result['count'] > 0) {
                return ['valid' => false, 'error' => 'Já utilizou este código de promoção anteriormente'];
            }
        }

        return ['valid' => true, 'promotion' => $promotion];
    }

    /**
     * Create promotion
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check if new columns exist
        $checkStmt = $this->db->query("SHOW COLUMNS FROM promotions LIKE 'is_visible'");
        $hasNewFields = $checkStmt->rowCount() > 0;

        if ($hasNewFields) {
            $stmt = $this->db->prepare("
                INSERT INTO promotions (
                    name, code, description, discount_type, discount_value,
                    plan_id, start_date, end_date, is_active, is_visible, duration_months, max_uses
                )
                VALUES (
                    :name, :code, :description, :discount_type, :discount_value,
                    :plan_id, :start_date, :end_date, :is_active, :is_visible, :duration_months, :max_uses
                )
            ");

            $stmt->execute([
                ':name' => $data['name'],
                ':code' => $data['code'],
                ':description' => $data['description'] ?? null,
                ':discount_type' => $data['discount_type'],
                ':discount_value' => $data['discount_value'],
                ':plan_id' => $data['plan_id'] ?? null,
                ':start_date' => $data['start_date'],
                ':end_date' => $data['end_date'],
                ':is_active' => $data['is_active'] ?? true,
                ':is_visible' => $data['is_visible'] ?? false,
                ':duration_months' => $data['duration_months'] ?? null,
                ':max_uses' => $data['max_uses'] ?? null
            ]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO promotions (
                    name, code, description, discount_type, discount_value,
                    plan_id, start_date, end_date, is_active, max_uses
                )
                VALUES (
                    :name, :code, :description, :discount_type, :discount_value,
                    :plan_id, :start_date, :end_date, :is_active, :max_uses
                )
            ");

            $stmt->execute([
                ':name' => $data['name'],
                ':code' => $data['code'],
                ':description' => $data['description'] ?? null,
                ':discount_type' => $data['discount_type'],
                ':discount_value' => $data['discount_value'],
                ':plan_id' => $data['plan_id'] ?? null,
                ':start_date' => $data['start_date'],
                ':end_date' => $data['end_date'],
                ':is_active' => $data['is_active'] ?? true,
                ':max_uses' => $data['max_uses'] ?? null
            ]);
        }

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update promotion
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        // Check if new columns exist
        $checkStmt = $this->db->query("SHOW COLUMNS FROM promotions LIKE 'is_visible'");
        $hasNewFields = $checkStmt->rowCount() > 0;

        $allowedFields = [
            'name', 'code', 'description', 'discount_type', 'discount_value',
            'plan_id', 'start_date', 'end_date', 'is_active', 'max_uses'
        ];
        
        if ($hasNewFields) {
            $allowedFields[] = 'is_visible';
            $allowedFields[] = 'duration_months';
        }

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'plan_id' && $data[$field] === '') {
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

        $sql = "UPDATE promotions SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Toggle promotion active status
     */
    public function toggleActive(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $promotion = $this->findById($id);
        if (!$promotion) {
            return false;
        }

        $newStatus = !$promotion['is_active'];
        $stmt = $this->db->prepare("UPDATE promotions SET is_active = :is_active WHERE id = :id");
        return $stmt->execute([
            ':id' => $id,
            ':is_active' => $newStatus ? 1 : 0
        ]);
    }

    /**
     * Increment usage count
     */
    public function incrementUsage(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE promotions 
            SET used_count = used_count + 1 
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Check if promotion is valid
     */
    public function isValid(int $id): bool
    {
        $promotion = $this->findById($id);
        if (!$promotion) {
            return false;
        }

        if (!$promotion['is_active']) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        if ($promotion['start_date'] > $now || $promotion['end_date'] < $now) {
            return false;
        }

        if ($promotion['max_uses'] !== null && $promotion['used_count'] >= $promotion['max_uses']) {
            return false;
        }

        return true;
    }
}
