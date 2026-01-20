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
            // Convert boolean to integer for MySQL TINYINT columns
            if (is_bool($value)) {
                $params[":$key"] = $value ? 1 : 0;
            } else {
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE fractions SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Check if fraction has fees
     */
    public function hasFees(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM fees WHERE fraction_id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        
        return ($result && (int)$result['count'] > 0);
    }

    /**
     * Check if fraction has payments
     */
    public function hasPayments(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM fee_payments fp
            INNER JOIN fees f ON f.id = fp.fee_id
            WHERE f.fraction_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        
        return ($result && (int)$result['count'] > 0);
    }

    /**
     * Delete fraction (soft delete)
     * Only allows deletion if fraction has no fees or payments
     */
    public function delete(int $id): bool
    {
        // Check if fraction has fees or payments
        if ($this->hasFees($id) || $this->hasPayments($id)) {
            return false;
        }

        // Convert boolean to integer for MySQL
        return $this->update($id, ['is_active' => 0]);
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

    /**
     * Para cada fraction_id: owner_name (1.º condómino) e floor.
     * Retorna [fraction_id => ['owner_name'=>string, 'floor'=>string], ...].
     */
    public function getOwnerAndFloorByFractionIds(array $fractionIds): array
    {
        if (!$this->db || empty($fractionIds)) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $fractionIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT f.id,
                   f.floor,
                   (SELECT u.name FROM condominium_users cu
                    INNER JOIN users u ON u.id = cu.user_id
                    WHERE cu.fraction_id = f.id
                      AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
                    ORDER BY cu.is_primary DESC, cu.created_at ASC
                    LIMIT 1) AS owner_name
            FROM fractions f
            WHERE f.id IN ({$placeholders})
        ");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = [
                'owner_name' => trim((string)($r['owner_name'] ?? '')) ?: null,
                'floor' => trim((string)($r['floor'] ?? '')) ?: null
            ];
        }
        return $out;
    }
}

