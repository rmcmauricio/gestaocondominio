<?php

namespace App\Models;

use App\Core\Model;

class FeePayment extends Model
{
    protected $table = 'fee_payments';

    /**
     * Get payments for a fee
     */
    public function getByFee(int $feeId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT fp.*, u.name as created_by_name, ft.id as transaction_id, ba.name as account_name
            FROM fee_payments fp
            LEFT JOIN users u ON u.id = fp.created_by
            LEFT JOIN financial_transactions ft ON ft.id = fp.financial_transaction_id
            LEFT JOIN bank_accounts ba ON ba.id = ft.bank_account_id
            WHERE fp.fee_id = :fee_id
            ORDER BY fp.payment_date DESC, fp.created_at DESC
        ");

        $stmt->execute([':fee_id' => $feeId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get total paid amount for a fee
     */
    public function getTotalPaid(int $feeId): float
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT SUM(amount) as total 
            FROM fee_payments 
            WHERE fee_id = :fee_id
        ");

        $stmt->execute([':fee_id' => $feeId]);
        $result = $stmt->fetch();
        return (float)($result['total'] ?? 0);
    }

    /**
     * Create payment
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO fee_payments (
                fee_id, financial_transaction_id, amount, payment_method, reference, payment_date, notes, created_by
            )
            VALUES (
                :fee_id, :financial_transaction_id, :amount, :payment_method, :reference, :payment_date, :notes, :created_by
            )
        ");

        $stmt->execute([
            ':fee_id' => $data['fee_id'],
            ':financial_transaction_id' => $data['financial_transaction_id'] ?? null,
            ':amount' => $data['amount'],
            ':payment_method' => $data['payment_method'],
            ':reference' => $data['reference'] ?? null,
            ':payment_date' => $data['payment_date'] ?? date('Y-m-d'),
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $data['created_by'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Delete payment
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM fee_payments WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}





