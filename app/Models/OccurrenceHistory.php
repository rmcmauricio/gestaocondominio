<?php

namespace App\Models;

use App\Core\Model;

class OccurrenceHistory extends Model
{
    protected $table = 'occurrence_history';

    /**
     * Get history by occurrence
     */
    public function getByOccurrence(int $occurrenceId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT h.*, u.name as user_name
            FROM occurrence_history h
            INNER JOIN users u ON u.id = h.user_id
            WHERE h.occurrence_id = :occurrence_id
            ORDER BY h.created_at ASC
        ");

        $stmt->execute([':occurrence_id' => $occurrenceId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create history entry
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO occurrence_history (
                occurrence_id, user_id, action, field_name, old_value, new_value, notes
            )
            VALUES (
                :occurrence_id, :user_id, :action, :field_name, :old_value, :new_value, :notes
            )
        ");

        $stmt->execute([
            ':occurrence_id' => $data['occurrence_id'],
            ':user_id' => $data['user_id'],
            ':action' => $data['action'],
            ':field_name' => $data['field_name'] ?? null,
            ':old_value' => $data['old_value'] ?? null,
            ':new_value' => $data['new_value'] ?? null,
            ':notes' => $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Log status change
     */
    public function logStatusChange(int $occurrenceId, int $userId, string $oldStatus, string $newStatus, string $notes = null): int
    {
        return $this->create([
            'occurrence_id' => $occurrenceId,
            'user_id' => $userId,
            'action' => 'status_changed',
            'field_name' => 'status',
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            'notes' => $notes
        ]);
    }

    /**
     * Log assignment
     */
    public function logAssignment(int $occurrenceId, int $userId, string $type, ?int $oldValue, ?int $newValue, string $notes = null): int
    {
        return $this->create([
            'occurrence_id' => $occurrenceId,
            'user_id' => $userId,
            'action' => 'assigned',
            'field_name' => $type,
            'old_value' => $oldValue ? (string)$oldValue : null,
            'new_value' => $newValue ? (string)$newValue : null,
            'notes' => $notes
        ]);
    }

    /**
     * Log field update
     */
    public function logFieldUpdate(int $occurrenceId, int $userId, string $fieldName, $oldValue, $newValue, string $notes = null): int
    {
        return $this->create([
            'occurrence_id' => $occurrenceId,
            'user_id' => $userId,
            'action' => 'field_updated',
            'field_name' => $fieldName,
            'old_value' => $oldValue ? (string)$oldValue : null,
            'new_value' => $newValue ? (string)$newValue : null,
            'notes' => $notes
        ]);
    }

    /**
     * Log comment added
     */
    public function logComment(int $occurrenceId, int $userId, string $notes = null): int
    {
        return $this->create([
            'occurrence_id' => $occurrenceId,
            'user_id' => $userId,
            'action' => 'comment_added',
            'notes' => $notes
        ]);
    }
}
