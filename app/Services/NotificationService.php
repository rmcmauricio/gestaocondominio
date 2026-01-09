<?php

namespace App\Services;

use App\Core\EmailService;

class NotificationService
{
    protected $emailService;

    public function __construct()
    {
        $this->emailService = new EmailService();
    }

    /**
     * Create notification in database
     */
    public function createNotification(int $userId, int $condominiumId, string $type, string $title, string $message, string $link = null): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO notifications (user_id, condominium_id, type, title, message, link)
                VALUES (:user_id, :condominium_id, :type, :title, :message, :link)
            ");

            return $stmt->execute([
                ':user_id' => $userId,
                ':condominium_id' => $condominiumId,
                ':type' => $type,
                ':title' => $title,
                ':message' => $message,
                ':link' => $link
            ]);
        } catch (\Exception $e) {
            error_log("Notification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify occurrence created
     */
    public function notifyOccurrenceCreated(int $occurrenceId, int $condominiumId): void
    {
        // Notify admins
        global $db;
        if ($db) {
            $stmt = $db->prepare("
                SELECT DISTINCT u.id 
                FROM users u
                INNER JOIN condominiums c ON c.user_id = u.id
                WHERE c.id = :condominium_id AND u.role = 'admin'
            ");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $admins = $stmt->fetchAll();

            foreach ($admins as $admin) {
                $this->createNotification(
                    $admin['id'],
                    $condominiumId,
                    'occurrence',
                    'Nova Ocorrência',
                    'Uma nova ocorrência foi reportada.',
                    BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $occurrenceId
                );
            }
        }
    }

    /**
     * Notify fee overdue
     */
    public function notifyFeeOverdue(int $feeId, int $userId, int $condominiumId): void
    {
        $this->createNotification(
            $userId,
            $condominiumId,
            'fee_overdue',
            'Quota em Atraso',
            'Tem uma quota em atraso. Por favor, efetue o pagamento.',
            BASE_URL . 'condominiums/' . $condominiumId . '/fees'
        );
    }

    /**
     * Notify assembly scheduled
     */
    public function notifyAssemblyScheduled(int $assemblyId, int $condominiumId, array $userIds): void
    {
        foreach ($userIds as $userId) {
            $this->createNotification(
                $userId,
                $condominiumId,
                'assembly',
                'Nova Assembleia Agendada',
                'Uma nova assembleia foi agendada.',
                BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId
            );
        }
    }

    /**
     * Get user notifications
     */
    public function getUserNotifications(int $userId, int $limit = 10): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT * FROM notifications 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");

        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = TRUE, read_at = NOW() 
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $notificationId]);
    }
}





