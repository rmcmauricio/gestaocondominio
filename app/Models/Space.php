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

        $stmt = $this->db->prepare("
            SELECT * FROM spaces 
            WHERE condominium_id = :condominium_id AND is_active = TRUE
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

        $stmt = $this->db->prepare("
            SELECT * FROM spaces 
            WHERE condominium_id = :condominium_id
            ORDER BY is_active DESC, name ASC
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
                         'deposit_required', 'requires_approval', 'rules', 'available_hours', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'available_hours') {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = !empty($data[$field]) ? json_encode($data[$field]) : null;
                } elseif ($field === 'requires_approval' || $field === 'is_active') {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = (int)$data[$field];
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
}





