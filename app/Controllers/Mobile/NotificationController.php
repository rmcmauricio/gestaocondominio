<?php

namespace App\Controllers\Mobile;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Services\NotificationService;

class NotificationController extends Controller
{
    protected $notificationService;

    public function __construct()
    {
        parent::__construct();
        $this->notificationService = new NotificationService();
    }

    /**
     * List notifications (same structure as desktop, mobile layout).
     */
    public function index(): void
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $notifications = $this->notificationService->getUnifiedNotifications($userId, 50);
        $unreadCount = count(array_filter($notifications, function ($n) {
            return !$n['is_read'];
        }));

        $this->loadPageTranslations('notifications');
        $lang = $_SESSION['lang'] ?? 'pt';
        $translations = [];
        $translationFile = __DIR__ . "/../Metafiles/{$lang}/notifications.json";
        if (file_exists($translationFile)) {
            $translationData = json_decode(file_get_contents($translationFile), true);
            if (isset($translationData['translations'])) {
                $translations = $translationData['translations'];
            }
        }

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        $this->data += [
            'viewName' => 'pages/notifications/index.html.twig',
            'page' => ['titulo' => $translations['title'] ?? 'Notificações'],
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'translations' => $translations,
            'user' => AuthMiddleware::user(),
            'success' => $success,
            'error' => $error,
            'flash_messages' => ['success' => $success, 'error' => $error],
            'notifications_base_url' => BASE_URL . 'm/notifications',
            'notifications_show_base' => BASE_URL . 'm/notifications/show?id=',
        ];

        $_SESSION['mobile_version'] = true;
        $this->renderMobileTemplate();
    }

    /**
     * Show one notification (note) in mobile layout; "Ver mais" opens this.
     */
    public function show(): void
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $id = $_GET['id'] ?? '';
        if ($id === '') {
            header('Location: ' . BASE_URL . 'm/notifications');
            exit;
        }

        $notifications = $this->notificationService->getUnifiedNotifications($userId, 500);
        $notification = null;
        foreach ($notifications as $n) {
            if (($n['id'] ?? '') === $id) {
                $notification = $n;
                break;
            }
        }

        if (!$notification) {
            $_SESSION['error'] = 'Notificação não encontrada.';
            header('Location: ' . BASE_URL . 'm/notifications');
            exit;
        }

        $this->loadPageTranslations('notifications');
        $lang = $_SESSION['lang'] ?? 'pt';
        $translations = [];
        $translationFile = __DIR__ . "/../Metafiles/{$lang}/notifications.json";
        if (file_exists($translationFile)) {
            $translationData = json_decode(file_get_contents($translationFile), true);
            if (isset($translationData['translations'])) {
                $translations = $translationData['translations'];
            }
        }

        $this->data += [
            'viewName' => 'pages/m/notification-show.html.twig',
            'page' => ['titulo' => $notification['title'] ?? 'Notificação'],
            'notification' => $notification,
            'translations' => $translations,
            'user' => AuthMiddleware::user(),
        ];

        $_SESSION['mobile_version'] = true;
        $this->renderMobileTemplate();
    }

    /**
     * Mark one as read; redirect to /m/notifications.
     */
    public function markAsRead(string $id): void
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();

        if (strpos($id, 'msg_') === 0) {
            $messageId = (int) str_replace('msg_', '', $id);
            $messageModel = new \App\Models\Message();
            $message = $messageModel->findById($messageId);
            if ($message) {
                $recipientId = $message['to_user_id'] ?? null;
                if (($recipientId == $userId || $recipientId === null) && !$message['is_read']) {
                    $messageModel->markAsRead($messageId);
                    $_SESSION['success'] = 'Mensagem marcada como lida.';
                }
            }
        } else {
            $notificationId = (int) str_replace('notif_', '', $id);
            global $db;
            if ($db) {
                $stmt = $db->prepare("SELECT id FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1");
                $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
                if ($stmt->fetch()) {
                    $this->notificationService->markAsRead($notificationId);
                    $_SESSION['success'] = 'Notificação marcada como lida.';
                } else {
                    $_SESSION['error'] = 'Notificação não encontrada.';
                }
            }
        }

        header('Location: ' . BASE_URL . 'm/notifications');
        exit;
    }

    /**
     * Mark all as read; redirect to /m/notifications.
     */
    public function markAllAsRead(): void
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        global $db;
        if ($db) {
            $stmt = $db->prepare("
                UPDATE notifications SET is_read = TRUE, read_at = NOW()
                WHERE user_id = :user_id AND is_read = FALSE
            ");
            $stmt->execute([':user_id' => $userId]);

            $stmt = $db->prepare("
                UPDATE messages SET is_read = TRUE, read_at = NOW()
                WHERE (to_user_id = :user_id OR to_user_id IS NULL) AND is_read = FALSE AND from_user_id != :user_id
            ");
            $stmt->execute([':user_id' => $userId]);

            $_SESSION['success'] = 'Todas as notificações foram marcadas como lidas.';
        }

        header('Location: ' . BASE_URL . 'm/notifications');
        exit;
    }

    /**
     * Delete notification; redirect to /m/notifications.
     */
    public function delete(string $id): void
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();

        if (strpos($id, 'msg_') === 0) {
            $messageId = (int) str_replace('msg_', '', $id);
            $messageModel = new \App\Models\Message();
            $message = $messageModel->findById($messageId);
            if ($message) {
                $recipientId = $message['to_user_id'] ?? null;
                if (($recipientId == $userId || $recipientId === null) && !$message['is_read']) {
                    $messageModel->markAsRead($messageId);
                    $_SESSION['success'] = 'Mensagem marcada como lida.';
                }
            }
        } else {
            $notificationId = (int) str_replace('notif_', '', $id);
            global $db;
            if ($db) {
                $stmt = $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :user_id");
                $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
                $_SESSION['success'] = 'Notificação eliminada.';
            }
        }

        header('Location: ' . BASE_URL . 'm/notifications');
        exit;
    }
}
