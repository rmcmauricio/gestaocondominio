<?php

namespace App\Models;

use App\Core\Model;

class FeePayment extends Model
{
    protected $table = 'fee_payments';

    /**
     * Get payments by financial transaction ID
     */
    public function getByFinancialTransactionId(int $financialTransactionId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM fee_payments
            WHERE financial_transaction_id = :financial_transaction_id
            ORDER BY id
        ");
        $stmt->execute([':financial_transaction_id' => $financialTransactionId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get payments for a fee
     */
    public function getByFee(int $feeId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT fp.*, u.name as created_by_name,
                ft.id as transaction_id, ft.reference as ft_reference,
                ba.name as account_name, ba.account_type
            FROM fee_payments fp
            LEFT JOIN users u ON u.id = fp.created_by
            LEFT JOIN fraction_account_movements fam ON fam.source_reference_id = fp.id 
                AND fam.source_type = 'quota_application' AND fam.type = 'debit'
            LEFT JOIN financial_transactions ft ON ft.id = COALESCE(fp.financial_transaction_id, fam.source_financial_transaction_id)
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

        $paymentId = (int)$this->db->lastInsertId();
        
        // Log audit
        $this->auditCreate($paymentId, $data);
        
        return $paymentId;
    }

    /**
     * Find payment by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM fee_payments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update payment
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['amount', 'payment_method', 'payment_date', 'notes'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        // Get old data for audit
        $oldData = $this->findById($id);

        $sql = "UPDATE fee_payments SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        $result = $stmt->execute($params);
        
        // Log audit
        if ($result) {
            $this->auditUpdate($id, $data, $oldData);
        }
        
        return $result;
    }

    /**
     * Delete payment
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Get old data for audit before deletion
        $oldData = $this->findById($id);

        $stmt = $this->db->prepare("DELETE FROM fee_payments WHERE id = :id");
        $result = $stmt->execute([':id' => $id]);
        
        // Log audit
        if ($result && $oldData) {
            $this->auditDelete($id, $oldData);
        }
        
        return $result;
    }
}





