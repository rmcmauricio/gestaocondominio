<?php

namespace App\Models;

use App\Core\Model;

class Message extends Model
{
    protected $table = 'messages';

    /**
     * Get messages by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT m.*, u1.name as sender_name, u2.name as recipient_name
                FROM messages m
                LEFT JOIN users u1 ON u1.id = m.sender_id
                LEFT JOIN users u2 ON u2.id = m.recipient_id
                WHERE m.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['recipient_id'])) {
            $sql .= " AND m.recipient_id = :recipient_id";
            $params[':recipient_id'] = $filters['recipient_id'];
        }

        if (isset($filters['sender_id'])) {
            $sql .= " AND m.sender_id = :sender_id";
            $params[':sender_id'] = $filters['sender_id'];
        }

        if (isset($filters['is_read'])) {
            $sql .= " AND m.is_read = :is_read";
            $params[':is_read'] = (int)$filters['is_read'];
        }

        $sql .= " ORDER BY m.created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create message
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO messages (
                condominium_id, sender_id, recipient_id, subject, message, message_type
            )
            VALUES (
                :condominium_id, :sender_id, :recipient_id, :subject, :message, :message_type
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':sender_id' => $data['sender_id'],
            ':recipient_id' => $data['recipient_id'] ?? null,
            ':subject' => $data['subject'],
            ':message' => $data['message'],
            ':message_type' => $data['message_type'] ?? 'private'
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Mark as read
     */
    public function markAsRead(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE messages 
            SET is_read = TRUE, read_at = NOW() 
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $id]);
    }

    /**
     * Find message by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM messages WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}





