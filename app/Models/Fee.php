<?php

namespace App\Models;

use App\Core\Model;

class Fee extends Model
{
    protected $table = 'fees';

    /**
     * Get fees by fraction
     */
    public function getByFraction(int $fractionId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT * FROM fees WHERE fraction_id = :fraction_id";
        $params = [':fraction_id' => $fractionId];

        if (isset($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['year'])) {
            $sql .= " AND period_year = :year";
            $params[':year'] = $filters['year'];
        }

        $sql .= " ORDER BY period_year DESC, period_month DESC, created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get pending fees by fraction
     */
    public function getPendingByFraction(int $fractionId): array
    {
        return $this->getByFraction($fractionId, ['status' => 'pending']);
    }

    /**
     * Create fee
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO fees (
                condominium_id, fraction_id, period_type, period_year,
                period_month, period_quarter, amount, base_amount,
                status, due_date, reference, notes, is_historical
            )
            VALUES (
                :condominium_id, :fraction_id, :period_type, :period_year,
                :period_month, :period_quarter, :amount, :base_amount,
                :status, :due_date, :reference, :notes, :is_historical
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':fraction_id' => $data['fraction_id'],
            ':period_type' => $data['period_type'] ?? 'monthly',
            ':period_year' => $data['period_year'],
            ':period_month' => $data['period_month'] ?? null,
            ':period_quarter' => $data['period_quarter'] ?? null,
            ':amount' => $data['amount'],
            ':base_amount' => $data['base_amount'] ?? $data['amount'],
            ':status' => $data['status'] ?? 'pending',
            ':due_date' => $data['due_date'],
            ':reference' => $data['reference'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':is_historical' => isset($data['is_historical']) ? (int)$data['is_historical'] : 0
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Mark fee as paid
     */
    public function markAsPaid(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE fees 
            SET status = 'paid', paid_at = NOW() 
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $id]);
    }

    /**
     * Get total pending amount by fraction
     */
    public function getTotalPendingByFraction(int $fractionId): float
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT SUM(amount) as total 
            FROM fees 
            WHERE fraction_id = :fraction_id 
            AND status = 'pending'
        ");

        $stmt->execute([':fraction_id' => $fractionId]);
        $result = $stmt->fetch();
        return (float)($result['total'] ?? 0);
    }

    /**
     * Get fees by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT f.*, fr.identifier as fraction_identifier
                FROM fees f
                INNER JOIN fractions fr ON fr.id = f.fraction_id
                WHERE f.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['year'])) {
            $sql .= " AND f.period_year = :year";
            $params[':year'] = $filters['year'];
        }

        if (isset($filters['month'])) {
            $sql .= " AND f.period_month = :month";
            $params[':month'] = $filters['month'];
        }

        if (isset($filters['status'])) {
            $sql .= " AND f.status = :status";
            $params[':status'] = $filters['status'];
        }

        $sql .= " ORDER BY f.period_year DESC, f.period_month DESC, fr.identifier ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find fee by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM fees WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}

