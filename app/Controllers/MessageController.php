<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Message;
use App\Models\Condominium;
use App\Models\MessageAttachment;
use App\Services\NotificationService;
use App\Services\FileStorageService;

class MessageController extends Controller
{
    protected $messageModel;
    protected $condominiumModel;
    protected $notificationService;
    protected $fileStorageService;

    public function __construct()
    {
        parent::__construct();
        $this->messageModel = new Message();
        $this->condominiumModel = new Condominium();
        $this->notificationService = new NotificationService();
        $this->fileStorageService = new FileStorageService();
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
        
        // Get root messages (messages without thread_id)
        $rootMessages = $this->messageModel->getByCondominium($condominiumId, ['recipient_id' => $userId]);
        $rootSentMessages = $this->messageModel->getByCondominium($condominiumId, ['sender_id' => $userId]);
        
        // Get all replies for received messages
        $organizedMessages = [];
        foreach ($rootMessages as $msg) {
            $msg['replies'] = $this->messageModel->getReplies($msg['id']);
            // Filter replies to only show those relevant to this user
            $msg['replies'] = array_filter($msg['replies'], function($reply) use ($userId) {
                return $reply['to_user_id'] == $userId || $reply['to_user_id'] === null || $reply['from_user_id'] == $userId;
            });
            $msg['is_sent'] = false; // Mark as received
            $organizedMessages[] = $msg;
        }
        
        // Get all replies for sent messages
        foreach ($rootSentMessages as $msg) {
            $msg['replies'] = $this->messageModel->getReplies($msg['id']);
            // Filter replies to only show those sent by this user or received by this user
            $msg['replies'] = array_filter($msg['replies'], function($reply) use ($userId) {
                return $reply['from_user_id'] == $userId || $reply['to_user_id'] == $userId;
            });
            $msg['is_sent'] = true; // Mark as sent
            $organizedMessages[] = $msg;
        }
        
        // Sort all messages by created_at DESC (most recent first)
        usort($organizedMessages, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        $this->loadPageTranslations('messages');
        
        $this->data += [
            'viewName' => 'pages/messages/index.html.twig',
            'page' => ['titulo' => 'Mensagens'],
            'condominium' => $condominium,
            'messages' => $organizedMessages,
            'current_user_id' => $userId,
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
            // Get HTML message content from TinyMCE
            $messageContent = $_POST['message'] ?? '';
            // Basic HTML sanitization - allow common formatting tags
            // Note: We allow HTML tags for rich text editing
            $allowedTags = '<p><br><br/><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><a><img><blockquote><code><pre><div><span>';
            $messageContent = strip_tags($messageContent, $allowedTags);
            // Don't escape HTML - we want to store it as-is for rendering
            
            $messageId = $this->messageModel->create([
                'condominium_id' => $condominiumId,
                'sender_id' => $userId,
                'recipient_id' => $recipientId,
                'subject' => Security::sanitize($_POST['subject'] ?? ''),
                'message' => $messageContent,
                'message_type' => $recipientId ? 'private' : 'announcement'
            ]);

            // Handle file attachments
            if (!empty($_FILES['attachments']['name'][0])) {
                $attachmentModel = new MessageAttachment();
                $fileCount = count($_FILES['attachments']['name']);
                
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        try {
                            $file = [
                                'name' => $_FILES['attachments']['name'][$i],
                                'type' => $_FILES['attachments']['type'][$i],
                                'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                                'error' => $_FILES['attachments']['error'][$i],
                                'size' => $_FILES['attachments']['size'][$i]
                            ];
                            
                            $uploadResult = $this->fileStorageService->upload($file, $condominiumId, 'messages', 'attachments');
                            
                            $attachmentModel->create([
                                'message_id' => $messageId,
                                'condominium_id' => $condominiumId,
                                'file_path' => $uploadResult['file_path'],
                                'file_name' => $uploadResult['file_name'],
                                'file_size' => $uploadResult['file_size'],
                                'mime_type' => $uploadResult['mime_type'],
                                'uploaded_by' => $userId
                            ]);
                        } catch (\Exception $e) {
                            error_log("Error uploading attachment: " . $e->getMessage());
                            // Continue with other attachments
                        }
                    }
                }
            }

            // Notify recipient if private message
            // For announcements (to_user_id IS NULL), notifications are handled via unified notifications
            // No need to create separate notification entries as messages appear in unified list

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
        
        // Mark as read if user is recipient or if it's an announcement (to_user_id IS NULL)
        $recipientId = $message['to_user_id'] ?? $message['recipient_id'] ?? null;
        if (($recipientId == $userId || $recipientId === null) && !$message['is_read']) {
            // For announcements, we need to track read status per user
            // For now, we'll mark it as read in the main table (simple approach)
            // In a production system, you'd want a message_reads table
            $this->messageModel->markAsRead($id);
        }

        // Get root message ID (if this is a reply, get the original)
        $rootMessageId = $this->messageModel->getRootMessageId($id);
        
        // Get all replies for this thread
        $replies = $this->messageModel->getReplies($rootMessageId);
        
        // If this message is not the root, get the root message
        $rootMessage = ($rootMessageId == $id) ? $message : $this->messageModel->findById($rootMessageId);
        
        // Determine if we're viewing a specific reply or the root message
        $isViewingReply = ($rootMessageId != $id);
        $viewingMessage = $isViewingReply ? $message : $rootMessage;
        
        // Get attachments for root message and all replies
        $attachmentModel = new MessageAttachment();
        $messageAttachments = $attachmentModel->getByMessage($rootMessageId);
        foreach ($replies as &$reply) {
            $reply['attachments'] = $attachmentModel->getByMessage($reply['id']);
            // Mark if this is the message being viewed
            $reply['is_viewing'] = ($reply['id'] == $id);
        }
        
        // Get condominium for sidebar
        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('messages');
        
        // Determine page title
        $pageTitle = $isViewingReply ? $viewingMessage['subject'] : $rootMessage['subject'];
        
        $this->data += [
            'viewName' => 'pages/messages/show.html.twig',
            'page' => ['titulo' => $pageTitle],
            'condominium' => $condominium,
            'message' => $rootMessage,
            'replies' => $replies,
            'attachments' => $messageAttachments,
            'current_user_id' => $userId,
            'viewing_message_id' => $id, // ID of the message being viewed (could be root or reply)
            'is_viewing_reply' => $isViewingReply,
            'viewing_message' => $viewingMessage,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
    
    /**
     * Reply to a message
     */
    public function reply(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/' . $id);
            exit;
        }

        $originalMessage = $this->messageModel->findById($id);
        if (!$originalMessage || $originalMessage['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Mensagem não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages');
            exit;
        }

        $userId = AuthMiddleware::userId();
        
        // Get root message ID
        $rootMessageId = $this->messageModel->getRootMessageId($id);
        
        // Determine recipient: if replying to a message sent to me, reply to sender
        // If replying to an announcement, reply to sender
        $recipientId = null;
        if ($originalMessage['to_user_id'] == $userId) {
            // This was sent to me, reply to sender
            $recipientId = $originalMessage['from_user_id'];
        } elseif ($originalMessage['to_user_id'] === null) {
            // This is an announcement, reply to sender
            $recipientId = $originalMessage['from_user_id'];
        } elseif ($originalMessage['from_user_id'] == $userId) {
            // This was sent by me, reply to recipient
            $recipientId = $originalMessage['to_user_id'];
        } else {
            // Default: reply to original sender
            $recipientId = $originalMessage['from_user_id'];
        }

        try {
            // Get HTML message content from TinyMCE
            $messageContent = $_POST['message'] ?? '';
            // Basic HTML sanitization - allow common formatting tags
            $allowedTags = '<p><br><br/><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><a><img><blockquote><code><pre><div><span>';
            $messageContent = strip_tags($messageContent, $allowedTags);
            // Don't escape HTML - we want to store it as-is for rendering
            
            $replyId = $this->messageModel->create([
                'condominium_id' => $condominiumId,
                'sender_id' => $userId,
                'recipient_id' => $recipientId,
                'thread_id' => $rootMessageId,
                'subject' => 'Re: ' . $originalMessage['subject'],
                'message' => $messageContent
            ]);

            // Handle file attachments for reply
            if (!empty($_FILES['attachments']['name'][0])) {
                $attachmentModel = new MessageAttachment();
                $fileCount = count($_FILES['attachments']['name']);
                
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        try {
                            $file = [
                                'name' => $_FILES['attachments']['name'][$i],
                                'type' => $_FILES['attachments']['type'][$i],
                                'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                                'error' => $_FILES['attachments']['error'][$i],
                                'size' => $_FILES['attachments']['size'][$i]
                            ];
                            
                            $uploadResult = $this->fileStorageService->upload($file, $condominiumId, 'messages', 'attachments');
                            
                            $attachmentModel->create([
                                'message_id' => $replyId,
                                'condominium_id' => $condominiumId,
                                'file_path' => $uploadResult['file_path'],
                                'file_name' => $uploadResult['file_name'],
                                'file_size' => $uploadResult['file_size'],
                                'mime_type' => $uploadResult['mime_type'],
                                'uploaded_by' => $userId
                            ]);
                        } catch (\Exception $e) {
                            error_log("Error uploading attachment: " . $e->getMessage());
                        }
                    }
                }
            }

            // Notify recipient
            if ($recipientId) {
                $this->notificationService->createNotification(
                    $recipientId,
                    $condominiumId,
                    'message',
                    'Nova Resposta',
                    'Recebeu uma resposta: ' . $originalMessage['subject'],
                    BASE_URL . 'condominiums/' . $condominiumId . '/messages/' . $rootMessageId
                );
            }

            $_SESSION['success'] = 'Resposta enviada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/' . $rootMessageId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao enviar resposta: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/' . $id);
            exit;
        }
    }
    
    /**
     * Upload inline image for editor
     */
    public function uploadInlineImage(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(400);
            echo json_encode(['error' => 'Método não permitido']);
            exit;
        }

        if (empty($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nenhum ficheiro enviado']);
            exit;
        }

        try {
            $uploadResult = $this->fileStorageService->upload($_FILES['file'], $condominiumId, 'messages', 'inline', 2097152);
            
            // Return URL for TinyMCE
            $fileUrl = $this->fileStorageService->getFileUrl($uploadResult['file_path']);
            
            echo json_encode([
                'location' => $fileUrl
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    /**
     * Download attachment
     */
    public function downloadAttachment(int $condominiumId, int $messageId, int $attachmentId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $attachmentModel = new MessageAttachment();
        $attachment = $attachmentModel->findById($attachmentId);

        if (!$attachment || $attachment['message_id'] != $messageId || $attachment['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Anexo não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/' . $messageId);
            exit;
        }

        $filePath = $this->fileStorageService->getFilePath($attachment['file_path']);
        
        if (!file_exists($filePath)) {
            $_SESSION['error'] = 'Ficheiro não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/' . $messageId);
            exit;
        }

        header('Content-Type: ' . ($attachment['mime_type'] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . htmlspecialchars($attachment['file_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}





