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
            SELECT c.*, s.name as supplier_name
            FROM contracts c
            INNER JOIN suppliers s ON s.id = c.supplier_id
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
            ':amount' => $data['amount'],
            ':start_date' => $data['start_date'],
            ':end_date' => $data['end_date'] ?? null,
            ':renewal_alert_days' => $data['renewal_alert_days'] ?? 30,
            ':auto_renew' => isset($data['auto_renew']) ? (int)$data['auto_renew'] : 0,
            ':attachments' => !empty($data['attachments']) ? json_encode($data['attachments']) : null,
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $data['created_by']
        ]);

        return (int)$this->db->lastInsertId();
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
            } else {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE contracts SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }
}





