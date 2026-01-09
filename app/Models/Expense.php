<?php

namespace App\Models;

use App\Core\Model;

class Expense extends Model
{
    protected $table = 'expenses';

    /**
     * Get expenses by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT e.*, s.name as supplier_name, f.identifier as fraction_identifier
                FROM expenses e
                LEFT JOIN suppliers s ON s.id = e.supplier_id
                LEFT JOIN fractions f ON f.id = e.fraction_id
                WHERE e.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['year'])) {
            $sql .= " AND YEAR(e.expense_date) = :year";
            $params[':year'] = $filters['year'];
        }

        if (isset($filters['month'])) {
            $sql .= " AND MONTH(e.expense_date) = :month";
            $params[':month'] = $filters['month'];
        }

        if (isset($filters['category'])) {
            $sql .= " AND e.category = :category";
            $params[':category'] = $filters['category'];
        }

        if (isset($filters['type'])) {
            $sql .= " AND e.type = :type";
            $params[':type'] = $filters['type'];
        }

        $sql .= " ORDER BY e.expense_date DESC, e.created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create expense
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO expenses (
                condominium_id, fraction_id, supplier_id, category, description,
                amount, type, expense_date, invoice_number, invoice_date,
                payment_method, is_paid, attachments, notes, created_by
            )
            VALUES (
                :condominium_id, :fraction_id, :supplier_id, :category, :description,
                :amount, :type, :expense_date, :invoice_number, :invoice_date,
                :payment_method, :is_paid, :attachments, :notes, :created_by
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':fraction_id' => $data['fraction_id'] ?? null,
            ':supplier_id' => $data['supplier_id'] ?? null,
            ':category' => $data['category'],
            ':description' => $data['description'],
            ':amount' => $data['amount'],
            ':type' => $data['type'] ?? 'ordinaria',
            ':expense_date' => $data['expense_date'],
            ':invoice_number' => $data['invoice_number'] ?? null,
            ':invoice_date' => $data['invoice_date'] ?? null,
            ':payment_method' => $data['payment_method'] ?? null,
            ':is_paid' => $data['is_paid'] ?? false,
            ':attachments' => !empty($data['attachments']) ? json_encode($data['attachments']) : null,
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $data['created_by']
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get total expenses by period
     */
    public function getTotalByPeriod(int $condominiumId, string $startDate, string $endDate): float
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT SUM(amount) as total 
            FROM expenses 
            WHERE condominium_id = :condominium_id 
            AND expense_date BETWEEN :start_date AND :end_date
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        $result = $stmt->fetch();
        return (float)($result['total'] ?? 0);
    }
}





