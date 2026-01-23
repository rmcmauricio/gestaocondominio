<?php

namespace App\Models;

use App\Core\Model;

class SubscriptionCondominium extends Model
{
    protected $table = 'subscription_condominiums';

    /**
     * Attach condominium to subscription
     */
    public function attach(int $subscriptionId, int $condominiumId, ?int $userId = null): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check if already attached
        $existing = $this->getBySubscriptionAndCondominium($subscriptionId, $condominiumId, 'active');
        if ($existing) {
            throw new \Exception("Condomínio já está associado a esta subscrição.");
        }

        // If there's a detached record, reactivate it
        $detached = $this->getBySubscriptionAndCondominium($subscriptionId, $condominiumId, 'detached');
        if ($detached) {
            $stmt = $this->db->prepare("
                UPDATE subscription_condominiums 
                SET status = 'active', 
                    attached_at = NOW(),
                    detached_at = NULL,
                    detached_by = NULL,
                    notes = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $detached['id']]);
            return $detached['id'];
        }

        // Create new association
        $stmt = $this->db->prepare("
            INSERT INTO subscription_condominiums (
                subscription_id, condominium_id, status, attached_at
            )
            VALUES (
                :subscription_id, :condominium_id, 'active', NOW()
            )
        ");

        $stmt->execute([
            ':subscription_id' => $subscriptionId,
            ':condominium_id' => $condominiumId
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Detach condominium from subscription
     */
    public function detach(int $subscriptionId, int $condominiumId, ?int $userId = null, ?string $reason = null): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE subscription_condominiums 
            SET status = 'detached',
                detached_at = NOW(),
                detached_by = :detached_by,
                notes = :notes,
                updated_at = NOW()
            WHERE subscription_id = :subscription_id 
            AND condominium_id = :condominium_id
            AND status = 'active'
        ");

        return $stmt->execute([
            ':subscription_id' => $subscriptionId,
            ':condominium_id' => $condominiumId,
            ':detached_by' => $userId,
            ':notes' => $reason
        ]);
    }

    /**
     * Get associations by subscription
     */
    public function getBySubscription(int $subscriptionId, string $status = 'active'): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT sc.*, c.name as condominium_name, c.address as condominium_address
                FROM subscription_condominiums sc
                INNER JOIN condominiums c ON c.id = sc.condominium_id
                WHERE sc.subscription_id = :subscription_id";
        
        if ($status !== 'all') {
            $sql .= " AND sc.status = :status";
        }
        
        $sql .= " ORDER BY sc.attached_at DESC";

        $stmt = $this->db->prepare($sql);
        $params = [':subscription_id' => $subscriptionId];
        if ($status !== 'all') {
            $params[':status'] = $status;
        }
        $stmt->execute($params);
        
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get association by condominium
     */
    public function getByCondominium(int $condominiumId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT sc.*, s.user_id, s.plan_id, s.status as subscription_status
            FROM subscription_condominiums sc
            INNER JOIN subscriptions s ON s.id = sc.subscription_id
            WHERE sc.condominium_id = :condominium_id
            AND sc.status = 'active'
            ORDER BY sc.attached_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get active associations by subscription
     */
    public function getActiveBySubscription(int $subscriptionId): array
    {
        return $this->getBySubscription($subscriptionId, 'active');
    }

    /**
     * Get by subscription and condominium
     */
    public function getBySubscriptionAndCondominium(int $subscriptionId, int $condominiumId, string $status = 'active'): ?array
    {
        if (!$this->db) {
            return null;
        }

        $sql = "SELECT * FROM subscription_condominiums 
                WHERE subscription_id = :subscription_id 
                AND condominium_id = :condominium_id";
        
        if ($status !== 'all') {
            $sql .= " AND status = :status";
        }
        
        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $params = [
            ':subscription_id' => $subscriptionId,
            ':condominium_id' => $condominiumId
        ];
        if ($status !== 'all') {
            $params[':status'] = $status;
        }
        $stmt->execute($params);
        
        return $stmt->fetch() ?: null;
    }

    /**
     * Count active associations for subscription
     */
    public function countActiveBySubscription(int $subscriptionId): int
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM subscription_condominiums 
            WHERE subscription_id = :subscription_id 
            AND status = 'active'
        ");
        $stmt->execute([':subscription_id' => $subscriptionId]);
        $result = $stmt->fetch();
        
        return $result ? (int)$result['count'] : 0;
    }
}
