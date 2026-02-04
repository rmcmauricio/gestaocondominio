<?php

namespace App\Models;

use App\Core\Model;

class OccurrenceComment extends Model
{
    protected $table = 'occurrence_comments';

    /**
     * Get comments by occurrence
     */
    public function getByOccurrence(int $occurrenceId, bool $includeInternal = false): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT c.*, u.name as user_name, u.email as user_email
                FROM occurrence_comments c
                INNER JOIN users u ON u.id = c.user_id
                WHERE c.occurrence_id = :occurrence_id";

        if (!$includeInternal) {
            $sql .= " AND c.is_internal = FALSE";
        }

        $sql .= " ORDER BY c.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':occurrence_id' => $occurrenceId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create comment
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO occurrence_comments (
                occurrence_id, user_id, comment, is_internal
            )
            VALUES (
                :occurrence_id, :user_id, :comment, :is_internal
            )
        ");

        // Ensure is_internal is always a boolean converted to integer (0 or 1) for MySQL
        $isInternal = false;
        if (isset($data['is_internal'])) {
            $isInternal = filter_var($data['is_internal'], FILTER_VALIDATE_BOOLEAN);
        }
        
        $stmt->execute([
            ':occurrence_id' => $data['occurrence_id'],
            ':user_id' => $data['user_id'],
            ':comment' => $data['comment'],
            ':is_internal' => $isInternal ? 1 : 0
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update comment
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

        $sql = "UPDATE occurrence_comments SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete comment
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM occurrence_comments WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Find comment by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM occurrence_comments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
