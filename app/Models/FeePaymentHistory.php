<?php

namespace App\Models;

use App\Core\Model;

class FeePaymentHistory extends Model
{
    protected $table = 'fee_payment_history';

    /**
     * Get history by payment
     */
    public function getByPayment(int $paymentId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT h.*, u.name as user_name
            FROM fee_payment_history h
            LEFT JOIN users u ON u.id = h.user_id
            WHERE h.fee_payment_id = :payment_id
            ORDER BY h.created_at DESC
        ");

        $stmt->execute([':payment_id' => $paymentId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get history by fee
     */
    public function getByFee(int $feeId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT h.*, u.name as user_name
            FROM fee_payment_history h
            LEFT JOIN users u ON u.id = h.user_id
            WHERE h.fee_id = :fee_id
            ORDER BY h.created_at DESC
        ");

        $stmt->execute([':fee_id' => $feeId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create history entry
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO fee_payment_history (
                fee_payment_id, fee_id, user_id, action, field_name, old_value, new_value, description, ip_address, user_agent
            )
            VALUES (
                :fee_payment_id, :fee_id, :user_id, :action, :field_name, :old_value, :new_value, :description, :ip_address, :user_agent
            )
        ");

        $stmt->execute([
            ':fee_payment_id' => $data['fee_payment_id'],
            ':fee_id' => $data['fee_id'],
            ':user_id' => $data['user_id'] ?? null,
            ':action' => $data['action'],
            ':field_name' => $data['field_name'] ?? null,
            ':old_value' => $data['old_value'] ?? null,
            ':new_value' => $data['new_value'] ?? null,
            ':description' => $data['description'] ?? null,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Log payment update
     */
    public function logUpdate(int $paymentId, int $feeId, int $userId, array $changes, string $description = null): int
    {
        $changeDescriptions = [];
        foreach ($changes as $field => $values) {
            $changeDescriptions[] = "$field: " . ($values['old'] ?? 'N/A') . " → " . ($values['new'] ?? 'N/A');
        }
        
        $fullDescription = $description ?? implode('; ', $changeDescriptions);
        
        return $this->create([
            'fee_payment_id' => $paymentId,
            'fee_id' => $feeId,
            'user_id' => $userId,
            'action' => 'updated',
            'field_name' => null,
            'old_value' => json_encode($changes),
            'new_value' => null,
            'description' => $fullDescription
        ]);
    }

    /**
     * Log payment deletion
     */
    public function logDeletion(int $paymentId, int $feeId, int $userId, array $paymentData, string $description = null): int
    {
        return $this->create([
            'fee_payment_id' => $paymentId,
            'fee_id' => $feeId,
            'user_id' => $userId,
            'action' => 'deleted',
            'field_name' => null,
            'old_value' => json_encode($paymentData),
            'new_value' => null,
            'description' => $description ?? 'Pagamento eliminado'
        ]);
    }
}
