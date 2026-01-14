<?php

namespace App\Controllers;

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

    public function index()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        // Use unified notifications (includes messages)
        $notifications = $this->notificationService->getUnifiedNotifications($userId, 50);

        // Get unread count
        $unreadCount = count(array_filter($notifications, function($n) {
            return !$n['is_read'];
        }));

        $this->loadPageTranslations('notifications');
        
        // Load translations for the view
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
            'viewName' => 'pages/notifications/index.html.twig',
            'page' => ['titulo' => $translations['title'] ?? 'Notificações'],
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'translations' => $translations,
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function markAsRead(string $id)
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        
        // Handle unified notifications (can be notification or message)
        if (strpos($id, 'msg_') === 0) {
            // It's a message notification
            $messageId = (int)str_replace('msg_', '', $id);
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
            // It's a regular notification - handle both 'notif_XXX' format and plain integer
            $notificationId = str_replace('notif_', '', $id);
            $notificationId = (int)$notificationId;
            
            global $db;
            if ($db) {
                $stmt = $db->prepare("SELECT id FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1");
                $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
                $notification = $stmt->fetch();
                
                if ($notification) {
                    $this->notificationService->markAsRead($notificationId);
                    $_SESSION['success'] = 'Notificação marcada como lida.';
                } else {
                    $_SESSION['error'] = 'Notificação não encontrada.';
                }
            }
        }

        header('Location: ' . BASE_URL . 'notifications');
        exit;
    }

    public function markAllAsRead()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        
        global $db;
        if ($db) {
            // Mark all notifications as read
            $stmt = $db->prepare("
                UPDATE notifications 
                SET is_read = TRUE, read_at = NOW() 
                WHERE user_id = :user_id AND is_read = FALSE
            ");
            $stmt->execute([':user_id' => $userId]);
            
            // Mark all unread messages as read
            $messageModel = new \App\Models\Message();
            $stmt = $db->prepare("
                UPDATE messages 
                SET is_read = TRUE, read_at = NOW() 
                WHERE (to_user_id = :user_id OR to_user_id IS NULL)
                AND is_read = FALSE
                AND from_user_id != :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
            
            $_SESSION['success'] = 'Todas as notificações foram marcadas como lidas.';
        }

        header('Location: ' . BASE_URL . 'notifications');
        exit;
    }

    public function delete(string $id)
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        
        // Handle unified notifications (can be notification or message)
        // Messages cannot be deleted from notifications view, only marked as read
        if (strpos($id, 'msg_') === 0) {
            // It's a message - mark as read instead of deleting
            $messageId = (int)str_replace('msg_', '', $id);
            $messageModel = new \App\Models\Message();
            $message = $messageModel->findById($messageId);
            
            if ($message) {
                $recipientId = $message['to_user_id'] ?? null;
                if (($recipientId == $userId || $recipientId === null) && !$message['is_read']) {
                    $messageModel->markAsRead($messageId);
                    $_SESSION['success'] = 'Mensagem marcada como lida.';
                } else {
                    $_SESSION['info'] = 'Mensagens não podem ser eliminadas desta vista.';
                }
            }
        } else {
            // It's a regular notification - can be deleted
            $notificationId = (int)str_replace('notif_', '', $id);
            global $db;
            if ($db) {
                $stmt = $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :user_id");
                $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
                $_SESSION['success'] = 'Notificação eliminada.';
            }
        }

        header('Location: ' . BASE_URL . 'notifications');
        exit;
    }

    public function getUnreadCount()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $notifications = $this->notificationService->getUserNotifications($userId, 100);
        $unreadCount = count(array_filter($notifications, function($n) {
            return !$n['is_read'];
        }));

        header('Content-Type: application/json');
        echo json_encode(['count' => $unreadCount]);
        exit;
    }
}
