<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Models\User;
use App\Core\EmailService;

class AuthController extends Controller
{
    protected $userModel;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
    }

    public function login()
    {
        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user'])) {
            $this->redirectToDashboard();
            exit;
        }

        $this->loadPageTranslations('login');
        
        $this->data += [
            'viewName' => 'pages/login.html.twig',
            'page' => [
                'titulo' => 'Login',
                'description' => 'Login to your account',
                'keywords' => 'login, authentication'
            ],
            'error' => $_SESSION['login_error'] ?? null,
            'success' => $_SESSION['login_success'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
        ];
        
        // Clear error messages after displaying
        unset($_SESSION['login_error']);
        unset($_SESSION['login_success']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function processLogin()
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['login_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $email = Security::sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $twoFactorCode = $_POST['two_factor_code'] ?? '';

        // Basic validation
        if (empty($email) || empty($password)) {
            $_SESSION['login_error'] = 'Por favor, preencha todos os campos.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        if (!Security::validateEmail($email)) {
            $_SESSION['login_error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Authenticate user
        $user = $this->userModel->findByEmail($email);
        
        if (!$user || !$this->userModel->verifyPassword($email, $password)) {
            $_SESSION['login_error'] = 'Email ou senha incorretos.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Check if user is active
        if ($user['status'] !== 'active') {
            $_SESSION['login_error'] = 'A sua conta está suspensa ou inativa.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Check 2FA if enabled
        if ($user['two_factor_enabled']) {
            if (empty($twoFactorCode)) {
                $_SESSION['login_error'] = 'Código de autenticação de dois fatores necessário.';
                $_SESSION['pending_2fa_user'] = $user['id'];
                header('Location: ' . BASE_URL . 'login/2fa');
                exit;
            }

            if (!Security::verifyTOTP($user['two_factor_secret'], $twoFactorCode)) {
                $_SESSION['login_error'] = 'Código de autenticação inválido.';
                header('Location: ' . BASE_URL . 'login');
                exit;
            }
        }

        // Set user session
        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role']
        ];

        // Update last login
        $this->userModel->updateLastLogin($user['id']);

        // Log audit
        $this->logAudit($user['id'], 'login', 'User logged in');

        $_SESSION['login_success'] = 'Login realizado com sucesso!';
        $this->redirectToDashboard();
    }

    public function register()
    {
        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user'])) {
            $this->redirectToDashboard();
            exit;
        }

        $this->loadPageTranslations('login');
        
        $this->data += [
            'viewName' => 'pages/register.html.twig',
            'page' => [
                'titulo' => 'Criar Conta - MeuPrédio',
                'description' => 'Crie a sua conta de administrador e comece a gerir o seu condomínio',
                'keywords' => 'registro, criar conta, gestão condomínios'
            ],
            'error' => $_SESSION['register_error'] ?? null,
            'success' => $_SESSION['register_success'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
        ];
        
        unset($_SESSION['register_error']);
        unset($_SESSION['register_success']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function processRegister()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['register_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        $email = Security::sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $name = Security::sanitize($_POST['name'] ?? '');
        $phone = Security::sanitize($_POST['phone'] ?? '');
        $nif = Security::sanitize($_POST['nif'] ?? '');
        $role = Security::sanitize($_POST['role'] ?? 'admin'); // Default to admin for registration
        $terms = isset($_POST['terms']) && $_POST['terms'] === 'on';

        // Validation
        if (empty($email) || empty($password) || empty($name)) {
            $_SESSION['register_error'] = 'Por favor, preencha todos os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        if (!Security::validateEmail($email)) {
            $_SESSION['register_error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        if (strlen($password) < 8) {
            $_SESSION['register_error'] = 'A senha deve ter pelo menos 8 caracteres.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        if ($password !== $passwordConfirm) {
            $_SESSION['register_error'] = 'As senhas não coincidem.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        if (!$terms) {
            $_SESSION['register_error'] = 'Deve aceitar os Termos e Condições para continuar.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        // Check if email already exists
        if ($this->userModel->findByEmail($email)) {
            $_SESSION['register_error'] = 'Este email já está registado.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        // Create user
        try {
            $userId = $this->userModel->create([
                'email' => $email,
                'password' => $password,
                'name' => $name,
                'role' => $role, // admin for new registrations
                'phone' => $phone ?: null,
                'nif' => $nif ?: null,
                'status' => 'active'
            ]);

            // Log audit
            $this->logAudit($userId, 'register', 'User registered');

            // Start trial subscription (default to START plan)
            $planModel = new \App\Models\Plan();
            $startPlan = $planModel->findBySlug('start');
            
            if ($startPlan) {
                $subscriptionService = new \App\Services\SubscriptionService();
                try {
                    $subscriptionService->startTrial($userId, $startPlan['id'], 14);
                } catch (\Exception $e) {
                    error_log("Trial start error: " . $e->getMessage());
                }
            }

            // Auto login after registration
            $user = $this->userModel->findById($userId);
            if ($user) {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'role' => $user['role']
                ];
                
                $_SESSION['register_success'] = 'Conta criada com sucesso! Período experimental de 14 dias iniciado.';
                header('Location: ' . BASE_URL . 'subscription/choose-plan');
                exit;
            }

            $_SESSION['register_success'] = 'Registo realizado com sucesso! Pode fazer login agora.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        } catch (\Exception $e) {
            $_SESSION['register_error'] = 'Erro ao criar conta: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'register');
            exit;
        }
    }

    public function forgotPassword()
    {
        $this->loadPageTranslations('login');
        
        $this->data += [
            'viewName' => 'pages/forgot-password.html.twig',
            'page' => [
                'titulo' => 'Recuperar Senha',
                'description' => 'Reset your password',
                'keywords' => 'password, reset'
            ],
            'error' => $_SESSION['forgot_error'] ?? null,
            'success' => $_SESSION['forgot_success'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
        ];
        
        unset($_SESSION['forgot_error']);
        unset($_SESSION['forgot_success']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function processForgotPassword()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'forgot-password');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['forgot_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'forgot-password');
            exit;
        }

        $email = Security::sanitize($_POST['email'] ?? '');

        if (empty($email) || !Security::validateEmail($email)) {
            $_SESSION['forgot_error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'forgot-password');
            exit;
        }

        $token = $this->userModel->createPasswordResetToken($email);
        
        if ($token) {
            // Send email with reset link
            $resetLink = BASE_URL . 'reset-password?token=' . $token;
            
            // TODO: Send email using EmailService
            // For now, just show success message
            
            $_SESSION['forgot_success'] = 'Se o email existir, receberá um link para redefinir a senha.';
        } else {
            // Don't reveal if email exists for security
            $_SESSION['forgot_success'] = 'Se o email existir, receberá um link para redefinir a senha.';
        }

        header('Location: ' . BASE_URL . 'forgot-password');
        exit;
    }

    public function resetPassword()
    {
        $token = $_GET['token'] ?? '';

        if (empty($token)) {
            $_SESSION['login_error'] = 'Token inválido.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $reset = $this->userModel->verifyPasswordResetToken($token);
        
        if (!$reset) {
            $_SESSION['login_error'] = 'Token inválido ou expirado.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $this->loadPageTranslations('login');
        
        $this->data += [
            'viewName' => 'pages/reset-password.html.twig',
            'page' => [
                'titulo' => 'Redefinir Senha',
                'description' => 'Reset your password'
            ],
            'error' => $_SESSION['reset_error'] ?? null,
            'token' => $token,
            'csrf_token' => Security::generateCSRFToken()
        ];
        
        unset($_SESSION['reset_error']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function processResetPassword()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['reset_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'reset-password?token=' . ($_POST['token'] ?? ''));
            exit;
        }

        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (empty($token) || empty($password)) {
            $_SESSION['reset_error'] = 'Por favor, preencha todos os campos.';
            header('Location: ' . BASE_URL . 'reset-password?token=' . $token);
            exit;
        }

        if (strlen($password) < 8) {
            $_SESSION['reset_error'] = 'A senha deve ter pelo menos 8 caracteres.';
            header('Location: ' . BASE_URL . 'reset-password?token=' . $token);
            exit;
        }

        if ($password !== $passwordConfirm) {
            $_SESSION['reset_error'] = 'As senhas não coincidem.';
            header('Location: ' . BASE_URL . 'reset-password?token=' . $token);
            exit;
        }

        if ($this->userModel->resetPassword($token, $password)) {
            $_SESSION['login_success'] = 'Senha redefinida com sucesso! Pode fazer login agora.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        } else {
            $_SESSION['reset_error'] = 'Erro ao redefinir senha. Token inválido ou expirado.';
            header('Location: ' . BASE_URL . 'reset-password?token=' . $token);
            exit;
        }
    }

    public function logout()
    {
        if (isset($_SESSION['user'])) {
            $userId = $_SESSION['user']['id'];
            $this->logAudit($userId, 'logout', 'User logged out');
        }

        session_unset();
        session_destroy();
        session_start();
        
        $_SESSION['login_success'] = 'Logout realizado com sucesso!';
        header('Location: ' . BASE_URL . 'login');
        exit;
    }

    protected function authenticate(string $email, string $password): bool
    {
        return $this->userModel->verifyPassword($email, $password);
    }

    protected function redirectToDashboard(): void
    {
        if (!isset($_SESSION['user'])) {
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $role = $_SESSION['user']['role'];
        
        switch ($role) {
            case 'super_admin':
                header('Location: ' . BASE_URL . 'admin');
                break;
            case 'admin':
                header('Location: ' . BASE_URL . 'dashboard');
                break;
            default:
                header('Location: ' . BASE_URL . 'dashboard');
        }
        exit;
    }

    protected function logAudit(int $userId, string $action, string $description): void
    {
        global $db;
        if ($db) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent)
                    VALUES (:user_id, :action, :description, :ip_address, :user_agent)
                ");
                
                $stmt->execute([
                    ':user_id' => $userId,
                    ':action' => $action,
                    ':description' => $description,
                    ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (\Exception $e) {
                // Log error but don't break the flow
                error_log("Audit log error: " . $e->getMessage());
            }
        }
    }
}

