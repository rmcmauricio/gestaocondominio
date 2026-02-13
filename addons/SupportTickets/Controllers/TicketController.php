<?php

namespace Addons\SupportTickets\Controllers;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

class TicketController extends Controller
{
    protected function requireAdminOrSuper(): void
    {
        AuthMiddleware::require();
        $user = $_SESSION['user'] ?? null;
        if (!$user || !in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
            $_SESSION['error'] = 'Apenas administradores podem aceder aos tickets.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
    }

    protected function isSuperAdmin(): bool
    {
        return (($_SESSION['user']['role'] ?? '') === 'super_admin');
    }

    public function index(): void
    {
        $this->requireAdminOrSuper();
        $userId = (int) ($_SESSION['user']['id'] ?? 0);
        $ticketModel = new \Addons\SupportTickets\Models\SupportTicket();
        $isSuper = $this->isSuperAdmin();
        $tickets = $ticketModel->getForUser($userId, $isSuper);
        $this->data['viewName'] = '@addon_support_tickets/index.html.twig';
        $this->data['tickets'] = $tickets;
        $this->data['show_user_column'] = $isSuper;
        $this->data['page'] = ['titulo' => 'Tickets', 'description' => '', 'keywords' => ''];
        $this->mergeGlobalData($this->data);
        $this->renderMainTemplate();
    }

    public function create(): void
    {
        $this->requireAdminOrSuper();
        $this->data['viewName'] = '@addon_support_tickets/create.html.twig';
        $this->data['page'] = ['titulo' => 'Novo ticket', 'description' => '', 'keywords' => ''];
        $this->mergeGlobalData($this->data);
        $this->renderMainTemplate();
    }

    public function store(): void
    {
        $this->requireAdminOrSuper();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/tickets');
            exit;
        }
        $subject = trim($_POST['subject'] ?? '');
        $type = $_POST['type'] ?? 'problem';
        $bodyHtml = $_POST['body_html'] ?? '';
        if ($subject === '') {
            $_SESSION['error'] = 'Indique o assunto.';
            header('Location: ' . BASE_URL . 'admin/tickets/create');
            exit;
        }
        $allowedTypes = ['problem', 'error', 'suggestion'];
        if (!in_array($type, $allowedTypes)) {
            $type = 'problem';
        }
        $bodyHtml = \App\Core\Security::sanitizeHtml($bodyHtml);
        $userId = (int) ($_SESSION['user']['id'] ?? 0);
        $ticketModel = new \Addons\SupportTickets\Models\SupportTicket();
        $messageModel = new \Addons\SupportTickets\Models\SupportTicketMessage();
        $ticketId = $ticketModel->create($userId, $subject, $type);
        $messageModel->add($ticketId, $userId, $bodyHtml);
        $_SESSION['success'] = 'Ticket criado com sucesso.';
        header('Location: ' . BASE_URL . 'admin/tickets/' . $ticketId);
        exit;
    }

    public function show(string $id): void
    {
        $this->requireAdminOrSuper();
        $ticketId = (int) $id;
        $userId = (int) ($_SESSION['user']['id'] ?? 0);
        $ticketModel = new \Addons\SupportTickets\Models\SupportTicket();
        if (!$ticketModel->canAccess($ticketId, $userId, $this->isSuperAdmin())) {
            $_SESSION['error'] = 'Ticket não encontrado.';
            header('Location: ' . BASE_URL . 'admin/tickets');
            exit;
        }
        $ticket = $ticketModel->findById($ticketId);
        $messageModel = new \Addons\SupportTickets\Models\SupportTicketMessage();
        $messages = $messageModel->getByTicketId($ticketId);
        $this->data['viewName'] = '@addon_support_tickets/show.html.twig';
        $this->data['ticket'] = $ticket;
        $this->data['messages'] = $messages;
        $this->data['page'] = ['titulo' => 'Ticket #' . $ticketId, 'description' => '', 'keywords' => ''];
        $this->mergeGlobalData($this->data);
        $this->renderMainTemplate();
    }

    public function reply(string $id): void
    {
        $this->requireAdminOrSuper();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/tickets');
            exit;
        }
        $ticketId = (int) $id;
        $userId = (int) ($_SESSION['user']['id'] ?? 0);
        $ticketModel = new \Addons\SupportTickets\Models\SupportTicket();
        if (!$ticketModel->canAccess($ticketId, $userId, $this->isSuperAdmin())) {
            $_SESSION['error'] = 'Ticket não encontrado.';
            header('Location: ' . BASE_URL . 'admin/tickets');
            exit;
        }
        $bodyHtml = \App\Core\Security::sanitizeHtml($_POST['body_html'] ?? '');
        if ($bodyHtml === '') {
            $_SESSION['error'] = 'Escreva uma resposta.';
            header('Location: ' . BASE_URL . 'admin/tickets/' . $ticketId);
            exit;
        }
        $messageModel = new \Addons\SupportTickets\Models\SupportTicketMessage();
        $messageModel->add($ticketId, $userId, $bodyHtml);
        $_SESSION['success'] = 'Resposta adicionada.';
        header('Location: ' . BASE_URL . 'admin/tickets/' . $ticketId);
        exit;
    }

    public function updateStatus(string $id): void
    {
        $this->requireAdminOrSuper();
        if (!$this->isSuperAdmin()) {
            $_SESSION['error'] = 'Apenas super administradores podem alterar o estado.';
            header('Location: ' . BASE_URL . 'admin/tickets');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/tickets');
            exit;
        }
        $ticketId = (int) $id;
        $status = $_POST['status'] ?? '';
        $ticketModel = new \Addons\SupportTickets\Models\SupportTicket();
        $ticketModel->updateStatus($ticketId, $status);
        $_SESSION['success'] = 'Estado atualizado.';
        header('Location: ' . BASE_URL . 'admin/tickets/' . $ticketId);
        exit;
    }

    public function uploadInlineImage(): void
    {
        $this->requireAdminOrSuper();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Método não permitido', 400, 'INVALID_METHOD');
        }
        if (empty($_FILES['file'])) {
            $this->jsonError('Nenhum ficheiro enviado', 400, 'NO_FILE');
        }
        try {
            \App\Middleware\RateLimitMiddleware::require('file_upload');
        } catch (\Exception $e) {
            $this->jsonError($e, 429, 'RATE_LIMIT_EXCEEDED');
        }
        $userId = (int) ($_SESSION['user']['id'] ?? 0);
        try {
            $service = new \Addons\SupportTickets\Services\TicketUploadService();
            $result = $service->upload($_FILES['file'], $userId);
            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
            $url = $service->getFileUrl($result['file_path']);
            $this->jsonSuccess(['location' => $url]);
        } catch (\Exception $e) {
            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
            $this->jsonError($e, 400, 'UPLOAD_ERROR');
        }
        exit;
    }
}
