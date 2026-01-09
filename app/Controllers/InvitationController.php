<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Services\InvitationService;

class InvitationController extends Controller
{
    protected $invitationService;

    public function __construct()
    {
        parent::__construct();
        $this->invitationService = new InvitationService();
    }

    public function create(int $condominiumId, int $fractionId = null)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $this->loadPageTranslations('invitations');
        
        $this->data += [
            'viewName' => 'pages/invitations/create.html.twig',
            'page' => ['titulo' => 'Convidar Condómino'],
            'condominium_id' => $condominiumId,
            'fraction_id' => $fractionId,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

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

        $email = Security::sanitize($_POST['email'] ?? '');
        $name = Security::sanitize($_POST['name'] ?? '');
        $fractionId = !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null;
        $role = Security::sanitize($_POST['role'] ?? 'condomino');

        if (empty($email) || empty($name)) {
            $_SESSION['error'] = 'Por favor, preencha todos os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/invitations/create');
            exit;
        }

        if ($this->invitationService->sendInvitation($condominiumId, $fractionId, $email, $name, $role)) {
            $_SESSION['success'] = 'Convite enviado com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao enviar convite.';
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
        
        $this->data += [
            'viewName' => 'pages/invitations/accept.html.twig',
            'page' => ['titulo' => 'Aceitar Convite'],
            'token' => $token,
            'csrf_token' => Security::generateCSRFToken()
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
}

