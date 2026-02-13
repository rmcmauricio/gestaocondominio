<?php

namespace Addons\SupportTickets\Models;

class SupportTicket
{
    protected $db;

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM support_tickets WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function getForUser(int $userId, bool $isSuperAdmin): array
    {
        if (!$this->db) {
            return [];
        }
        if ($isSuperAdmin) {
            $stmt = $this->db->query("SELECT t.*, u.name as user_name, u.email as user_email FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.updated_at DESC");
        } else {
            $stmt = $this->db->prepare("SELECT * FROM support_tickets WHERE user_id = :user_id ORDER BY updated_at DESC");
            $stmt->execute([':user_id' => $userId]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function create(int $userId, string $subject, string $type): int
    {
        if (!$this->db) {
            throw new \Exception("Database not available");
        }
        $stmt = $this->db->prepare("INSERT INTO support_tickets (user_id, subject, type, status) VALUES (:user_id, :subject, :type, 'open')");
        $stmt->execute([
            ':user_id' => $userId,
            ':subject' => $subject,
            ':type' => $type,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status): void
    {
        if (!$this->db) {
            return;
        }
        $allowed = ['open', 'in_progress', 'resolved', 'closed'];
        if (!in_array($status, $allowed)) {
            return;
        }
        $stmt = $this->db->prepare("UPDATE support_tickets SET status = :status, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    public function canAccess(int $ticketId, int $userId, bool $isSuperAdmin): bool
    {
        $t = $this->findById($ticketId);
        if (!$t) {
            return false;
        }
        return $isSuperAdmin || (int) $t['user_id'] === $userId;
    }
}
