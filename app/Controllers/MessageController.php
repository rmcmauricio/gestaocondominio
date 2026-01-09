<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Message;
use App\Models\Condominium;
use App\Services\NotificationService;

class MessageController extends Controller
{
    protected $messageModel;
    protected $condominiumModel;
    protected $notificationService;

    public function __construct()
    {
        parent::__construct();
        $this->messageModel = new Message();
        $this->condominiumModel = new Condominium();
        $this->notificationService = new NotificationService();
    }

    public function index(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $messages = $this->messageModel->getByCondominium($condominiumId, ['recipient_id' => $userId]);
        $sentMessages = $this->messageModel->getByCondominium($condominiumId, ['sender_id' => $userId]);

        $this->loadPageTranslations('messages');
        
        $this->data += [
            'viewName' => 'pages/messages/index.html.twig',
            'page' => ['titulo' => 'Mensagens'],
            'condominium' => $condominium,
            'messages' => $messages,
            'sent_messages' => $sentMessages,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function create(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        // Get users in condominium
        global $db;
        $users = [];
        if ($db) {
            $stmt = $db->prepare("
                SELECT DISTINCT u.id, u.name, u.email
                FROM users u
                INNER JOIN condominium_users cu ON cu.user_id = u.id
                WHERE cu.condominium_id = :condominium_id
                ORDER BY u.name ASC
            ");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $users = $stmt->fetchAll() ?: [];
        }

        $this->loadPageTranslations('messages');
        
        $this->data += [
            'viewName' => 'pages/messages/create.html.twig',
            'page' => ['titulo' => 'Nova Mensagem'],
            'condominium' => $condominium,
            'users' => $users,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/create');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $recipientId = !empty($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : null;

        try {
            $messageId = $this->messageModel->create([
                'condominium_id' => $condominiumId,
                'sender_id' => $userId,
                'recipient_id' => $recipientId,
                'subject' => Security::sanitize($_POST['subject'] ?? ''),
                'message' => Security::sanitize($_POST['message'] ?? ''),
                'message_type' => $recipientId ? 'private' : 'announcement'
            ]);

            // Notify recipient if private message
            if ($recipientId) {
                $this->notificationService->createNotification(
                    $recipientId,
                    $condominiumId,
                    'message',
                    'Nova Mensagem',
                    'Recebeu uma nova mensagem: ' . Security::sanitize($_POST['subject'] ?? ''),
                    BASE_URL . 'condominiums/' . $condominiumId . '/messages'
                );
            }

            $_SESSION['success'] = 'Mensagem enviada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao enviar mensagem: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/create');
            exit;
        }
    }

    public function show(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $message = $this->messageModel->findById($id);
        
        if (!$message || $message['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Mensagem não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages');
            exit;
        }

        $userId = AuthMiddleware::userId();
        
        // Mark as read if user is recipient
        if ($message['recipient_id'] == $userId && !$message['is_read']) {
            $this->messageModel->markAsRead($id);
        }

        $this->loadPageTranslations('messages');
        
        $this->data += [
            'viewName' => 'pages/messages/show.html.twig',
            'page' => ['titulo' => $message['subject']],
            'message' => $message
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}





