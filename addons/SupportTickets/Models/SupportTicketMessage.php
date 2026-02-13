<?php

namespace Addons\SupportTickets\Models;

class SupportTicketMessage
{
    protected $db;

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    public function getByTicketId(int $ticketId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT m.*, u.name as user_name
            FROM support_ticket_messages m
            LEFT JOIN users u ON u.id = m.user_id
            WHERE m.ticket_id = :ticket_id
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([':ticket_id' => $ticketId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function add(int $ticketId, int $userId, string $bodyHtml): int
    {
        if (!$this->db) {
            throw new \Exception("Database not available");
        }
        $stmt = $this->db->prepare("INSERT INTO support_ticket_messages (ticket_id, user_id, body_html) VALUES (:ticket_id, :user_id, :body_html)");
        $stmt->execute([
            ':ticket_id' => $ticketId,
            ':user_id' => $userId,
            ':body_html' => $bodyHtml,
        ]);
        return (int) $this->db->lastInsertId();
    }
}
