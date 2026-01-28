<?php

namespace App\Models;

use App\Core\Model;

class BudgetItem extends Model
{
    protected $table = 'budget_items';

    /**
     * Get items by budget
     */
    public function getByBudget(int $budgetId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM budget_items 
            WHERE budget_id = :budget_id
            ORDER BY sort_order ASC, category ASC, id ASC
        ");

        $stmt->execute([':budget_id' => $budgetId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create budget item
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO budget_items (
                budget_id, category, description, amount, sort_order
            )
            VALUES (
                :budget_id, :category, :description, :amount, :sort_order
            )
        ");

        $stmt->execute([
            ':budget_id' => $data['budget_id'],
            ':category' => $data['category'],
            ':description' => $data['description'] ?? null,
            ':amount' => $data['amount'],
            ':sort_order' => $data['sort_order'] ?? 0
        ]);

        $itemId = (int)$this->db->lastInsertId();
        
        // Log audit
        $this->auditCreate($itemId, $data);
        
        return $itemId;
    }

    /**
     * Update budget item
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

        $sql = "UPDATE budget_items SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Delete budget item
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Get old data for audit before deletion
        $oldData = $this->getOldData('budget_items', $id);

        $stmt = $this->db->prepare("DELETE FROM budget_items WHERE id = :id");
        $result = $stmt->execute([':id' => $id]);
        
        // Log audit
        if ($result && $oldData) {
            $this->auditDelete($id, $oldData);
        }
        
        return $result;
    }

    /**
     * Delete all items for a budget
     */
    public function deleteByBudget(int $budgetId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM budget_items WHERE budget_id = :budget_id");
        return $stmt->execute([':budget_id' => $budgetId]);
    }

    /**
     * Get total amount by type (expense/revenue)
     */
    public function getTotalByType(int $budgetId, string $type): float
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT SUM(amount) as total 
            FROM budget_items 
            WHERE budget_id = :budget_id 
            AND category LIKE :type
        ");

        $stmt->execute([
            ':budget_id' => $budgetId,
            ':type' => $type . '%'
        ]);

        $result = $stmt->fetch();
        return (float)($result['total'] ?? 0);
    }
}





