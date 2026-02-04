<?php

namespace App\Models;

use App\Core\Model;

class VoteOption extends Model
{
    protected $table = 'vote_options';

    /**
     * Get options by condominium
     */
    public function getByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM vote_options
            WHERE condominium_id = :condominium_id AND is_active = TRUE
            ORDER BY order_index ASC, id ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get default options by condominium
     */
    public function getDefaults(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM vote_options
            WHERE condominium_id = :condominium_id AND is_default = TRUE AND is_active = TRUE
            ORDER BY order_index ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create option
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO vote_options (
                condominium_id, option_label, order_index, is_default, is_active
            )
            VALUES (
                :condominium_id, :option_label, :order_index, :is_default, :is_active
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':option_label' => $data['option_label'],
            ':order_index' => $data['order_index'] ?? 0,
            ':is_default' => isset($data['is_default']) ? ($data['is_default'] ? 1 : 0) : 0,
            ':is_active' => isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update option
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            if (in_array($key, ['is_default', 'is_active'])) {
                $fields[] = "$key = :$key";
                $params[":$key"] = is_bool($value) ? ($value ? 1 : 0) : (int)$value;
            } else {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE vote_options SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete option (only if not default)
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Check if it's a default option
        $stmt = $this->db->prepare("SELECT is_default FROM vote_options WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $option = $stmt->fetch();

        if ($option && $option['is_default']) {
            // Don't delete, just deactivate
            return $this->update($id, ['is_active' => false]);
        }

        $stmt = $this->db->prepare("DELETE FROM vote_options WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Find option by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM vote_options WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $option = $stmt->fetch();

        return $option ?: null;
    }
}
