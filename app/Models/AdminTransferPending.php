<?php

namespace App\Models;

use App\Core\Model;

class AdminTransferPending extends Model
{
    protected $table = 'admin_transfer_pending';

    /**
     * Create pending admin transfer
     */
    public function createPending(
        int $condominiumId,
        int $userId,
        int $assignedByUserId,
        bool $isProfessionalTransfer = false,
        ?int $fromSubscriptionId = null,
        ?int $toSubscriptionId = null,
        ?string $message = null
    ): int {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check if pending already exists
        $stmt = $this->db->prepare("
            SELECT id FROM admin_transfer_pending 
            WHERE condominium_id = :condominium_id 
            AND user_id = :user_id 
            AND status = 'pending'
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing pending
            $stmt = $this->db->prepare("
                UPDATE admin_transfer_pending 
                SET assigned_by_user_id = :assigned_by_user_id,
                    is_professional_transfer = :is_professional_transfer,
                    from_subscription_id = :from_subscription_id,
                    to_subscription_id = :to_subscription_id,
                    message = :message,
                    created_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':assigned_by_user_id' => $assignedByUserId,
                ':is_professional_transfer' => $isProfessionalTransfer ? 1 : 0,
                ':from_subscription_id' => $fromSubscriptionId,
                ':to_subscription_id' => $toSubscriptionId,
                ':message' => $message,
                ':id' => $existing['id']
            ]);
            return (int)$existing['id'];
        }

        // Create new pending
        $stmt = $this->db->prepare("
            INSERT INTO admin_transfer_pending (
                condominium_id, user_id, assigned_by_user_id,
                is_professional_transfer, from_subscription_id, to_subscription_id,
                message, status
            )
            VALUES (
                :condominium_id, :user_id, :assigned_by_user_id,
                :is_professional_transfer, :from_subscription_id, :to_subscription_id,
                :message, 'pending'
            )
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId,
            ':assigned_by_user_id' => $assignedByUserId,
            ':is_professional_transfer' => $isProfessionalTransfer ? 1 : 0,
            ':from_subscription_id' => $fromSubscriptionId,
            ':to_subscription_id' => $toSubscriptionId,
            ':message' => $message
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get pending transfers for user
     */
    public function getPendingForUser(int $userId): array
    {
        if (!$this->db) {
            return [];
        }

        global $db;
        $stmt = $db->prepare("
            SELECT atp.*, 
                   c.name as condominium_name, c.address,
                   u.name as assigned_by_name, u.email as assigned_by_email
            FROM admin_transfer_pending atp
            INNER JOIN condominiums c ON c.id = atp.condominium_id
            INNER JOIN users u ON u.id = atp.assigned_by_user_id
            WHERE atp.user_id = :user_id
            AND atp.status = 'pending'
            ORDER BY atp.created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Accept pending transfer
     */
    public function accept(int $id, int $userId): bool
    {
        if (!$this->db) {
            return false;
        }

        // Verify it belongs to user and is pending
        $stmt = $this->db->prepare("
            SELECT * FROM admin_transfer_pending 
            WHERE id = :id 
            AND user_id = :user_id 
            AND status = 'pending'
        ");
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId
        ]);
        $pending = $stmt->fetch();

        if (!$pending) {
            return false;
        }

        // Update status
        $stmt = $this->db->prepare("
            UPDATE admin_transfer_pending 
            SET status = 'accepted', accepted_at = NOW()
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Reject pending transfer
     */
    public function reject(int $id, int $userId): bool
    {
        if (!$this->db) {
            return false;
        }

        // Verify it belongs to user and is pending
        $stmt = $this->db->prepare("
            SELECT * FROM admin_transfer_pending 
            WHERE id = :id 
            AND user_id = :user_id 
            AND status = 'pending'
        ");
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId
        ]);
        $pending = $stmt->fetch();

        if (!$pending) {
            return false;
        }

        // Update status
        $stmt = $this->db->prepare("
            UPDATE admin_transfer_pending 
            SET status = 'rejected', rejected_at = NOW()
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Check if user has pending transfer for condominium
     */
    public function hasPending(int $userId, int $condominiumId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT id FROM admin_transfer_pending 
            WHERE user_id = :user_id 
            AND condominium_id = :condominium_id 
            AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        return (bool)$stmt->fetch();
    }
}
