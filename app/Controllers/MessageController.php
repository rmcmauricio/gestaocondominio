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
        
        // Get received messages (where user is recipient or announcements)
        $receivedMessages = $this->messageModel->getByCondominium($condominiumId, ['recipient_id' => $userId]);
        
        // Get sent messages (where user is sender)
        $sentMessages = $this->messageModel->getByCondominium($condominiumId, ['sender_id' => $userId]);
        
        // Organize received messages with replies
        $organizedReceived = [];
        foreach ($receivedMessages as $msg) {
            $msg['replies'] = $this->messageModel->getReplies($msg['id']);
            // Include all replies in the thread (they're part of the conversation)
            $msg['is_sent'] = false; // Mark as received
            $organizedReceived[] = $msg;
        }
        
        // Sort received messages by created_at DESC (most recent first)
        usort($organizedReceived, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Organize sent messages with replies
        $organizedSent = [];
        foreach ($sentMessages as $msg) {
            $msg['replies'] = $this->messageModel->getReplies($msg['id']);
            // Include all replies in the thread (they're part of the conversation)
            $msg['is_sent'] = true; // Mark as sent
            $organizedSent[] = $msg;
        }
        
        // Sort sent messages by created_at DESC (most recent first)
        usort($organizedSent, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        $this->loadPageTranslations('messages');
        
        $this->data += [
            'viewName' => 'pages/messages/index.html.twig',
            'page' => ['titulo' => 'Mensagens'],
            'condominium' => $condominium,
            'received_messages' => $organizedReceived,
            'sent_messages' => $organizedSent,
            'current_user_id' => $userId,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        $this->renderMainTemplate();
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
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/messages/create.html.twig',
            'page' => ['titulo' => 'Nova Mensagem'],
            'condominium' => $condominium,
            'users' => $users,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
        ];

        $this->renderMainTemplate();
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
            // Sanitize HTML content - allows safe tags but removes scripts and dangerous attributes
            $messageContent = Security::sanitizeHtml($messageContent);
            
            $messageId = $this->messageModel->create([
                'condominium_id' => $condominiumId,
                'sender_id' => $userId,
                'recipient_id' => $recipientId,
                'subject' => Security::sanitize($_POST['subject'] ?? ''),
                'message' => $messageContent,
                'message_type' => $recipientId ? 'private' : 'announcement'
            ]);

            // Handle file attachments with rate limiting
            if (!empty($_FILES['attachments']['name'][0])) {
                // Check rate limit for file uploads
                try {
                    \App\Middleware\RateLimitMiddleware::require('file_upload');
                } catch (\Exception $e) {
                    $_SESSION['error'] = $e->getMessage();
                    header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/create');
                    exit;
                }
                
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
                            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
                        } catch (\Exception $e) {
                            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
                            error_log("Error uploading attachment: " . $e->getMessage());
                            // Continue with other attachments
                        }
                    }
                }
            }

            // Send email notifications
            try {
                $emailService = new \App\Core\EmailService();
                $preferenceModel = new \App\Models\UserEmailPreference();
                $sender = AuthMiddleware::user();
                $senderName = $sender['name'] ?? 'Sistema';
                $messageLink = BASE_URL . 'condominiums/' . $condominiumId . '/messages/' . $messageId;
                $subject = Security::sanitize($_POST['subject'] ?? '');
                $isAnnouncement = ($recipientId === null);

                if ($isAnnouncement) {
                    // Announcement: send to all users in condominium (except demo users)
                    global $db;
                    $stmt = $db->prepare("
                        SELECT DISTINCT u.id, u.email, u.name
                        FROM users u
                        INNER JOIN condominium_users cu ON cu.user_id = u.id
                        WHERE cu.condominium_id = :condominium_id
                        AND u.id != :sender_id
                    ");
                    $stmt->execute([
                        ':condominium_id' => $condominiumId,
                        ':sender_id' => $userId
                    ]);
                    $recipients = $stmt->fetchAll();

                    foreach ($recipients as $recipient) {
                        // Skip demo users
                        if (\App\Middleware\DemoProtectionMiddleware::isDemoUser($recipient['id'])) {
                            continue;
                        }

                        // Check preferences
                        if ($preferenceModel->hasEmailEnabled($recipient['id'], 'message')) {
                            $emailService->sendMessageEmail(
                                $recipient['email'],
                                $recipient['name'] ?? 'Utilizador',
                                $senderName,
                                $subject,
                                $messageContent,
                                $messageLink,
                                true, // isAnnouncement
                                $recipient['id']
                            );
                        }
                    }
                } else {
                    // Private message: send to recipient only
                    // Check if recipient is demo
                    if (!\App\Middleware\DemoProtectionMiddleware::isDemoUser($recipientId)) {
                        // Get recipient info
                        global $db;
                        $stmt = $db->prepare("SELECT email, name FROM users WHERE id = :user_id LIMIT 1");
                        $stmt->execute([':user_id' => $recipientId]);
                        $recipient = $stmt->fetch();

                        if ($recipient && !empty($recipient['email'])) {
                            // Check preferences
                            if ($preferenceModel->hasEmailEnabled($recipientId, 'message')) {
                                $emailService->sendMessageEmail(
                                    $recipient['email'],
                                    $recipient['name'] ?? 'Utilizador',
                                    $senderName,
                                    $subject,
                                    $messageContent,
                                    $messageLink,
                                    false, // isAnnouncement
                                    $recipientId
                                );
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log error but don't fail message creation
                error_log("Failed to send message email: " . $e->getMessage());
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
        
        // Get root message ID (if this is a reply, get the original)
        $rootMessageId = $this->messageModel->getRootMessageId($id);
        
        // If this message is not the root, get the root message
        $rootMessage = ($rootMessageId == $id) ? $message : $this->messageModel->findById($rootMessageId);
        
        // Check if root message was unread before marking (to know if we should update counters)
        $wasJustMarkedAsRead = false;
        $recipientId = $rootMessage['to_user_id'] ?? $rootMessage['recipient_id'] ?? null;
        if (($recipientId == $userId || $recipientId === null) && !$rootMessage['is_read']) {
            // Mark as read if user is recipient or if it's an announcement (to_user_id IS NULL)
            // For announcements, we need to track read status per user
            // For now, we'll mark it as read in the main table (simple approach)
            // In a production system, you'd want a message_reads table
            $this->messageModel->markAsRead($rootMessageId);
            $wasJustMarkedAsRead = true;
            // Reload root message to get updated read status
            $rootMessage = $this->messageModel->findById($rootMessageId);
        }
        
        // Also mark the specific message being viewed as read if it's different from root
        if ($id != $rootMessageId) {
            $recipientId = $message['to_user_id'] ?? $message['recipient_id'] ?? null;
            if (($recipientId == $userId || $recipientId === null) && !$message['is_read']) {
                $this->messageModel->markAsRead($id);
                if (!$wasJustMarkedAsRead) {
                    $wasJustMarkedAsRead = true;
                }
            }
        }
        
        // Get all replies for this thread
        $replies = $this->messageModel->getReplies($rootMessageId);
        
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

        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);

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
            'was_just_marked_as_read' => $wasJustMarkedAsRead,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
        ];

        $this->renderMainTemplate();
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
            // Sanitize HTML content - allows safe tags but removes scripts and dangerous attributes
            $messageContent = Security::sanitizeHtml($messageContent);
            
            $replyId = $this->messageModel->create([
                'condominium_id' => $condominiumId,
                'sender_id' => $userId,
                'recipient_id' => $recipientId,
                'thread_id' => $rootMessageId,
                'subject' => 'Re: ' . $originalMessage['subject'],
                'message' => $messageContent
            ]);

            // Handle file attachments for reply with rate limiting
            if (!empty($_FILES['attachments']['name'][0])) {
                // Check rate limit for file uploads
                try {
                    \App\Middleware\RateLimitMiddleware::require('file_upload');
                } catch (\Exception $e) {
                    $_SESSION['error'] = $e->getMessage();
                    header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/messages/' . $id);
                    exit;
                }
                
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
                            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
                        } catch (\Exception $e) {
                            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
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

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Método não permitido', 400, 'INVALID_METHOD');
        }

        if (empty($_FILES['file'])) {
            $this->jsonError('Nenhum ficheiro enviado', 400, 'NO_FILE');
        }

        // Check rate limit for file uploads
        try {
            \App\Middleware\RateLimitMiddleware::require('file_upload');
        } catch (\Exception $e) {
            $this->jsonError($e, 429, 'RATE_LIMIT_EXCEEDED');
        }

        try {
            $uploadResult = $this->fileStorageService->upload($_FILES['file'], $condominiumId, 'messages', 'inline', 2097152);
            
            // Record successful upload
            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
            
            // Return URL for TinyMCE
            $fileUrl = $this->fileStorageService->getFileUrl($uploadResult['file_path']);
            
            $this->jsonSuccess(['location' => $fileUrl]);
        } catch (\Exception $e) {
            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
            $this->jsonError($e, 400, 'UPLOAD_ERROR');
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





