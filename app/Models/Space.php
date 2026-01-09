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
}





