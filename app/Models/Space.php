<?php

namespace App\Models;

use App\Core\Model;

class Space extends Model
{
    protected $table = 'spaces';

    /**
     * Get spaces by condominium
     */
    public function getByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        // Auto-unblock spaces that have expired
        $this->checkAndUnblockExpiredSpaces($condominiumId);

        $stmt = $this->db->prepare("
            SELECT * FROM spaces 
            WHERE condominium_id = :condominium_id 
            AND is_active = TRUE 
            AND is_blocked = FALSE
            ORDER BY name ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create space
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO spaces (
                condominium_id, name, description, type, capacity,
                price_per_hour, price_per_day, deposit_required,
                requires_approval, rules, available_hours
            )
            VALUES (
                :condominium_id, :name, :description, :type, :capacity,
                :price_per_hour, :price_per_day, :deposit_required,
                :requires_approval, :rules, :available_hours
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':type' => $data['type'] ?? null,
            ':capacity' => $data['capacity'] ?? null,
            ':price_per_hour' => $data['price_per_hour'] ?? 0,
            ':price_per_day' => $data['price_per_day'] ?? 0,
            ':deposit_required' => $data['deposit_required'] ?? 0,
            ':requires_approval' => isset($data['requires_approval']) ? (int)$data['requires_approval'] : 1,
            ':rules' => $data['rules'] ?? null,
            ':available_hours' => !empty($data['available_hours']) ? json_encode($data['available_hours']) : null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get all spaces by condominium (including inactive)
     */
    public function getAllByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        // Auto-unblock spaces that have expired
        $this->checkAndUnblockExpiredSpaces($condominiumId);

        $stmt = $this->db->prepare("
            SELECT * FROM spaces 
            WHERE condominium_id = :condominium_id
            ORDER BY is_active DESC, is_blocked DESC, name ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find space by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM spaces WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update space
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['name', 'description', 'type', 'capacity', 'price_per_hour', 'price_per_day', 
                         'deposit_required', 'requires_approval', 'rules', 'available_hours', 'is_active',
                         'is_blocked', 'blocked_until', 'block_reason'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'available_hours') {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = !empty($data[$field]) ? json_encode($data[$field]) : null;
                } elseif ($field === 'requires_approval' || $field === 'is_active' || $field === 'is_blocked') {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = (int)$data[$field];
                } elseif ($field === 'blocked_until') {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = !empty($data[$field]) ? $data[$field] : null;
                } else {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE spaces SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete space (soft delete by setting is_active to false)
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Check if space has active reservations
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM reservations 
            WHERE space_id = :id 
            AND status IN ('pending', 'approved')
        ");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        
        if (($result['count'] ?? 0) > 0) {
            // Soft delete - set is_active to false
            $stmt = $this->db->prepare("UPDATE spaces SET is_active = FALSE, updated_at = NOW() WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } else {
            // Hard delete if no active reservations
            $stmt = $this->db->prepare("DELETE FROM spaces WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        }
    }

    /**
     * Check if space is blocked
     */
    public function isBlocked(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Auto-unblock if expired
        $this->checkAndUnblockExpiredSpace($id);

        $stmt = $this->db->prepare("
            SELECT is_blocked, blocked_until 
            FROM spaces 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $space = $stmt->fetch();

        if (!$space || !$space['is_blocked']) {
            return false;
        }

        // If blocked_until is set and has passed, space is not blocked
        if ($space['blocked_until']) {
            $blockedUntil = new \DateTime($space['blocked_until']);
            $now = new \DateTime();
            if ($blockedUntil <= $now) {
                return false;
            }
        }

        return true;
    }

    /**
     * Block space
     */
    public function block(int $id, ?string $blockedUntil = null, ?string $reason = null): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE spaces 
            SET is_blocked = TRUE, 
                blocked_until = :blocked_until, 
                block_reason = :block_reason,
                updated_at = NOW() 
            WHERE id = :id
        ");

        return $stmt->execute([
            ':id' => $id,
            ':blocked_until' => $blockedUntil,
            ':block_reason' => $reason
        ]);
    }

    /**
     * Unblock space
     */
    public function unblock(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE spaces 
            SET is_blocked = FALSE, 
                blocked_until = NULL, 
                block_reason = NULL,
                updated_at = NOW() 
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $id]);
    }

    /**
     * Check and unblock expired spaces for a condominium
     */
    public function checkAndUnblockExpiredSpaces(int $condominiumId): void
    {
        if (!$this->db) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE spaces 
            SET is_blocked = FALSE, 
                blocked_until = NULL, 
                block_reason = NULL,
                updated_at = NOW() 
            WHERE condominium_id = :condominium_id 
            AND is_blocked = TRUE 
            AND blocked_until IS NOT NULL 
            AND blocked_until <= NOW()
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
    }

    /**
     * Check and unblock a specific expired space
     */
    public function checkAndUnblockExpiredSpace(int $id): void
    {
        if (!$this->db) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE spaces 
            SET is_blocked = FALSE, 
                blocked_until = NULL, 
                block_reason = NULL,
                updated_at = NOW() 
            WHERE id = :id 
            AND is_blocked = TRUE 
            AND blocked_until IS NOT NULL 
            AND blocked_until <= NOW()
        ");

        $stmt->execute([':id' => $id]);
    }
}





