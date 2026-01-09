<?php

namespace App\Models;

use App\Core\Model;

class CondominiumUser extends Model
{
    protected $table = 'condominium_users';

    /**
     * Associate user with fraction
     */
    public function associate(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO condominium_users (
                condominium_id, user_id, fraction_id, role,
                can_view_finances, can_vote, is_primary, started_at
            )
            VALUES (
                :condominium_id, :user_id, :fraction_id, :role,
                :can_view_finances, :can_vote, :is_primary, :started_at
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':user_id' => $data['user_id'],
            ':fraction_id' => $data['fraction_id'] ?? null,
            ':role' => $data['role'] ?? 'condomino',
            ':can_view_finances' => $data['can_view_finances'] ?? true,
            ':can_vote' => $data['can_vote'] ?? true,
            ':is_primary' => $data['is_primary'] ?? false,
            ':started_at' => $data['started_at'] ?? date('Y-m-d')
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get user condominiums
     */
    public function getUserCondominiums(int $userId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT cu.*, c.name as condominium_name, c.address,
                   f.identifier as fraction_identifier
            FROM condominium_users cu
            INNER JOIN condominiums c ON c.id = cu.condominium_id
            LEFT JOIN fractions f ON f.id = cu.fraction_id
            WHERE cu.user_id = :user_id
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            AND c.is_active = TRUE
            ORDER BY cu.created_at DESC
        ");

        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Remove association
     */
    public function removeAssociation(int $id, string $endDate = null): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE condominium_users 
            SET ended_at = :ended_at 
            WHERE id = :id
        ");

        return $stmt->execute([
            ':ended_at' => $endDate ?? date('Y-m-d'),
            ':id' => $id
        ]);
    }
}





