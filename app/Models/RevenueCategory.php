<?php

namespace App\Models;

use App\Core\Model;

class RevenueCategory extends Model
{
    protected $table = 'revenue_categories';

    public function getByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT * FROM revenue_categories
            WHERE condominium_id = :condominium_id
            ORDER BY name ASC
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM revenue_categories WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function getByName(int $condominiumId, string $name): ?array
    {
        if (!$this->db) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT * FROM revenue_categories
            WHERE condominium_id = :condominium_id AND name = :name
            LIMIT 1
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':name' => trim($name)
        ]);
        return $stmt->fetch() ?: null;
    }

    public function getRevenueCountByName(int $condominiumId, string $name): int
    {
        if (!$this->db) {
            return 0;
        }
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as c FROM financial_transactions
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'income'
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
            INSERT INTO revenue_categories (condominium_id, name)
            VALUES (:condominium_id, :name)
        ");
        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':name' => $name
        ]);
        return (int)$this->db->lastInsertId();
    }

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
        $sql = "UPDATE revenue_categories SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateRevenuesCategory(int $condominiumId, string $oldName, string $newName): int
    {
        if (!$this->db) {
            return 0;
        }
        $stmt = $this->db->prepare("
            UPDATE financial_transactions
            SET category = :new_name, updated_at = NOW()
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'income'
            AND COALESCE(NULLIF(TRIM(category), ''), '') = :old_name
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':old_name' => trim($oldName),
            ':new_name' => trim($newName)
        ]);
        return $stmt->rowCount();
    }

    public function clearRevenuesCategory(int $condominiumId, string $categoryName): int
    {
        if (!$this->db) {
            return 0;
        }
        $stmt = $this->db->prepare("
            UPDATE financial_transactions
            SET category = NULL, updated_at = NOW()
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'income'
            AND COALESCE(NULLIF(TRIM(category), ''), '') = :category_name
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':category_name' => trim($categoryName)
        ]);
        return $stmt->rowCount();
    }

    public function getOrCreate(int $condominiumId, string $name): int
    {
        $existing = $this->getByName($condominiumId, $name);
        if ($existing) {
            return (int)$existing['id'];
        }
        return $this->create(['condominium_id' => $condominiumId, 'name' => $name]);
    }

    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM revenue_categories WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
