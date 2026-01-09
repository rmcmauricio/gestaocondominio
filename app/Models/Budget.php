<?php

namespace App\Models;

use App\Core\Model;

class Budget extends Model
{
    protected $table = 'budgets';

    /**
     * Get budget by condominium and year
     */
    public function getByCondominiumAndYear(int $condominiumId, int $year): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM budgets 
            WHERE condominium_id = :condominium_id AND year = :year
            LIMIT 1
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':year' => $year
        ]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Get all budgets for condominium
     */
    public function getByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM budgets 
            WHERE condominium_id = :condominium_id
            ORDER BY year DESC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create budget
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO budgets (
                condominium_id, year, total_amount, status, notes
            )
            VALUES (
                :condominium_id, :year, :total_amount, :status, :notes
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':year' => $data['year'],
            ':total_amount' => $data['total_amount'],
            ':status' => $data['status'] ?? 'draft',
            ':notes' => $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update budget
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[":$key"] = $value;
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE budgets SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Approve budget
     */
    public function approve(int $id, int $approvedBy): bool
    {
        return $this->update($id, [
            'status' => 'approved',
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => $approvedBy
        ]);
    }

    /**
     * Find budget by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM budgets WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}

