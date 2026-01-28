<?php

namespace App\Models;

use App\Core\Model;

class Contract extends Model
{
    protected $table = 'contracts';

    /**
     * Get contracts by condominium
     */
    public function getByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT c.*, s.name as supplier_name,
                   d.id as document_id, d.file_path as document_file_path, 
                   d.file_name as document_file_name, d.title as document_title
            FROM contracts c
            INNER JOIN suppliers s ON s.id = c.supplier_id
            LEFT JOIN documents d ON d.id = c.document_id
            WHERE c.condominium_id = :condominium_id
            ORDER BY c.end_date ASC, c.created_at DESC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get contracts expiring soon
     */
    public function getExpiringSoon(int $condominiumId, int $days = 30): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT c.*, s.name as supplier_name,
                   DATEDIFF(c.end_date, CURDATE()) as days_until_expiry
            FROM contracts c
            INNER JOIN suppliers s ON s.id = c.supplier_id
            WHERE c.condominium_id = :condominium_id
            AND c.is_active = TRUE
            AND c.end_date IS NOT NULL
            AND c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
            ORDER BY c.end_date ASC
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':days' => $days
        ]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create contract
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check if amount_type and document_id columns exist
        $stmt = $this->db->query("SHOW COLUMNS FROM contracts LIKE 'amount_type'");
        $hasAmountType = $stmt->rowCount() > 0;
        $stmt = $this->db->query("SHOW COLUMNS FROM contracts LIKE 'document_id'");
        $hasDocumentId = $stmt->rowCount() > 0;

        if ($hasAmountType && $hasDocumentId) {
            $stmt = $this->db->prepare("
                INSERT INTO contracts (
                    condominium_id, supplier_id, contract_number, description,
                    amount, amount_type, start_date, end_date, renewal_alert_days,
                    auto_renew, attachments, document_id, notes, created_by
                )
                VALUES (
                    :condominium_id, :supplier_id, :contract_number, :description,
                    :amount, :amount_type, :start_date, :end_date, :renewal_alert_days,
                    :auto_renew, :attachments, :document_id, :notes, :created_by
                )
            ");

            $stmt->execute([
                ':condominium_id' => $data['condominium_id'],
                ':supplier_id' => $data['supplier_id'],
                ':contract_number' => $data['contract_number'] ?? null,
                ':description' => $data['description'],
                ':amount' => $data['amount'] ?? null,
                ':amount_type' => $data['amount_type'] ?? null,
                ':start_date' => $data['start_date'],
                ':end_date' => $data['end_date'] ?? null,
                ':renewal_alert_days' => $data['renewal_alert_days'] ?? 30,
                ':auto_renew' => isset($data['auto_renew']) ? (int)$data['auto_renew'] : 0,
                ':attachments' => !empty($data['attachments']) ? json_encode($data['attachments']) : null,
                ':document_id' => $data['document_id'] ?? null,
                ':notes' => $data['notes'] ?? null,
                ':created_by' => $data['created_by']
            ]);
        } else {
            // Fallback for older database schema
            $stmt = $this->db->prepare("
                INSERT INTO contracts (
                    condominium_id, supplier_id, contract_number, description,
                    amount, start_date, end_date, renewal_alert_days,
                    auto_renew, attachments, notes, created_by
                )
                VALUES (
                    :condominium_id, :supplier_id, :contract_number, :description,
                    :amount, :start_date, :end_date, :renewal_alert_days,
                    :auto_renew, :attachments, :notes, :created_by
                )
            ");

            $stmt->execute([
                ':condominium_id' => $data['condominium_id'],
                ':supplier_id' => $data['supplier_id'],
                ':contract_number' => $data['contract_number'] ?? null,
                ':description' => $data['description'],
                ':amount' => $data['amount'] ?? null,
                ':start_date' => $data['start_date'],
                ':end_date' => $data['end_date'] ?? null,
                ':renewal_alert_days' => $data['renewal_alert_days'] ?? 30,
                ':auto_renew' => isset($data['auto_renew']) ? (int)$data['auto_renew'] : 0,
                ':attachments' => !empty($data['attachments']) ? json_encode($data['attachments']) : null,
                ':notes' => $data['notes'] ?? null,
                ':created_by' => $data['created_by']
            ]);
        }

        $contractId = (int)$this->db->lastInsertId();
        
        // Log audit
        $this->auditCreate($contractId, $data);
        
        return $contractId;
    }

    /**
     * Find contract by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM contracts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update contract
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            if ($key === 'attachments' && is_array($value)) {
                $fields[] = "attachments = :attachments";
                $params[':attachments'] = json_encode($value);
            } elseif ($key === 'auto_renew') {
                // Ensure auto_renew is always an integer (0 or 1)
                $fields[] = "$key = :$key";
                $params[":$key"] = $value ? 1 : 0;
            } else {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        // Get old data for audit
        $oldData = $this->findById($id);

        $sql = "UPDATE contracts SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        $result = $stmt->execute($params);
        
        // Log audit
        if ($result) {
            $this->auditUpdate($id, $data, $oldData);
        }
        
        return $result;
    }

    /**
     * Delete contract
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Get old data for audit before deletion
        $oldData = $this->findById($id);
        if (!$oldData) {
            return false;
        }

        // Delete from database
        $stmt = $this->db->prepare("DELETE FROM contracts WHERE id = :id");
        $result = $stmt->execute([':id' => $id]);
        
        // Log audit
        if ($result) {
            $this->auditDelete($id, $oldData);
        }
        
        return $result;
    }
}





