<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Models\User;
use App\Core\EmailService;
use App\Services\GoogleOAuthService;
use App\Services\SecurityLogger;
use App\Middleware\RateLimitMiddleware;

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

    public function demoAccess()
    {
        // Auto-login demo user
        $demoUser = $this->userModel->findByEmail('demo@predio.pt');
        
        if (!$demoUser) {
            $_SESSION['login_error'] = 'Conta demo não encontrada. Contacte o administrador.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Check if user is active
        if ($demoUser['status'] !== 'active') {
            $_SESSION['login_error'] = 'Conta demo não está ativa.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Set user session
        $_SESSION['user'] = [
            'id' => $demoUser['id'],
            'email' => $demoUser['email'],
            'name' => $demoUser['name'],
            'role' => $demoUser['role']
        ];
        
        // Set demo profile to admin by default
        $_SESSION['demo_profile'] = 'admin';

        // Update last login
        $this->userModel->updateLastLogin($demoUser['id']);

        // Log audit
        $this->logAudit($demoUser['id'], 'login', 'Demo access - auto login');

        // Redirect to dashboard
        $_SESSION['login_success'] = 'Bem-vindo à demo! Explore todas as funcionalidades. Todas as alterações serão repostas automaticamente.';
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }

    public function processLogin()
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Check rate limit BEFORE processing login attempt
        try {
            RateLimitMiddleware::require('login');
        } catch (\Exception $e) {
            $_SESSION['login_error'] = $e->getMessage();
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            RateLimitMiddleware::recordAttempt('login');
            $_SESSION['login_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $email = Security::sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $twoFactorCode = $_POST['two_factor_code'] ?? '';

        // Basic validation
        if (empty($email) || empty($password)) {
            RateLimitMiddleware::recordAttempt('login');
            $_SESSION['login_error'] = 'Por favor, preencha todos os campos.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        if (!Security::validateEmail($email)) {
            RateLimitMiddleware::recordAttempt('login');
            $_SESSION['login_error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Authenticate user
        $user = $this->userModel->findByEmail($email);
        
        if (!$user || !$this->userModel->verifyPassword($email, $password)) {
            RateLimitMiddleware::recordAttempt('login');
            // Log failed login attempt
            $securityLogger = new SecurityLogger();
            $securityLogger->logFailedLogin($email, 'invalid_credentials');
            $_SESSION['login_error'] = 'Email ou senha incorretos.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Check if user is active
        if ($user['status'] !== 'active') {
            RateLimitMiddleware::recordAttempt('login');
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
                RateLimitMiddleware::recordAttempt('login');
                $_SESSION['login_error'] = 'Código de autenticação inválido.';
                header('Location: ' . BASE_URL . 'login');
                exit;
            }
        }

        // Regenerate session ID on successful login to prevent session fixation
        session_regenerate_id(true);
        
        // Set user session
        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role']
        ];
        
        // Set session creation time for periodic regeneration
        $_SESSION['created'] = time();

        // Reset rate limit on successful login
        RateLimitMiddleware::reset('login', $email);

        // Update last login
        $this->userModel->updateLastLogin($user['id']);

        // Log successful login
        $securityLogger = new SecurityLogger();
        $securityLogger->logSuccessfulLogin($user['id'], $email);

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
                'description' => 'Crie a sua conta e comece a utilizar o MeuPrédio',
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

        // Check rate limit BEFORE processing registration
        try {
            RateLimitMiddleware::require('register');
        } catch (\Exception $e) {
            $_SESSION['register_error'] = $e->getMessage();
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            RateLimitMiddleware::recordAttempt('register');
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
        $accountType = Security::sanitize($_POST['account_type'] ?? '');
        $terms = isset($_POST['terms']) && $_POST['terms'] === 'on';

        // Validation
        if (empty($email) || empty($password) || empty($name)) {
            RateLimitMiddleware::recordAttempt('register');
            $_SESSION['register_error'] = 'Por favor, preencha todos os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        if (empty($accountType) || !in_array($accountType, ['user', 'admin'])) {
            RateLimitMiddleware::recordAttempt('register');
            $_SESSION['register_error'] = 'Por favor, selecione o tipo de conta.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        if (!Security::validateEmail($email)) {
            RateLimitMiddleware::recordAttempt('register');
            $_SESSION['register_error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        // Validate password strength
        $passwordValidation = Security::validatePasswordStrength($password, $email, $name);
        if (!$passwordValidation['valid']) {
            RateLimitMiddleware::recordAttempt('register');
            $_SESSION['register_error'] = implode(' ', $passwordValidation['errors']);
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        if ($password !== $passwordConfirm) {
            RateLimitMiddleware::recordAttempt('register');
            $_SESSION['register_error'] = 'As senhas não coincidem.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        if (!$terms) {
            RateLimitMiddleware::recordAttempt('register');
            $_SESSION['register_error'] = 'Deve aceitar os Termos e Condições para continuar.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        // Check if email already exists
        if ($this->userModel->findByEmail($email)) {
            RateLimitMiddleware::recordAttempt('register');
            $_SESSION['register_error'] = 'Este email já está registado.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        // If admin, store registration data in session and redirect to plan selection
        if ($accountType === 'admin') {
            $_SESSION['pending_registration'] = [
                'email' => $email,
                'password' => $password,
                'name' => $name,
                'phone' => $phone,
                'nif' => $nif,
                'role' => 'admin',
                'account_type' => 'admin'
            ];
            header('Location: ' . BASE_URL . 'auth/select-plan');
            exit;
        }

        // If user (condomino), create account directly without subscription
        try {
            $userId = $this->userModel->create([
                'email' => $email,
                'password' => $password,
                'name' => $name,
                'role' => 'condomino',
                'phone' => $phone ?: null,
                'nif' => $nif ?: null,
                'status' => 'active'
            ]);

            // Log audit
            $this->logAudit($userId, 'register', 'User registered');

            // Auto login after registration
            $user = $this->userModel->findById($userId);
            if ($user) {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'role' => $user['role']
                ];
                
                $_SESSION['register_success'] = 'Conta criada com sucesso!';
                $this->redirectToDashboard();
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

        // Check rate limit BEFORE processing password reset request
        try {
            RateLimitMiddleware::require('forgot_password');
        } catch (\Exception $e) {
            $_SESSION['forgot_error'] = $e->getMessage();
            header('Location: ' . BASE_URL . 'forgot-password');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            RateLimitMiddleware::recordAttempt('forgot_password');
            $_SESSION['forgot_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'forgot-password');
            exit;
        }

        $email = Security::sanitize($_POST['email'] ?? '');

        if (empty($email) || !Security::validateEmail($email)) {
            RateLimitMiddleware::recordAttempt('forgot_password');
            $_SESSION['forgot_error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'forgot-password');
            exit;
        }

        $token = $this->userModel->createPasswordResetToken($email);
        
        // Log password reset request
        $securityLogger = new SecurityLogger();
        $securityLogger->logPasswordResetRequest($email);
        
        if ($token) {
            // Get user info to send personalized email
            $user = $this->userModel->findByEmail($email);
            if ($user) {
                $emailService = new EmailService();
                $nome = $user['name'] ?? 'Utilizador';
                
                // Send email with reset link
                $emailSent = $emailService->sendPasswordResetEmail($email, $nome, $token);
                
                if (!$emailSent) {
                    error_log("Failed to send password reset email to: " . $email);
                }
            }
            
            // Reset rate limit on successful request (even if email doesn't exist, to prevent enumeration)
            RateLimitMiddleware::reset('forgot_password', $email);
            
            // Always show success message (don't reveal if email exists for security)
            $_SESSION['forgot_success'] = 'Se o email existir, receberá um link para redefinir a senha.';
        } else {
            // Reset rate limit on successful request (even if email doesn't exist, to prevent enumeration)
            RateLimitMiddleware::reset('forgot_password', $email);
            
            // Don't reveal if email exists for security
            $_SESSION['forgot_success'] = 'Se o email existir, receberá um link para redefinir a senha.';
        }

        header('Location: ' . BASE_URL . 'forgot-password');
        exit;
    }

    public function resetPassword()
    {
        // Security: Accept token from GET only for initial verification, then store in session
        $token = $_GET['token'] ?? '';

        if (empty($token)) {
            // Check if token is already in session (from previous verification)
            $token = $_SESSION['password_reset_token'] ?? '';
            
            if (empty($token)) {
                $_SESSION['login_error'] = 'Token inválido ou link expirado.';
                header('Location: ' . BASE_URL . 'login');
                exit;
            }
        } else {
            // Verify token and store in session for security (remove from URL)
            $reset = $this->userModel->verifyPasswordResetToken($token);
            
            if (!$reset) {
                $_SESSION['login_error'] = 'Token inválido ou expirado.';
                header('Location: ' . BASE_URL . 'login');
                exit;
            }
            
            // Store verified token in session (expires in 10 minutes for security)
            $_SESSION['password_reset_token'] = $token;
            $_SESSION['password_reset_token_time'] = time();
        }

        // Verify token from session is still valid
        $reset = $this->userModel->verifyPasswordResetToken($token);
        if (!$reset) {
            unset($_SESSION['password_reset_token'], $_SESSION['password_reset_token_time']);
            $_SESSION['login_error'] = 'Token inválido ou expirado.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Check if session token is too old (10 minutes max)
        if (isset($_SESSION['password_reset_token_time']) && (time() - $_SESSION['password_reset_token_time']) > 600) {
            unset($_SESSION['password_reset_token'], $_SESSION['password_reset_token_time']);
            $_SESSION['login_error'] = 'Sessão expirada. Por favor, solicite um novo link de redefinição.';
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
            header('Location: ' . BASE_URL . 'reset-password');
            exit;
        }

        // Security: Get token from session, not POST/GET
        $token = $_SESSION['password_reset_token'] ?? '';
        
        if (empty($token)) {
            $_SESSION['reset_error'] = 'Token inválido ou sessão expirada. Por favor, solicite um novo link.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Check if session token is too old (10 minutes max)
        if (isset($_SESSION['password_reset_token_time']) && (time() - $_SESSION['password_reset_token_time']) > 600) {
            unset($_SESSION['password_reset_token'], $_SESSION['password_reset_token_time']);
            $_SESSION['reset_error'] = 'Sessão expirada. Por favor, solicite um novo link de redefinição.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (empty($password)) {
            $_SESSION['reset_error'] = 'Por favor, preencha todos os campos.';
            header('Location: ' . BASE_URL . 'reset-password');
            exit;
        }

        // Validate password strength
        $passwordValidation = Security::validatePasswordStrength($password, $userEmail ?? null, $userName ?? null);
        if (!$passwordValidation['valid']) {
            $_SESSION['reset_error'] = implode(' ', $passwordValidation['errors']);
            header('Location: ' . BASE_URL . 'reset-password');
            exit;
        }

        if ($password !== $passwordConfirm) {
            $_SESSION['reset_error'] = 'As senhas não coincidem.';
            header('Location: ' . BASE_URL . 'reset-password');
            exit;
        }

        // Get reset info before resetting password (token will be marked as used)
        $resetInfo = $this->userModel->verifyPasswordResetToken($token);
        $userEmail = null;
        $userName = null;
        
        if ($resetInfo) {
            $user = $this->userModel->findByEmail($resetInfo['email']);
            if ($user) {
                $userEmail = $resetInfo['email'];
                $userName = $user['name'] ?? 'Utilizador';
            }
        }
        
        if ($this->userModel->resetPassword($token, $password)) {
            // Clear token from session after successful reset
            unset($_SESSION['password_reset_token'], $_SESSION['password_reset_token_time']);
            
            // Log successful password reset
            if ($userEmail) {
                $securityLogger = new SecurityLogger();
                $securityLogger->logPasswordResetSuccess($userEmail);
            }
            
            // Send success email if we have user info
            if ($userEmail && $userName) {
                $emailService = new EmailService();
                $emailSent = $emailService->sendPasswordResetSuccessEmail($userEmail, $userName);
                
                if (!$emailSent) {
                    error_log("Failed to send password reset success email to: " . $userEmail);
                }
            }
            
            $_SESSION['login_success'] = 'Senha redefinida com sucesso! Pode fazer login agora.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        } else {
            // Clear invalid token from session
            unset($_SESSION['password_reset_token'], $_SESSION['password_reset_token_time']);
            $_SESSION['reset_error'] = 'Erro ao redefinir senha. Token inválido ou expirado.';
            header('Location: ' . BASE_URL . 'login');
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
        header('Location: ' . BASE_URL);
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

        $userId = $_SESSION['user']['id'];
        $role = $_SESSION['user']['role'];
        
        // Get user's condominiums
        $userCondominiums = [];
        if ($role === 'super_admin') {
            // For superadmin, get all condominiums where user is admin or condomino
            $condominiumUserModel = new \App\Models\CondominiumUser();
            $condominiumsByRole = $condominiumUserModel->getUserCondominiumsWithRoles($userId);
            // Combine admin and condomino condominiums
            $userCondominiums = array_merge(
                $condominiumsByRole['admin'] ?? [],
                $condominiumsByRole['condomino'] ?? []
            );
        } elseif ($role === 'admin') {
            $condominiumModel = new \App\Models\Condominium();
            $userCondominiums = $condominiumModel->getByUserId($userId);
        } else {
            $condominiumUserModel = new \App\Models\CondominiumUser();
            $userCondominiumsList = $condominiumUserModel->getUserCondominiums($userId);
            $condominiumModel = new \App\Models\Condominium();
            foreach ($userCondominiumsList as $uc) {
                $condo = $condominiumModel->findById($uc['condominium_id']);
                if ($condo && !in_array($condo['id'], array_column($userCondominiums, 'id'))) {
                    $userCondominiums[] = $condo;
                }
            }
        }
        
        // If user has only one condominium, set it as default automatically
        if (count($userCondominiums) === 1) {
            $userModel = new \App\Models\User();
            $userModel->setDefaultCondominium($userId, $userCondominiums[0]['id']);
            $_SESSION['current_condominium_id'] = $userCondominiums[0]['id'];
            header('Location: ' . BASE_URL . 'condominiums/' . $userCondominiums[0]['id']);
            exit;
        }
        
        // Get user's default condominium
        $userModel = new \App\Models\User();
        $defaultCondominiumId = $userModel->getDefaultCondominiumId($userId);
        
        // If user has a default condominium, redirect there
        if ($defaultCondominiumId) {
            // Verify user still has access
            if ($role === 'admin' || $role === 'super_admin') {
                global $db;
                $stmt = $db->prepare("SELECT id FROM condominiums WHERE id = :condominium_id AND user_id = :user_id");
                $stmt->execute([
                    ':condominium_id' => $defaultCondominiumId,
                    ':user_id' => $userId
                ]);
                if ($stmt->fetch()) {
                    $_SESSION['current_condominium_id'] = $defaultCondominiumId;
                    header('Location: ' . BASE_URL . 'condominiums/' . $defaultCondominiumId);
                    exit;
                }
            } else {
                global $db;
                $stmt = $db->prepare("
                    SELECT id FROM condominium_users 
                    WHERE user_id = :user_id 
                    AND condominium_id = :condominium_id
                    AND (ended_at IS NULL OR ended_at > CURDATE())
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':condominium_id' => $defaultCondominiumId
                ]);
                if ($stmt->fetch()) {
                    $_SESSION['current_condominium_id'] = $defaultCondominiumId;
                    header('Location: ' . BASE_URL . 'condominiums/' . $defaultCondominiumId);
                    exit;
                }
            }
        }
        
        // Fallback to dashboard
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

    /**
     * Initiate Google OAuth authentication
     */
    public function googleAuth()
    {
        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user'])) {
            $this->redirectToDashboard();
            exit;
        }

        try {
            $oauthService = new GoogleOAuthService();
            $authUrl = $oauthService->getAuthUrl();
            
            // Store source (login or register) in session for redirect after callback
            $source = $_GET['source'] ?? 'login';
            $_SESSION['google_oauth_source'] = $source;
            
            header('Location: ' . $authUrl);
            exit;
        } catch (\Exception $e) {
            $_SESSION['login_error'] = 'Erro ao iniciar autenticação Google: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'login');
            exit;
        }
    }

    /**
     * Handle Google OAuth callback
     */
    public function googleCallback()
    {
        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user'])) {
            $this->redirectToDashboard();
            exit;
        }

        $code = $_GET['code'] ?? '';
        $error = $_GET['error'] ?? '';

        if (!empty($error)) {
            $_SESSION['login_error'] = 'Autenticação Google cancelada ou falhou.';
            $source = $_SESSION['google_oauth_source'] ?? 'login';
            unset($_SESSION['google_oauth_source']);
            header('Location: ' . BASE_URL . ($source === 'register' ? 'register' : 'login'));
            exit;
        }

        if (empty($code)) {
            $_SESSION['login_error'] = 'Código de autorização não recebido.';
            $source = $_SESSION['google_oauth_source'] ?? 'login';
            unset($_SESSION['google_oauth_source']);
            header('Location: ' . BASE_URL . ($source === 'register' ? 'register' : 'login'));
            exit;
        }

        try {
            $oauthService = new GoogleOAuthService();
            $userInfo = $oauthService->handleCallback($code);

            $googleId = $userInfo['google_id'];
            $email = $userInfo['email'];
            $name = $userInfo['name'];
            $source = $_SESSION['google_oauth_source'] ?? 'login';
            unset($_SESSION['google_oauth_source']);

            // Check if user exists by Google ID
            $user = $this->userModel->findByGoogleId($googleId);

            // If not found by Google ID, check by email
            if (!$user) {
                $user = $this->userModel->findByEmail($email);
                
                if ($user) {
                    // User exists with this email but not linked to Google
                    // Link the Google account
                    if ($user['auth_provider'] === 'local') {
                        // Link Google account to existing local account
                        $this->userModel->linkGoogleAccount($user['id'], $googleId);
                        $user = $this->userModel->findById($user['id']); // Refresh user data
                    } else {
                        // Email exists but with different provider - show error
                        $_SESSION['login_error'] = 'Este email já está registado com outro método de autenticação.';
                        header('Location: ' . BASE_URL . ($source === 'register' ? 'register' : 'login'));
                        exit;
                    }
                }
            }

            // If user found, log them in
            if ($user) {
                // Check if user is active
                if ($user['status'] !== 'active') {
                    $_SESSION['login_error'] = 'A sua conta está suspensa ou inativa.';
                    header('Location: ' . BASE_URL . 'login');
                    exit;
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
                $this->logAudit($user['id'], 'login', 'User logged in via Google OAuth');

                $_SESSION['login_success'] = 'Login realizado com sucesso via Google!';
                $this->redirectToDashboard();
                exit;
            }

            // User doesn't exist - ask for account type
            if ($source === 'register') {
                // Store Google OAuth data in session for account creation
                $_SESSION['google_oauth_pending'] = [
                    'email' => $email,
                    'name' => $name,
                    'google_id' => $googleId,
                    'auth_provider' => 'google'
                ];
                // Redirect to account type selection
                header('Location: ' . BASE_URL . 'auth/select-account-type');
                exit;
            } else {
                // Trying to login but account doesn't exist
                $_SESSION['login_error'] = 'Conta não encontrada. Por favor, registe-se primeiro.';
                header('Location: ' . BASE_URL . 'register');
                exit;
            }
        } catch (\Exception $e) {
            $_SESSION['login_error'] = 'Erro ao processar autenticação Google: ' . $e->getMessage();
            $source = $_SESSION['google_oauth_source'] ?? 'login';
            unset($_SESSION['google_oauth_source']);
            header('Location: ' . BASE_URL . ($source === 'register' ? 'register' : 'login'));
            exit;
        }
    }

    /**
     * Show account type selection page (for Google OAuth)
     */
    public function selectAccountType()
    {
        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user'])) {
            $this->redirectToDashboard();
            exit;
        }

        // Check if we have pending Google OAuth data
        if (!isset($_SESSION['google_oauth_pending'])) {
            $_SESSION['register_error'] = 'Dados de autenticação não encontrados. Por favor, tente novamente.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        $this->loadPageTranslations('login');
        
        $this->data += [
            'viewName' => 'pages/auth/select-account-type.html.twig',
            'page' => [
                'titulo' => 'Selecionar Tipo de Conta',
                'description' => 'Escolha o tipo de conta que deseja criar'
            ],
            'error' => $_SESSION['register_error'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
        ];
        
        unset($_SESSION['register_error']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Process account type selection
     */
    public function processAccountType()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'auth/select-account-type');
            exit;
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['register_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'auth/select-account-type');
            exit;
        }

        // Check if we have pending Google OAuth data
        if (!isset($_SESSION['google_oauth_pending'])) {
            $_SESSION['register_error'] = 'Dados de autenticação não encontrados. Por favor, tente novamente.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        $accountType = Security::sanitize($_POST['account_type'] ?? '');

        if (empty($accountType) || !in_array($accountType, ['user', 'admin'])) {
            $_SESSION['register_error'] = 'Por favor, selecione o tipo de conta.';
            header('Location: ' . BASE_URL . 'auth/select-account-type');
            exit;
        }

        $googleData = $_SESSION['google_oauth_pending'];

        // If user (condomino), create account directly
        if ($accountType === 'user') {
            try {
                $userId = $this->userModel->create([
                    'email' => $googleData['email'],
                    'name' => $googleData['name'],
                    'role' => 'condomino',
                    'status' => 'active',
                    'google_id' => $googleData['google_id'],
                    'auth_provider' => $googleData['auth_provider']
                ]);

                // Log audit
                $this->logAudit($userId, 'register', 'User registered via Google OAuth');

                // Auto login
                $user = $this->userModel->findById($userId);
                if ($user) {
                    unset($_SESSION['google_oauth_pending']);
                    $_SESSION['user'] = [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'name' => $user['name'],
                        'role' => $user['role']
                    ];
                    
                    $_SESSION['register_success'] = 'Conta criada com sucesso via Google!';
                    $this->redirectToDashboard();
                    exit;
                }
            } catch (\Exception $e) {
                $_SESSION['register_error'] = 'Erro ao criar conta: ' . $e->getMessage();
                header('Location: ' . BASE_URL . 'auth/select-account-type');
                exit;
            }
        }

        // If admin, store account type and redirect to plan selection
        $_SESSION['google_oauth_pending']['account_type'] = 'admin';
        $_SESSION['google_oauth_pending']['role'] = 'admin';
        header('Location: ' . BASE_URL . 'auth/select-plan');
        exit;
    }

    /**
     * Show plan selection page (for admin registration)
     */
    public function selectPlanForAdmin()
    {
        // Check if we have pending registration data (from normal register or Google OAuth)
        $hasPendingRegistration = isset($_SESSION['pending_registration']);
        $hasGoogleOAuth = isset($_SESSION['google_oauth_pending']) && 
                         isset($_SESSION['google_oauth_pending']['account_type']) && 
                         $_SESSION['google_oauth_pending']['account_type'] === 'admin';

        if (!$hasPendingRegistration && !$hasGoogleOAuth) {
            $_SESSION['register_error'] = 'Dados de registo não encontrados. Por favor, tente novamente.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        // Get plans
        $planModel = new \App\Models\Plan();
        $plans = $planModel->getActivePlans();

        // Convert features to readable format
        $featureLabels = [
            'financas_basicas' => 'Finanças Básicas',
            'financas_completas' => 'Finanças Completas',
            'documentos' => 'Gestão de Documentos',
            'ocorrencias_simples' => 'Ocorrências Simples',
            'ocorrencias' => 'Ocorrências',
            'votacoes_online' => 'Votações Online',
            'reservas_espacos' => 'Reservas de Espaços',
            'gestao_contratos' => 'Gestão de Contratos',
            'gestao_fornecedores' => 'Gestão de Fornecedores'
        ];
        
        foreach ($plans as &$plan) {
            $featuresArray = [];
            if (isset($plan['features']) && is_string($plan['features'])) {
                $featuresJson = json_decode($plan['features'], true) ?: [];
                foreach ($featuresJson as $key => $value) {
                    if ($value === true && isset($featureLabels[$key])) {
                        $featuresArray[] = $featureLabels[$key];
                    }
                }
            }
            $plan['features'] = $featuresArray;
        }
        unset($plan);

        // Get visible promotions for each plan
        $promotionModel = new \App\Models\Promotion();
        $planPromotions = [];
        
        foreach ($plans as $plan) {
            $visiblePromotion = $promotionModel->getVisibleForPlan($plan['id']);
            if ($visiblePromotion) {
                $planPromotions[$plan['id']] = $visiblePromotion;
            }
        }

        $this->loadPageTranslations('subscription');
        
        $this->data += [
            'viewName' => 'pages/auth/select-plan.html.twig',
            'page' => [
                'titulo' => 'Escolher Plano de Subscrição',
                'description' => 'Escolha o plano ideal para o seu condomínio'
            ],
            'plans' => $plans,
            'plan_promotions' => $planPromotions,
            'error' => $_SESSION['register_error'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
        ];
        
        unset($_SESSION['register_error']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Process plan selection and create admin account
     */
    public function processPlanSelection()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'auth/select-plan');
            exit;
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['register_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'auth/select-plan');
            exit;
        }

        $planId = (int)($_POST['plan_id'] ?? 0);
        $planModel = new \App\Models\Plan();
        $plan = $planModel->findById($planId);

        if (!$plan) {
            $_SESSION['register_error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'auth/select-plan');
            exit;
        }

        // Check if we have pending registration data
        $pendingData = null;
        $isGoogleOAuth = false;

        if (isset($_SESSION['google_oauth_pending']) && 
            isset($_SESSION['google_oauth_pending']['account_type']) && 
            $_SESSION['google_oauth_pending']['account_type'] === 'admin') {
            $pendingData = $_SESSION['google_oauth_pending'];
            $isGoogleOAuth = true;
        } elseif (isset($_SESSION['pending_registration'])) {
            $pendingData = $_SESSION['pending_registration'];
        }

        if (!$pendingData) {
            $_SESSION['register_error'] = 'Dados de registo não encontrados. Por favor, tente novamente.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        // Check if email already exists (in case user refreshed page)
        if ($this->userModel->findByEmail($pendingData['email'])) {
            $_SESSION['register_error'] = 'Este email já está registado.';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        try {
            // Create user account
            $userData = [
                'email' => $pendingData['email'],
                'name' => $pendingData['name'],
                'role' => 'admin',
                'status' => 'active'
            ];

            if ($isGoogleOAuth) {
                $userData['google_id'] = $pendingData['google_id'];
                $userData['auth_provider'] = $pendingData['auth_provider'];
            } else {
                $userData['password'] = $pendingData['password'];
                if (isset($pendingData['phone'])) {
                    $userData['phone'] = $pendingData['phone'];
                }
                if (isset($pendingData['nif'])) {
                    $userData['nif'] = $pendingData['nif'];
                }
            }

            $userId = $this->userModel->create($userData);

            // Log audit
            $this->logAudit($userId, 'register', 'Admin registered' . ($isGoogleOAuth ? ' via Google OAuth' : ''));

            // Start trial subscription
            $subscriptionService = new \App\Services\SubscriptionService();
            try {
                $subscriptionService->startTrial($userId, $planId, 14);
            } catch (\Exception $e) {
                error_log("Trial start error: " . $e->getMessage());
            }

            // Clear pending data
            unset($_SESSION['pending_registration']);
            unset($_SESSION['google_oauth_pending']);

            // Auto login
            $user = $this->userModel->findById($userId);
            if ($user) {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'role' => $user['role']
                ];
                
                $_SESSION['register_success'] = 'Conta criada com sucesso! Período experimental de 14 dias iniciado.';
                header('Location: ' . BASE_URL . 'subscription');
                exit;
            }

            $_SESSION['register_success'] = 'Registo realizado com sucesso! Pode fazer login agora.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        } catch (\Exception $e) {
            $_SESSION['register_error'] = 'Erro ao criar conta: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'auth/select-plan');
            exit;
        }
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

