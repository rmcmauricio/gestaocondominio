<?php

namespace App\Models;

use App\Core\Model;

class ExpenseCategory extends Model
{
    protected $table = 'expense_categories';

    /**
     * Get all categories for a condominium
     */
    public function getByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM expense_categories
            WHERE condominium_id = :condominium_id
            ORDER BY name ASC
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find category by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM expense_categories WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find category by condominium and name
     */
    public function getByName(int $condominiumId, string $name): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM expense_categories
            WHERE condominium_id = :condominium_id AND name = :name
            LIMIT 1
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':name' => trim($name)
        ]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Count expenses with this category name
     */
    public function getExpenseCountByName(int $condominiumId, string $name): int
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as c FROM financial_transactions
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'expense'
            AND (related_type IS NULL OR related_type != 'transfer')
            AND COALESCE(NULLIF(TRIM(category), ''), '') = :name
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':name' => trim($name)
        ]);
        $r = $stmt->fetch();
        return (int)($r['c'] ?? 0);
    }

    /**
     * Create category
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $name = trim($data['name'] ?? '');
        if (empty($name)) {
            throw new \Exception("Category name is required");
        }

        $stmt = $this->db->prepare("
            INSERT INTO expense_categories (condominium_id, name)
            VALUES (:condominium_id, :name)
        ");
        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':name' => $name
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update category
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        $fields = [];
        $params = [':id' => $id];
        foreach ($data as $key => $value) {
            if (in_array($key, ['condominium_id', 'name'])) {
                $fields[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE expense_categories SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Update all transactions with old category name to new name
     */
    public function updateTransactionsCategory(int $condominiumId, string $oldName, string $newName): int
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            UPDATE financial_transactions
            SET category = :new_name, updated_at = NOW()
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'expense'
            AND COALESCE(NULLIF(TRIM(category), ''), '') = :old_name
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':old_name' => trim($oldName),
            ':new_name' => trim($newName)
        ]);
        return $stmt->rowCount();
    }

    /**
     * Clear category from all transactions (set to NULL)
     */
    public function clearTransactionsCategory(int $condominiumId, string $categoryName): int
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            UPDATE financial_transactions
            SET category = NULL, updated_at = NOW()
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'expense'
            AND COALESCE(NULLIF(TRIM(category), ''), '') = :category_name
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':category_name' => trim($categoryName)
        ]);
        return $stmt->rowCount();
    }

    /**
     * Create or get category by name
     */
    public function getOrCreate(int $condominiumId, string $name): int
    {
        $existing = $this->getByName($condominiumId, $name);
        if ($existing) {
            return (int)$existing['id'];
        }
        return $this->create(['condominium_id' => $condominiumId, 'name' => $name]);
    }

    /**
     * Delete category
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM expense_categories WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
