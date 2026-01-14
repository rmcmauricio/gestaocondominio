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

        $sql = "SELECT m.*, u1.name as sender_name, u2.name as recipient_name,
                       parent.subject as parent_subject, parent.id as parent_id
                FROM messages m
                LEFT JOIN users u1 ON u1.id = m.from_user_id
                LEFT JOIN users u2 ON u2.id = m.to_user_id
                LEFT JOIN messages parent ON parent.id = m.thread_id
                WHERE m.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['recipient_id'])) {
            // Include both private messages to this user AND announcements (to_user_id IS NULL)
            // Exclude replies (only show root messages)
            $sql .= " AND (m.to_user_id = :recipient_id OR m.to_user_id IS NULL)";
            $sql .= " AND m.thread_id IS NULL";
            $params[':recipient_id'] = $filters['recipient_id'];
        }

        if (isset($filters['sender_id'])) {
            $sql .= " AND m.from_user_id = :sender_id";
            // Exclude replies (only show root messages) unless explicitly requested
            if (!isset($filters['include_replies']) || !$filters['include_replies']) {
                $sql .= " AND m.thread_id IS NULL";
            }
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
     * Get unread messages count for a user in a condominium
     * Includes both private messages and announcements
     * Note: For announcements, this counts all unread announcements (simple approach)
     * In production, you'd want a message_reads table to track per-user read status
     */
    public function getUnreadCount(int $condominiumId, int $userId): int
    {
        if (!$this->db) {
            return 0;
        }

        // Count private messages to this user that are unread
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM messages m
            WHERE m.condominium_id = :condominium_id
            AND m.to_user_id = :user_id
            AND m.is_read = FALSE
            AND m.from_user_id != :user_id
        ");
        
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId
        ]);
        
        $privateCount = (int)($stmt->fetch()['count'] ?? 0);
        
        // Count announcements (to_user_id IS NULL) that are unread
        // Note: This is a simple approach - in production, use a message_reads table
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM messages m
            WHERE m.condominium_id = :condominium_id
            AND m.to_user_id IS NULL
            AND m.is_read = FALSE
            AND m.from_user_id != :user_id
        ");
        
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId
        ]);
        
        $announcementCount = (int)($stmt->fetch()['count'] ?? 0);
        
        return $privateCount + $announcementCount;
    }

    /**
     * Create message
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Support both old names (sender_id/recipient_id) and new names (from_user_id/to_user_id)
        $fromUserId = $data['sender_id'] ?? $data['from_user_id'] ?? null;
        $toUserId = $data['recipient_id'] ?? $data['to_user_id'] ?? null;

        $threadId = $data['thread_id'] ?? null;
        
        $stmt = $this->db->prepare("
            INSERT INTO messages (
                condominium_id, from_user_id, to_user_id, thread_id, subject, message
            )
            VALUES (
                :condominium_id, :from_user_id, :to_user_id, :thread_id, :subject, :message
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':from_user_id' => $fromUserId,
            ':to_user_id' => $toUserId,
            ':thread_id' => $threadId,
            ':subject' => $data['subject'],
            ':message' => $data['message']
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

        $stmt = $this->db->prepare("
            SELECT m.*, u1.name as sender_name, u2.name as recipient_name
            FROM messages m
            LEFT JOIN users u1 ON u1.id = m.from_user_id
            LEFT JOIN users u2 ON u2.id = m.to_user_id
            WHERE m.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get replies for a message thread
     */
    public function getReplies(int $threadId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT m.*, u1.name as sender_name, u2.name as recipient_name
            FROM messages m
            LEFT JOIN users u1 ON u1.id = m.from_user_id
            LEFT JOIN users u2 ON u2.id = m.to_user_id
            WHERE m.thread_id = :thread_id
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([':thread_id' => $threadId]);
        return $stmt->fetchAll() ?: [];
    }
    
    /**
     * Get root message ID (if this is a reply, get the original message)
     */
    public function getRootMessageId(int $messageId): int
    {
        if (!$this->db) {
            return $messageId;
        }

        $message = $this->findById($messageId);
        if (!$message) {
            return $messageId;
        }

        // If this message is already a root (no thread_id), return its ID
        if (empty($message['thread_id'])) {
            return $messageId;
        }

        // Otherwise, recursively find the root
        return $this->getRootMessageId($message['thread_id']);
    }
}





