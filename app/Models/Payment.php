<?php

namespace App\Models;

use App\Core\Model;

class Payment extends Model
{
    protected $table = 'payments';

    /**
     * Create payment record
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO payments (
                invoice_id, subscription_id, user_id, amount,
                payment_method, status, external_payment_id, reference, metadata
            )
            VALUES (
                :invoice_id, :subscription_id, :user_id, :amount,
                :payment_method, :status, :external_payment_id, :reference, :metadata
            )
        ");

        $stmt->execute([
            ':invoice_id' => $data['invoice_id'] ?? null,
            ':subscription_id' => $data['subscription_id'] ?? null,
            ':user_id' => $data['user_id'],
            ':amount' => $data['amount'],
            ':payment_method' => $data['payment_method'],
            ':status' => $data['status'] ?? 'pending',
            ':external_payment_id' => $data['external_payment_id'] ?? null,
            ':reference' => $data['reference'] ?? null,
            ':metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update payment status
     */
    public function updateStatus(int $id, string $status, ?string $externalPaymentId = null): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE payments 
            SET status = :status,
                external_payment_id = COALESCE(:external_payment_id, external_payment_id),
                processed_at = CASE WHEN :status = 'completed' THEN NOW() ELSE processed_at END,
                updated_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':external_payment_id' => $externalPaymentId
        ]);
    }

    /**
     * Find payment by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find payment by external ID
     */
    public function findByExternalId(string $externalPaymentId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM payments WHERE external_payment_id = :external_payment_id LIMIT 1");
        $stmt->execute([':external_payment_id' => $externalPaymentId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get payments by user
     */
    public function getByUserId(int $userId, int $limit = 50): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT p.*, s.plan_id, pl.name as plan_name
            FROM payments p
            LEFT JOIN subscriptions s ON s.id = p.subscription_id
            LEFT JOIN plans pl ON pl.id = s.plan_id
            WHERE p.user_id = :user_id
            ORDER BY p.created_at DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get payments by subscription
     */
    public function getBySubscriptionId(int $subscriptionId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM payments 
            WHERE subscription_id = :subscription_id
            ORDER BY created_at DESC
        ");

        $stmt->execute([':subscription_id' => $subscriptionId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get pending payments
     */
    public function getPendingPayments(): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT * FROM payments 
            WHERE status IN ('pending', 'processing')
            ORDER BY created_at ASC
        ");

        return $stmt->fetchAll() ?: [];
    }
}





