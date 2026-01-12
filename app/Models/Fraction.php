<?php

namespace App\Models;

use App\Core\Model;

class Fraction extends Model
{
    protected $table = 'fractions';

    /**
     * Get all fractions for condominium
     */
    public function getByCondominiumId(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT f.*
            FROM fractions f
            WHERE f.condominium_id = :condominium_id
            AND f.is_active = TRUE
            ORDER BY f.identifier ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find fraction by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM fractions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create fraction
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO fractions (
                condominium_id, identifier, permillage, floor, typology, area, notes
            )
            VALUES (
                :condominium_id, :identifier, :permillage, :floor, :typology, :area, :notes
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':identifier' => $data['identifier'],
            ':permillage' => $data['permillage'] ?? 0,
            ':floor' => $data['floor'] ?? null,
            ':typology' => $data['typology'] ?? null,
            ':area' => $data['area'] ?? null,
            ':notes' => $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update fraction
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
            $params[":$key"] = $value;
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE fractions SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Delete fraction (soft delete)
     */
    public function delete(int $id): bool
    {
        return $this->update($id, ['is_active' => false]);
    }

    /**
     * Get total permillage for condominium
     */
    public function getTotalPermillage(int $condominiumId): float
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT SUM(permillage) as total 
            FROM fractions 
            WHERE condominium_id = :condominium_id AND is_active = TRUE
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        $result = $stmt->fetch();
        return (float)($result['total'] ?? 0);
    }

    /**
     * Get fraction owners
     */
    public function getOwners(int $fractionId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT cu.*, u.name, u.email, u.phone
            FROM condominium_users cu
            INNER JOIN users u ON u.id = cu.user_id
            WHERE cu.fraction_id = :fraction_id
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            ORDER BY cu.is_primary DESC, cu.created_at ASC
        ");

        $stmt->execute([':fraction_id' => $fractionId]);
        return $stmt->fetchAll() ?: [];
    }
}

