<?php

namespace App\Controllers\Mobile;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Models\Message;
use App\Models\Condominium;
use App\Models\CondominiumUser;

class MessageController extends Controller
{
    /**
     * List messages using the same view as full version (pages/messages/index.html.twig)
     * with tabs Recebidas/Enviadas and replies grouped. Renders with mobile template.
     */
    public function index(): void
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $messageModel = new Message();
        $condominiumModel = new Condominium();
        $condominiumUserModel = new CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        $condominiumIds = array_unique(array_map(function ($uc) {
            return (int)$uc['condominium_id'];
        }, $userCondominiums));

        $organizedReceived = [];
        $organizedSent = [];
        foreach ($condominiumIds as $condoId) {
            $received = $messageModel->getByCondominium($condoId, ['recipient_id' => $userId]);
            $sent = $messageModel->getByCondominium($condoId, ['sender_id' => $userId]);
            foreach ($received as $msg) {
                $msg['replies'] = $messageModel->getReplies($msg['id']);
                foreach ($msg['replies'] as &$reply) {
                    $reply['condominium_id'] = $condoId;
                }
                unset($reply);
                $msg['condominium_id'] = $condoId;
                $msg['is_sent'] = false;
                $organizedReceived[] = $msg;
            }
            foreach ($sent as $msg) {
                $msg['replies'] = $messageModel->getReplies($msg['id']);
                foreach ($msg['replies'] as &$reply) {
                    $reply['condominium_id'] = $condoId;
                }
                unset($reply);
                $msg['condominium_id'] = $condoId;
                $msg['is_sent'] = true;
                $organizedSent[] = $msg;
            }
        }
        usort($organizedReceived, function ($a, $b) {
            return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
        });
        usort($organizedSent, function ($a, $b) {
            return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
        });

        $firstCondominium = null;
        if (!empty($userCondominiums)) {
            $firstCondominium = $condominiumModel->findById((int)$userCondominiums[0]['condominium_id']);
        }
        $condominium = $firstCondominium;

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        $this->loadPageTranslations('messages');
        $this->data += [
            'viewName' => 'pages/messages/index.html.twig',
            'page' => ['titulo' => 'Mensagens'],
            'condominium' => $condominium,
            'received_messages' => $organizedReceived,
            'sent_messages' => $organizedSent,
            'current_user_id' => $userId,
            'error' => $error,
            'success' => $success,
        ];

        $_SESSION['mobile_version'] = true;
        $this->renderMobileTemplate();
    }

    /**
     * Show one message (Ver mais) in mobile layout.
     */
    public function show(): void
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: ' . BASE_URL . 'm/messages');
            exit;
        }

        $messageModel = new Message();
        $condominiumModel = new Condominium();
        $message = $messageModel->findById($id);
        if (!$message) {
            $_SESSION['error'] = 'Mensagem não encontrada.';
            header('Location: ' . BASE_URL . 'm/messages');
            exit;
        }

        $condoId = (int)($message['condominium_id'] ?? 0);
        $condo = $condominiumModel->findById($condoId);
        $message['condominium_name'] = $condo['name'] ?? '';

        // Ensure user has access (is recipient or announcement)
        $toUserId = $message['to_user_id'] ?? null;
        if ($toUserId !== null && (int)$toUserId !== $userId) {
            $condominiumUserModel = new CondominiumUser();
            $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
            $hasAccess = false;
            foreach ($userCondominiums as $uc) {
                if ((int)$uc['condominium_id'] === $condoId) {
                    $hasAccess = true;
                    break;
                }
            }
            if (!$hasAccess) {
                $_SESSION['error'] = 'Mensagem não encontrada.';
                header('Location: ' . BASE_URL . 'm/messages');
                exit;
            }
        }

        $this->data += [
            'viewName' => 'pages/m/message-show.html.twig',
            'page' => ['titulo' => $message['subject'] ?? 'Mensagem'],
            'message' => $message,
            'user' => AuthMiddleware::user(),
        ];

        $_SESSION['mobile_version'] = true;
        $this->renderMobileTemplate();
    }

    /**
     * Mark message as read; redirect to /m/messages.
     */
    public function markAsRead(string $id): void
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $messageId = (int)$id;
        $messageModel = new Message();
        $message = $messageModel->findById($messageId);
        if ($message) {
            $toUserId = $message['to_user_id'] ?? null;
            if ($toUserId === null || (int)$toUserId === $userId) {
                $messageModel->markAsRead($messageId);
                $_SESSION['success'] = 'Mensagem marcada como lida.';
            }
        } else {
            $_SESSION['error'] = 'Mensagem não encontrada.';
        }

        header('Location: ' . BASE_URL . 'm/messages');
        exit;
    }
}
