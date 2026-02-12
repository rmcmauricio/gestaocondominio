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
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/notifications/index.html.twig',
            'page' => ['titulo' => $translations['title'] ?? 'Notificações'],
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'translations' => $translations,
            'user' => AuthMiddleware::user(),
            'error' => $error,
            'success' => $success
        ];

        $this->renderMainTemplate();
    }

    public function markAsRead(string $id)
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        $success = false;
        $message = '';
        
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
                    $success = true;
                    $message = 'Mensagem marcada como lida.';
                    $_SESSION['success'] = $message;
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
                    $success = true;
                    $message = 'Notificação marcada como lida.';
                    $_SESSION['success'] = $message;
                } else {
                    $message = 'Notificação não encontrada.';
                    $_SESSION['error'] = $message;
                }
            }
        }

        // If AJAX request, return JSON
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'message' => $message]);
            exit;
        }

        header('Location: ' . BASE_URL . 'notifications');
        exit;
    }

    public function markAllAsRead()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
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

        // If AJAX request, return JSON
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Todas as notificações foram marcadas como lidas.']);
            exit;
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
        // Use unified notifications to get accurate count (includes messages and filters by condominium access)
        $notifications = $this->notificationService->getUnifiedNotifications($userId, 1000);
        $unreadCount = count(array_filter($notifications, function($n) {
            return !$n['is_read'];
        }));

        header('Content-Type: application/json');
        echo json_encode(['count' => $unreadCount]);
        exit;
    }

    /**
     * Get separate counts for messages and notifications
     * Returns JSON with unread_messages_count and unread_notifications_count
     */
    public function getCounts()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $currentCondominiumId = $_SESSION['current_condominium_id'] ?? null;
        
        $unreadMessagesCount = 0;
        $unreadNotificationsCount = 0;
        $systemNotificationsCount = 0;
        
        global $db;
        if ($db) {
            try {
                // Get notifications filtered by current condominium if set
                $notificationService = new \App\Services\NotificationService();
                if ($currentCondominiumId) {
                    // Get notifications only for current condominium
                    $notifications = $notificationService->getUserNotifications($userId, 1000);
                    $notifications = array_filter($notifications, function($n) use ($currentCondominiumId) {
                        return isset($n['condominium_id']) && $n['condominium_id'] == $currentCondominiumId;
                    });
                } else {
                    // If no condominium selected, get all notifications
                    $notifications = $notificationService->getUserNotifications($userId, 1000);
                }
                
                $systemNotificationsCount = count(array_filter($notifications, function($n) {
                    return !$n['is_read'];
                }));
            } catch (\Exception $e) {
                // Silently fail if notifications table doesn't exist or other error
                $systemNotificationsCount = 0;
            }
            
            // Get unread messages count for current condominium only
            try {
                if ($currentCondominiumId) {
                    // Count unread messages only for current condominium
                    $messageModel = new \App\Models\Message();
                    $unreadMessagesCount = $messageModel->getUnreadCount($currentCondominiumId, $userId);
                } else {
                    // If no condominium selected, count messages from all condominiums user has access to
                    $userRole = $_SESSION['user']['role'] ?? 'condomino';
                    if ($userRole === 'admin' || $userRole === 'super_admin') {
                        $condominiumModel = new \App\Models\Condominium();
                        $userCondominiums = $condominiumModel->getByUserId($userId);
                    } else {
                        $condominiumUserModel = new \App\Models\CondominiumUser();
                        $userCondominiumsList = $condominiumUserModel->getUserCondominiums($userId);
                        $condominiumModel = new \App\Models\Condominium();
                        $userCondominiums = [];
                        foreach ($userCondominiumsList as $uc) {
                            $condo = $condominiumModel->findById($uc['condominium_id']);
                            if ($condo) {
                                $userCondominiums[] = $condo;
                            }
                        }
                    }
                    
                    $messageModel = new \App\Models\Message();
                    foreach ($userCondominiums as $condo) {
                        $unreadMessagesCount += $messageModel->getUnreadCount($condo['id'], $userId);
                    }
                }
            } catch (\Exception $e) {
                // Silently fail if messages table doesn't exist or other error
                $unreadMessagesCount = 0;
            }
            
            // Unified count includes both notifications and messages
            $unreadNotificationsCount = $systemNotificationsCount + $unreadMessagesCount;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'unread_messages_count' => $unreadMessagesCount,
            'unread_notifications_count' => $unreadNotificationsCount
        ]);
        exit;
    }
}
