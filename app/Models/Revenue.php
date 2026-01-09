<?php

namespace App\Models;

use App\Core\Model;

class Revenue extends Model
{
    protected $table = 'revenues';

    /**
     * Get revenues by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT r.*, fr.identifier as fraction_identifier
                FROM revenues r
                LEFT JOIN fractions fr ON fr.id = r.fraction_id
                WHERE r.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['year'])) {
            $sql .= " AND YEAR(r.revenue_date) = :year";
            $params[':year'] = $filters['year'];
        }

        if (isset($filters['month'])) {
            $sql .= " AND MONTH(r.revenue_date) = :month";
            $params[':month'] = $filters['month'];
        }

        if (isset($filters['fraction_id'])) {
            $sql .= " AND r.fraction_id = :fraction_id";
            $params[':fraction_id'] = $filters['fraction_id'];
        }

        $sql .= " ORDER BY r.revenue_date DESC, r.created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get total revenues by period
     */
    public function getTotalByPeriod(int $condominiumId, string $startDate, string $endDate): float
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT SUM(amount) as total 
            FROM revenues 
            WHERE condominium_id = :condominium_id 
            AND revenue_date BETWEEN :start_date AND :end_date
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        $result = $stmt->fetch();
        return (float)($result['total'] ?? 0);
    }

    /**
     * Create revenue
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO revenues (
                condominium_id, fraction_id, description, amount,
                revenue_date, payment_method, reference, notes, created_by
            )
            VALUES (
                :condominium_id, :fraction_id, :description, :amount,
                :revenue_date, :payment_method, :reference, :notes, :created_by
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':fraction_id' => $data['fraction_id'] ?? null,
            ':description' => $data['description'],
            ':amount' => $data['amount'],
            ':revenue_date' => $data['revenue_date'],
            ':payment_method' => $data['payment_method'] ?? null,
            ':reference' => $data['reference'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $data['created_by']
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Find revenue by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM revenues WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update revenue
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach (['description', 'amount', 'revenue_date', 'payment_method', 'reference', 'notes', 'fraction_id'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE revenues SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete revenue
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM revenues WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
