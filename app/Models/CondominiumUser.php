<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Security;

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
                condominium_id, user_id, fraction_id, role, nif, phone, alternative_address,
                can_view_finances, can_vote, is_primary, started_at
            )
            VALUES (
                :condominium_id, :user_id, :fraction_id, :role, :nif, :phone, :alternative_address,
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
     * Update contact information for a condominium user association
     */
    public function updateContactInfo(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE condominium_users 
            SET nif = :nif,
                phone = :phone,
                alternative_address = :alternative_address
            WHERE id = :id
        ");

        return $stmt->execute([
            ':nif' => !empty($data['nif']) ? Security::sanitize($data['nif']) : null,
            ':phone' => !empty($data['phone']) ? Security::sanitize($data['phone']) : null,
            ':alternative_address' => !empty($data['alternative_address']) ? Security::sanitize($data['alternative_address']) : null,
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
     * Remove admin role from user in condominium
     */
    public function removeAdmin(int $condominiumId, int $userId, int $removedByUserId): bool
    {
        if (!$this->db) {
            return false;
        }

        // Check if user is owner - cannot remove owner's admin role
        global $db;
        $stmt = $db->prepare("SELECT user_id FROM condominiums WHERE id = :condominium_id AND user_id = :user_id");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId
        ]);
        $isOwner = $stmt->fetch();
        
        if ($isOwner) {
            // Cannot remove admin role from owner
            return false;
        }

        // Find admin entry in condominium_users
        $stmt = $this->db->prepare("
            SELECT id FROM condominium_users 
            WHERE user_id = :user_id 
            AND condominium_id = :condominium_id
            AND role = 'admin'
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        $adminEntry = $stmt->fetch();

        if ($adminEntry) {
            // Check if user has other roles (condomino, proprietario, etc.)
            // If yes, change role to condomino; if no, end the association
            $stmt = $this->db->prepare("
                SELECT id, role FROM condominium_users 
                WHERE user_id = :user_id 
                AND condominium_id = :condominium_id
                AND id != :admin_id
                AND (ended_at IS NULL OR ended_at > CURDATE())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':condominium_id' => $condominiumId,
                ':admin_id' => $adminEntry['id']
            ]);
            $otherRoles = $stmt->fetchAll();

            if (!empty($otherRoles)) {
                // User has other roles, just change admin role to condomino
                $stmt = $this->db->prepare("
                    UPDATE condominium_users 
                    SET role = 'condomino'
                    WHERE id = :id
                ");
                return $stmt->execute([':id' => $adminEntry['id']]);
            } else {
                // User only has admin role, end the association
                $stmt = $this->db->prepare("
                    UPDATE condominium_users 
                    SET ended_at = :ended_at
                    WHERE id = :id
                ");
                return $stmt->execute([
                    ':id' => $adminEntry['id'],
                    ':ended_at' => date('Y-m-d')
                ]);
            }
        }

        return false;
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

    /**
     * Sync user profile data with fraction associations
     * Updates phone and NIF in condominium_users based on user profile
     * NIF: if user NIF doesn't exist in fraction or is different, use user NIF (user data prevails)
     * Phone: always sync from user profile
     */
    public function syncUserDataWithFractions(int $userId, array $userData): bool
    {
        if (!$this->db) {
            return false;
        }

        $userPhone = !empty($userData['phone']) ? Security::sanitize($userData['phone']) : null;
        $userNif = !empty($userData['nif']) ? Security::sanitize($userData['nif']) : null;

        // Get all active associations for this user
        $stmt = $this->db->prepare("
            SELECT id, phone, nif 
            FROM condominium_users 
            WHERE user_id = :user_id 
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $stmt->execute([':user_id' => $userId]);
        $associations = $stmt->fetchAll() ?: [];

        foreach ($associations as $association) {
            $updateFields = [];
            $updateParams = [':id' => $association['id']];

            // Always sync phone from user profile
            if ($userPhone !== null) {
                $updateFields[] = 'phone = :phone';
                $updateParams[':phone'] = $userPhone;
            } else {
                // If user phone is null, clear fraction phone too
                $updateFields[] = 'phone = NULL';
            }

            // Sync NIF: if user NIF doesn't exist in fraction or is different, use user NIF
            if ($userNif !== null) {
                $fractionNif = $association['nif'] ?? null;
                // If fraction doesn't have NIF or it's different from user NIF, update with user NIF
                if (empty($fractionNif) || $fractionNif !== $userNif) {
                    $updateFields[] = 'nif = :nif';
                    $updateParams[':nif'] = $userNif;
                }
            }

            // Only update if there are fields to update
            if (!empty($updateFields)) {
                $updateSql = "UPDATE condominium_users SET " . implode(', ', $updateFields) . " WHERE id = :id";
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->execute($updateParams);
            }
        }

        return true;
    }
}





