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
            // Get occurrence priority
            $stmtOcc = $db->prepare("SELECT priority, title FROM occurrences WHERE id = :occurrence_id LIMIT 1");
            $stmtOcc->execute([':occurrence_id' => $occurrenceId]);
            $occurrence = $stmtOcc->fetch();

            $priority = $occurrence['priority'] ?? 'medium';
            $occurrenceTitle = $occurrence['title'] ?? 'Nova Ocorrência';

            // Map priority to Portuguese
            $priorityLabels = [
                'low' => 'Baixa',
                'medium' => 'Média',
                'high' => 'Alta',
                'urgent' => 'Urgente'
            ];
            $priorityLabel = $priorityLabels[$priority] ?? 'Média';

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
                    'Uma nova ocorrência foi reportada: ' . $occurrenceTitle . ' (Prioridade: ' . $priorityLabel . ')',
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
     * Send email notification for overdue fees
     */
    public function sendOverdueFeeEmail(int $userId, array $feeData, int $condominiumId): bool
    {
        global $db;

        if (!$db) {
            return false;
        }

        // Get user email
        $stmt = $db->prepare("SELECT email, name FROM users WHERE id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['email'])) {
            return false;
        }

        // Get condominium info
        $stmt = $db->prepare("SELECT name FROM condominiums WHERE id = :condominium_id LIMIT 1");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $condominium = $stmt->fetch();

        $totalAmount = 0;
        $feesList = [];
        foreach ($feeData as $fee) {
            $totalAmount += (float)$fee['pending_amount'];
            $feesList[] = [
                'reference' => $fee['reference'] ?? '',
                'period' => $fee['period_year'] . '/' . str_pad($fee['period_month'] ?? 0, 2, '0', STR_PAD_LEFT),
                'amount' => (float)$fee['pending_amount'],
                'due_date' => $fee['due_date']
            ];
        }

        $subject = 'Quotas em Atraso - ' . ($condominium['name'] ?? 'Condomínio');

        $html = $this->getOverdueFeeEmailTemplate(
            $user['name'],
            $condominium['name'] ?? 'Condomínio',
            $feesList,
            $totalAmount,
            $condominiumId
        );

        return $this->emailService->sendEmail($user['email'], $subject, $html, '');
    }

    /**
     * Get overdue fee email template
     */
    protected function getOverdueFeeEmailTemplate(string $userName, string $condominiumName, array $feesList, float $totalAmount, int $condominiumId): string
    {
        $feesTable = '';
        foreach ($feesList as $fee) {
            $feesTable .= '<tr>';
            $feesTable .= '<td>' . htmlspecialchars($fee['reference']) . '</td>';
            $feesTable .= '<td>' . htmlspecialchars($fee['period']) . '</td>';
            $feesTable .= '<td>' . date('d/m/Y', strtotime($fee['due_date'])) . '</td>';
            $feesTable .= '<td style="text-align: right;">€' . number_format($fee['amount'], 2, ',', '.') . '</td>';
            $feesTable .= '</tr>';
        }

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f2f2f2; }
                .total { font-size: 18px; font-weight: bold; color: #dc3545; }
                .button { display: inline-block; padding: 12px 24px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Quotas em Atraso</h1>
                </div>
                <div class="content">
                    <p>Olá <strong>' . htmlspecialchars($userName) . '</strong>,</p>
                    <p>Informamos que tem quotas em atraso no condomínio <strong>' . htmlspecialchars($condominiumName) . '</strong>.</p>

                    <table>
                        <thead>
                            <tr>
                                <th>Referência</th>
                                <th>Período</th>
                                <th>Vencimento</th>
                                <th style="text-align: right;">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            ' . $feesTable . '
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="total">Total em Atraso:</td>
                                <td class="total" style="text-align: right;">€' . number_format($totalAmount, 2, ',', '.') . '</td>
                            </tr>
                        </tfoot>
                    </table>

                    <p>Por favor, efetue o pagamento o mais breve possível para evitar juros de mora.</p>

                    <a href="' . BASE_URL . 'condominiums/' . $condominiumId . '/fees" class="button">Ver Detalhes das Quotas</a>
                </div>
                <div class="footer">
                    <p>Este é um email automático. Por favor, não responda a este email.</p>
                    <p>&copy; ' . date('Y') . ' MeuPrédio. Todos os direitos reservados.</p>
                </div>
            </div>
        </body>
        </html>';
    }

    /**
     * Notify occurrence comment added
     */
    public function notifyOccurrenceComment(int $occurrenceId, int $condominiumId, int $commentUserId): void
    {
        global $db;
        if (!$db) {
            return;
        }

        // Get occurrence details
        $stmt = $db->prepare("SELECT reported_by, assigned_to FROM occurrences WHERE id = :occurrence_id LIMIT 1");
        $stmt->execute([':occurrence_id' => $occurrenceId]);
        $occurrence = $stmt->fetch();

        if (!$occurrence) {
            return;
        }

        // Notify reporter and assigned user (if different from comment author)
        $usersToNotify = [];
        if ($occurrence['reported_by'] && $occurrence['reported_by'] != $commentUserId) {
            $usersToNotify[] = $occurrence['reported_by'];
        }
        if ($occurrence['assigned_to'] && $occurrence['assigned_to'] != $commentUserId && !in_array($occurrence['assigned_to'], $usersToNotify)) {
            $usersToNotify[] = $occurrence['assigned_to'];
        }

        // Also notify admins
        $stmt = $db->prepare("
            SELECT DISTINCT u.id
            FROM users u
            INNER JOIN condominium_users cu ON cu.user_id = u.id
            WHERE cu.condominium_id = :condominium_id AND u.role IN ('admin', 'super_admin')
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $admins = $stmt->fetchAll();

        foreach ($admins as $admin) {
            if ($admin['id'] != $commentUserId && !in_array($admin['id'], $usersToNotify)) {
                $usersToNotify[] = $admin['id'];
            }
        }

        foreach ($usersToNotify as $userId) {
            $this->createNotification(
                $userId,
                $condominiumId,
                'occurrence_comment',
                'Novo Comentário em Ocorrência',
                'Um novo comentário foi adicionado a uma ocorrência.',
                BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $occurrenceId
            );
        }
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
     * Notify vote opened
     */
    public function notifyVoteOpened(int $voteId, int $condominiumId, string $voteTitle): void
    {
        global $db;
        if (!$db) {
            return;
        }

        // Get all users in the condominium (condominos and admins)
        $stmt = $db->prepare("
            SELECT DISTINCT u.id
            FROM users u
            INNER JOIN condominium_users cu ON cu.user_id = u.id
            WHERE cu.condominium_id = :condominium_id
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $users = $stmt->fetchAll();

        foreach ($users as $user) {
            $this->createNotification(
                $user['id'],
                $condominiumId,
                'vote',
                'Nova Votação Aberta',
                'Uma nova votação foi aberta: ' . $voteTitle,
                BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId
            );
        }
    }

    /**
     * Check if user has access to condominium
     */
    protected function userHasAccessToCondominium(int $userId, int $condominiumId): bool
    {
        global $db;
        if (!$db) {
            return false;
        }

        // Get user role
        $stmt = $db->prepare("SELECT role FROM users WHERE id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        $role = $user['role'];

        // Super admin can access all
        if ($role === 'super_admin') {
            return true;
        }

        // Admin can access their own condominiums
        if ($role === 'admin') {
            $stmt = $db->prepare("SELECT id FROM condominiums WHERE user_id = :user_id AND id = :condominium_id LIMIT 1");
            $stmt->execute([
                ':user_id' => $userId,
                ':condominium_id' => $condominiumId
            ]);
            return $stmt->fetch() !== false;
        }

        // Condomino can access if associated
        $stmt = $db->prepare("
            SELECT id FROM condominium_users 
            WHERE user_id = :user_id 
            AND condominium_id = :condominium_id
            AND (ended_at IS NULL OR ended_at > CURDATE())
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        return $stmt->fetch() !== false;
    }

    /**
     * Get user notifications (only for condominiums user has access to)
     */
    public function getUserNotifications(int $userId, int $limit = 10): array
    {
        global $db;

        if (!$db) {
            return [];
        }

        // Get all notifications for user
        $stmt = $db->prepare("
            SELECT * FROM notifications
            WHERE user_id = :user_id
            ORDER BY created_at DESC
        ");

        $stmt->execute([':user_id' => $userId]);
        $allNotifications = $stmt->fetchAll() ?: [];

        // Filter notifications to only include those from condominiums user has access to
        $filteredNotifications = [];
        foreach ($allNotifications as $notification) {
            $condominiumId = $notification['condominium_id'] ?? null;
            
            // If notification has no condominium_id (system notification), include it
            if ($condominiumId === null) {
                $filteredNotifications[] = $notification;
            } 
            // If notification has condominium_id, check access
            elseif ($this->userHasAccessToCondominium($userId, $condominiumId)) {
                $filteredNotifications[] = $notification;
            }
        }

        // Apply limit
        return array_slice($filteredNotifications, 0, $limit);
    }

    /**
     * Get unified notifications (system notifications + unread messages)
     */
    public function getUnifiedNotifications(int $userId, int $limit = 50): array
    {
        global $db;

        if (!$db) {
            return [];
        }

        $unified = [];

        // Get system notifications
        $stmt = $db->prepare("
            SELECT
                n.id,
                'notification' as source_type,
                n.type,
                n.title,
                n.message,
                n.link,
                n.condominium_id,
                n.is_read,
                n.read_at,
                n.created_at
            FROM notifications n
            WHERE n.user_id = :user_id
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $allNotifications = $stmt->fetchAll() ?: [];

        // Filter notifications to only include those from condominiums user has access to
        $notifications = [];
        foreach ($allNotifications as $notif) {
            $condominiumId = $notif['condominium_id'] ?? null;
            
            // If notification has no condominium_id (system notification), include it
            if ($condominiumId === null) {
                $notifications[] = $notif;
            } 
            // If notification has condominium_id, check access
            elseif ($this->userHasAccessToCondominium($userId, $condominiumId)) {
                $notifications[] = $notif;
            }
        }

        foreach ($notifications as $notif) {
            // Ensure link has BASE_URL if it's a relative path
            $link = $notif['link'];
            if ($link) {
                // Replace localhost with current domain if present
                $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $currentProtocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $currentBaseUrl = $currentProtocol . '://' . $currentHost . '/';

                // If link contains localhost, replace with current domain
                if (strpos($link, 'localhost') !== false) {
                    $link = preg_replace('/https?:\/\/localhost\/?/', $currentBaseUrl, $link);
                    $link = preg_replace('/https?:\/\/127\.0\.0\.1\/?/', $currentBaseUrl, $link);
                }

                // If link is relative or doesn't start with http, add BASE_URL
                if (!preg_match('/^https?:\/\//', $link)) {
                    // Remove BASE_URL if already present to avoid duplication
                    $link = str_replace(BASE_URL, '', $link);
                    // Add BASE_URL if link doesn't start with /
                    if (!str_starts_with($link, '/')) {
                        $link = BASE_URL . $link;
                    } else {
                        $link = BASE_URL . ltrim($link, '/');
                    }
                }
            }

            $notificationData = [
                'id' => 'notif_' . $notif['id'],
                'source_type' => 'notification',
                'type' => $notif['type'],
                'title' => $notif['title'],
                'message' => $notif['message'],
                'link' => $link,
                'condominium_id' => $notif['condominium_id'],
                'is_read' => (bool)$notif['is_read'],
                'read_at' => $notif['read_at'],
                'created_at' => $notif['created_at']
            ];

            // If it's an occurrence notification, get the priority from the occurrence
            if ($notif['type'] === 'occurrence' && $notif['link']) {
                // Extract occurrence ID from link (format: BASE_URL/condominiums/{id}/occurrences/{occurrence_id})
                if (preg_match('/occurrences\/(\d+)/', $notif['link'], $matches)) {
                    $occurrenceId = (int)$matches[1];
                    $stmtOcc = $db->prepare("SELECT priority FROM occurrences WHERE id = :occurrence_id LIMIT 1");
                    $stmtOcc->execute([':occurrence_id' => $occurrenceId]);
                    $occurrence = $stmtOcc->fetch();
                    if ($occurrence) {
                        $notificationData['priority'] = $occurrence['priority'];
                    }
                }
            }

            $unified[] = $notificationData;
        }

        // Get unread messages (only from condominiums user has access to)
        $stmt = $db->prepare("
            SELECT
                m.id,
                m.condominium_id,
                m.subject,
                m.message,
                m.is_read,
                m.read_at,
                m.created_at,
                u.name as sender_name
            FROM messages m
            LEFT JOIN users u ON u.id = m.from_user_id
            WHERE (m.to_user_id = :user_id OR m.to_user_id IS NULL)
            AND m.is_read = FALSE
            AND m.from_user_id != :user_id
            AND m.thread_id IS NULL
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $allMessages = $stmt->fetchAll() ?: [];

        // Filter messages to only include those from condominiums user has access to
        $messages = [];
        foreach ($allMessages as $msg) {
            $condominiumId = $msg['condominium_id'] ?? null;
            
            // If message has condominium_id, check access
            if ($condominiumId && $this->userHasAccessToCondominium($userId, $condominiumId)) {
                $messages[] = $msg;
            }
        }

        foreach ($messages as $msg) {
            $unified[] = [
                'id' => 'msg_' . $msg['id'],
                'source_type' => 'message',
                'type' => 'message',
                'title' => 'Nova Mensagem: ' . $msg['subject'],
                'message' => 'De: ' . ($msg['sender_name'] ?? 'Sistema') . ' - ' . substr(strip_tags($msg['message']), 0, 100) . '...',
                'link' => BASE_URL . 'condominiums/' . $msg['condominium_id'] . '/messages/' . $msg['id'],
                'condominium_id' => $msg['condominium_id'],
                'is_read' => false,
                'read_at' => null,
                'created_at' => $msg['created_at']
            ];
        }

        // Sort by created_at DESC
        usort($unified, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        // Limit results
        return array_slice($unified, 0, $limit);
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





