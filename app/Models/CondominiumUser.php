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

        // Ensure boolean values are properly converted to integers for MySQL
        $isPrimary = false;
        if (isset($data['is_primary'])) {
            $isPrimary = filter_var($data['is_primary'], FILTER_VALIDATE_BOOLEAN);
        }
        
        $canViewFinances = true;
        if (isset($data['can_view_finances'])) {
            $canViewFinances = filter_var($data['can_view_finances'], FILTER_VALIDATE_BOOLEAN);
        }
        
        $canVote = true;
        if (isset($data['can_vote'])) {
            $canVote = filter_var($data['can_vote'], FILTER_VALIDATE_BOOLEAN);
        }
        
        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':user_id' => $data['user_id'],
            ':fraction_id' => $data['fraction_id'] ?? null,
            ':role' => $data['role'] ?? 'condomino',
            ':can_view_finances' => $canViewFinances ? 1 : 0,
            ':can_vote' => $canVote ? 1 : 0,
            ':is_primary' => $isPrimary ? 1 : 0,
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

    /**
     * Get user's role in a specific condominium
     */
    public function getUserRole(int $userId, int $condominiumId): ?string
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT role 
            FROM condominium_users 
            WHERE user_id = :user_id 
            AND condominium_id = :condominium_id
            AND (ended_at IS NULL OR ended_at > CURDATE())
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        $result = $stmt->fetch();
        
        return $result ? $result['role'] : null;
    }

    /**
     * Assign admin role to user in condominium
     */
    public function assignAdmin(int $condominiumId, int $userId, int $assignedByUserId): bool
    {
        if (!$this->db) {
            return false;
        }

        // Check if assignment already exists
        $stmt = $this->db->prepare("
            SELECT id FROM condominium_users 
            WHERE user_id = :user_id 
            AND condominium_id = :condominium_id
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing entry
            $stmt = $this->db->prepare("
                UPDATE condominium_users 
                SET role = 'admin', ended_at = NULL
                WHERE id = :id
            ");
            return $stmt->execute([':id' => $existing['id']]);
        } else {
            // Create new entry
            $stmt = $this->db->prepare("
                INSERT INTO condominium_users (
                    condominium_id, user_id, role, started_at
                )
                VALUES (
                    :condominium_id, :user_id, 'admin', :started_at
                )
            ");
            return $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':user_id' => $userId,
                ':started_at' => date('Y-m-d')
            ]);
        }
    }

    /**
     * Get user condominiums with role information
     * Returns both condominiums where user is admin (owner or assigned) and where user is condomino
     */
    public function getUserCondominiumsWithRoles(int $userId): array
    {
        if (!$this->db) {
            return ['admin' => [], 'condomino' => []];
        }

        global $db;
        
        // Get condominiums where user is owner (admin)
        $stmt = $db->prepare("
            SELECT c.*, 'admin' as user_role, 'owner' as admin_type
            FROM condominiums c
            WHERE c.user_id = :user_id
            AND c.is_active = TRUE
        ");
        $stmt->execute([':user_id' => $userId]);
        $ownedCondominiums = $stmt->fetchAll() ?: [];

        // Get condominiums from condominium_users where user is admin
        $stmt = $db->prepare("
            SELECT c.*, cu.role as user_role, 'assigned' as admin_type
            FROM condominium_users cu
            INNER JOIN condominiums c ON c.id = cu.condominium_id
            WHERE cu.user_id = :user_id
            AND cu.role = 'admin'
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            AND c.is_active = TRUE
            AND c.user_id != :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $assignedAdminCondominiums = $stmt->fetchAll() ?: [];

        // Get condominiums from condominium_users where user is condomino
        $stmt = $db->prepare("
            SELECT c.*, cu.role as user_role, cu.fraction_id, f.identifier as fraction_identifier
            FROM condominium_users cu
            INNER JOIN condominiums c ON c.id = cu.condominium_id
            LEFT JOIN fractions f ON f.id = cu.fraction_id
            WHERE cu.user_id = :user_id
            AND cu.role IN ('condomino', 'proprietario', 'arrendatario')
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            AND c.is_active = TRUE
        ");
        $stmt->execute([':user_id' => $userId]);
        $condominoCondominiums = $stmt->fetchAll() ?: [];

        // Merge owned and assigned admin condominiums
        $adminCondominiums = array_merge($ownedCondominiums, $assignedAdminCondominiums);

        return [
            'admin' => $adminCondominiums,
            'condomino' => $condominoCondominiums
        ];
    }
}





