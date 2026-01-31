<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Services\InvitationService;
use App\Services\AuditService;

class InvitationController extends Controller
{
    protected $invitationService;
    protected $auditService;

    public function __construct()
    {
        parent::__construct();
        $this->invitationService = new InvitationService();
        $this->auditService = new AuditService();
    }

    public function create(int $condominiumId, int $fractionId = null)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        // Get condominium for sidebar
        $condominiumModel = new \App\Models\Condominium();
        $condominium = $condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('invitations');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/invitations/create.html.twig',
            'page' => ['titulo' => 'Convidar Condómino'],
            'condominium' => $condominium,
            'condominium_id' => $condominiumId,
            'fraction_id' => $fractionId,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/invitations/create');
            exit;
        }

        $email = trim(Security::sanitize($_POST['email'] ?? ''));
        $name = trim(Security::sanitize($_POST['name'] ?? ''));
        $fractionId = !empty($_POST['fraction_id']) && $_POST['fraction_id'] !== '' ? (int)$_POST['fraction_id'] : null;
        $role = Security::sanitize($_POST['role'] ?? 'condomino');

        if (empty($email) || empty($name)) {
            $_SESSION['error'] = 'Por favor, preencha todos os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/invitations/create');
            exit;
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Por favor, insira um email válido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/invitations/create');
            exit;
        }

        try {
            if ($this->invitationService->sendInvitation($condominiumId, $fractionId, $email, $name, $role)) {
                $_SESSION['success'] = 'Convite enviado com sucesso!';
                
                // Log audit: invitation sent is an important administrative action
                $this->auditService->log([
                    'action' => 'invitation_sent',
                    'model' => 'invitation',
                    'model_id' => null,
                    'description' => "Convite enviado para {$name} ({$email}) no condomínio ID {$condominiumId}" . ($fractionId ? " - Fração ID {$fractionId}" : "") . " - Papel: {$role}"
                ]);
            } else {
                $_SESSION['error'] = 'Erro ao enviar convite.';
            }
        } catch (\Exception $e) {
            error_log("InvitationController exception: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao enviar convite: ' . $e->getMessage();
        }

        // Redirect back to fractions page if fraction_id is provided, otherwise to condominium page
        if ($fractionId) {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
        } else {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId);
        }
        exit;
    }

    public function accept()
    {
        $token = $_GET['token'] ?? '';

        if (empty($token)) {
            $_SESSION['error'] = 'Token de convite inválido.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $this->loadPageTranslations('invitations');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/invitations/accept.html.twig',
            'page' => ['titulo' => 'Aceitar Convite'],
            'token' => $token,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function processAccept()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'invitation/accept?token=' . ($_POST['token'] ?? ''));
            exit;
        }

        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (empty($token) || empty($password)) {
            $_SESSION['error'] = 'Por favor, preencha todos os campos.';
            header('Location: ' . BASE_URL . 'invitation/accept?token=' . $token);
            exit;
        }

        if (strlen($password) < 8) {
            $_SESSION['error'] = 'A senha deve ter pelo menos 8 caracteres.';
            header('Location: ' . BASE_URL . 'invitation/accept?token=' . $token);
            exit;
        }

        if ($password !== $passwordConfirm) {
            $_SESSION['error'] = 'As senhas não coincidem.';
            header('Location: ' . BASE_URL . 'invitation/accept?token=' . $token);
            exit;
        }

        $userId = $this->invitationService->acceptInvitation($token, $password);
        
        if ($userId) {
            // Auto login
            $userModel = new \App\Models\User();
            $user = $userModel->findById($userId);
            
            if ($user) {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'role' => $user['role']
                ];
                
                $_SESSION['success'] = 'Conta criada e convite aceite com sucesso!';
                header('Location: ' . BASE_URL . 'dashboard');
                exit;
            }
        }

        $_SESSION['error'] = 'Erro ao aceitar convite. Token inválido ou expirado.';
        header('Location: ' . BASE_URL . 'invitation/accept?token=' . $token);
        exit;
    }

    public function revoke(int $condominiumId, int $invitationId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $fractionId = !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null;

        // Get invitation details before revoking for audit log
        global $db;
        $invitationDetails = null;
        if ($db) {
            $stmt = $db->prepare("SELECT email, name FROM invitations WHERE id = :id AND condominium_id = :condominium_id LIMIT 1");
            $stmt->execute([':id' => $invitationId, ':condominium_id' => $condominiumId]);
            $invitationDetails = $stmt->fetch();
        }

        if ($this->invitationService->revokeInvitation($invitationId, $condominiumId)) {
            $_SESSION['success'] = 'Convite revogado com sucesso!';
            
            // Log audit: invitation revoked is an important administrative action
            $email = $invitationDetails['email'] ?? 'N/A';
            $name = $invitationDetails['name'] ?? 'N/A';
            $this->auditService->log([
                'action' => 'invitation_revoked',
                'model' => 'invitation',
                'model_id' => $invitationId,
                'description' => "Convite revogado para {$name} ({$email}) no condomínio ID {$condominiumId}"
            ]);
        } else {
            $_SESSION['error'] = 'Erro ao revogar convite. Convite não encontrado ou já foi aceite.';
        }

        // Redirect back to fractions page if fraction_id is provided, otherwise to condominium page
        if ($fractionId) {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
        } else {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId);
        }
        exit;
    }
}

