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
        $notifications = $this->notificationService->getUserNotifications($userId, 50);

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

    public function markAsRead(int $id)
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        
        // Verify notification belongs to user
        global $db;
        if ($db) {
            $stmt = $db->prepare("SELECT id FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1");
            $stmt->execute([':id' => $id, ':user_id' => $userId]);
            $notification = $stmt->fetch();
            
            if ($notification) {
                $this->notificationService->markAsRead($id);
                $_SESSION['success'] = 'Notificação marcada como lida.';
            } else {
                $_SESSION['error'] = 'Notificação não encontrada.';
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
            $stmt = $db->prepare("
                UPDATE notifications 
                SET is_read = TRUE, read_at = NOW() 
                WHERE user_id = :user_id AND is_read = FALSE
            ");
            $stmt->execute([':user_id' => $userId]);
            $_SESSION['success'] = 'Todas as notificações foram marcadas como lidas.';
        }

        header('Location: ' . BASE_URL . 'notifications');
        exit;
    }

    public function delete(int $id)
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        
        global $db;
        if ($db) {
            $stmt = $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $id, ':user_id' => $userId]);
            $_SESSION['success'] = 'Notificação eliminada.';
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
