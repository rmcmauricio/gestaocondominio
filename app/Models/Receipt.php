<?php

namespace App\Models;

use App\Core\Model;

class Receipt extends Model
{
    protected $table = 'receipts';

    /**
     * Find receipt by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get receipts by fee ID
     */
    public function getByFee(int $feeId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT r.*, f.period_year, f.period_month, fr.identifier as fraction_identifier
            FROM {$this->table} r
            LEFT JOIN fees f ON f.id = r.fee_id
            LEFT JOIN fractions fr ON fr.id = r.fraction_id
            WHERE r.fee_id = :fee_id
            ORDER BY r.generated_at DESC
        ");
        $stmt->execute([':fee_id' => $feeId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get receipts by fraction ID
     */
    public function getByFraction(int $fractionId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT r.*, f.period_year, f.period_month, fr.identifier as fraction_identifier
            FROM {$this->table} r
            LEFT JOIN fees f ON f.id = r.fee_id
            LEFT JOIN fractions fr ON fr.id = r.fraction_id
            WHERE r.fraction_id = :fraction_id
            ORDER BY r.generated_at DESC
        ");
        $stmt->execute([':fraction_id' => $fractionId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get receipts by condominium ID
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "
            SELECT r.*, f.period_year, f.period_month, fr.identifier as fraction_identifier
            FROM {$this->table} r
            LEFT JOIN fees f ON f.id = r.fee_id
            LEFT JOIN fractions fr ON fr.id = r.fraction_id
            WHERE r.condominium_id = :condominium_id
        ";
        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['fraction_id'])) {
            $sql .= " AND r.fraction_id = :fraction_id";
            $params[':fraction_id'] = $filters['fraction_id'];
        }

        if (isset($filters['year'])) {
            $sql .= " AND f.period_year = :year";
            $params[':year'] = $filters['year'];
        }

        if (isset($filters['receipt_type'])) {
            $sql .= " AND r.receipt_type = :receipt_type";
            $params[':receipt_type'] = $filters['receipt_type'];
        }

        $sql .= " ORDER BY r.generated_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get receipts by user (for condominos - their fractions)
     */
    public function getByUser(int $userId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "
            SELECT r.*, f.period_year, f.period_month, fr.identifier as fraction_identifier, c.name as condominium_name
            FROM {$this->table} r
            LEFT JOIN fees f ON f.id = r.fee_id
            LEFT JOIN fractions fr ON fr.id = r.fraction_id
            LEFT JOIN condominiums c ON c.id = r.condominium_id
            INNER JOIN condominium_users cu ON cu.condominium_id = r.condominium_id AND cu.fraction_id = r.fraction_id
            WHERE cu.user_id = :user_id
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
        ";
        $params = [':user_id' => $userId];

        if (isset($filters['condominium_id'])) {
            $sql .= " AND r.condominium_id = :condominium_id";
            $params[':condominium_id'] = $filters['condominium_id'];
        }

        if (isset($filters['year'])) {
            $sql .= " AND f.period_year = :year";
            $params[':year'] = $filters['year'];
        }

        if (isset($filters['receipt_type'])) {
            $sql .= " AND r.receipt_type = :receipt_type";
            $params[':receipt_type'] = $filters['receipt_type'];
        }

        $sql .= " ORDER BY r.generated_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get next receipt number for a condominium and year
     */
    public function getNextReceiptNumber(int $condominiumId, int $year): string
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Get the last receipt number for this condominium and year
        $stmt = $this->db->prepare("
            SELECT receipt_number 
            FROM {$this->table} 
            WHERE condominium_id = :condominium_id 
            AND receipt_number LIKE :pattern
            ORDER BY receipt_number DESC 
            LIMIT 1
        ");
        $pattern = "REC-{$condominiumId}-{$year}-%";
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':pattern' => $pattern
        ]);
        $result = $stmt->fetch();

        if ($result) {
            // Extract the sequential number
            $parts = explode('-', $result['receipt_number']);
            $lastNumber = (int)end($parts);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('REC-%d-%d-%03d', $condominiumId, $year, $nextNumber);
    }

    /**
     * Generate receipt number
     */
    public function generateReceiptNumber(int $condominiumId, int $year): string
    {
        return $this->getNextReceiptNumber($condominiumId, $year);
    }

    /**
     * Create receipt
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (
                fee_id, fee_payment_id, condominium_id, fraction_id,
                receipt_number, receipt_type, amount,
                file_path, file_name, file_size,
                generated_at, generated_by
            )
            VALUES (
                :fee_id, :fee_payment_id, :condominium_id, :fraction_id,
                :receipt_number, :receipt_type, :amount,
                :file_path, :file_name, :file_size,
                :generated_at, :generated_by
            )
        ");

        $stmt->execute([
            ':fee_id' => $data['fee_id'],
            ':fee_payment_id' => $data['fee_payment_id'] ?? null,
            ':condominium_id' => $data['condominium_id'],
            ':fraction_id' => $data['fraction_id'],
            ':receipt_number' => $data['receipt_number'],
            ':receipt_type' => $data['receipt_type'],
            ':amount' => $data['amount'],
            ':file_path' => $data['file_path'],
            ':file_name' => $data['file_name'],
            ':file_size' => $data['file_size'] ?? 0,
            ':generated_at' => $data['generated_at'] ?? date('Y-m-d H:i:s'),
            ':generated_by' => $data['generated_by'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Delete receipt
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Delete receipts by condominium (for demo cleanup)
     */
    public function deleteByCondominium(int $condominiumId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE condominium_id = :condominium_id");
        return $stmt->execute([':condominium_id' => $condominiumId]);
    }

    /**
     * Delete receipts by condominiums (for demo cleanup - multiple)
     */
    public function deleteByCondominiums(array $condominiumIds): bool
    {
        if (!$this->db || empty($condominiumIds)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE condominium_id IN ($placeholders)");
        return $stmt->execute($condominiumIds);
    }

    /**
     * Get available years for receipts in a condominium
     */
    public function getAvailableYears(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT f.period_year as year
            FROM {$this->table} r
            LEFT JOIN fees f ON f.id = r.fee_id
            WHERE r.condominium_id = :condominium_id
            AND r.receipt_type = 'final'
            AND f.period_year IS NOT NULL
            ORDER BY f.period_year DESC
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $results = $stmt->fetchAll() ?: [];
        
        $years = [];
        foreach ($results as $result) {
            if ($result['year']) {
                $years[] = (int)$result['year'];
            }
        }
        
        return $years;
    }

    /**
     * Get available years for receipts by user
     */
    public function getAvailableYearsByUser(int $userId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT f.period_year as year
            FROM {$this->table} r
            LEFT JOIN fees f ON f.id = r.fee_id
            INNER JOIN condominium_users cu ON cu.condominium_id = r.condominium_id AND cu.fraction_id = r.fraction_id
            WHERE cu.user_id = :user_id
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            AND r.receipt_type = 'final'
            AND f.period_year IS NOT NULL
            ORDER BY f.period_year DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $results = $stmt->fetchAll() ?: [];
        
        $years = [];
        foreach ($results as $result) {
            if ($result['year']) {
                $years[] = (int)$result['year'];
            }
        }
        
        return $years;
    }
}
