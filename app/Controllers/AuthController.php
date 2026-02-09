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
        
        $isRegistrationDisabled = defined('DISABLE_REGISTRATION') && DISABLE_REGISTRATION;
        
        $this->data += [
            'viewName' => 'pages/login.html.twig',
            'page' => [
                'titulo' => 'Login',
                'description' => 'Login to your account',
                'keywords' => 'login, authentication'
            ],
            'error' => $_SESSION['login_error'] ?? null,
            'success' => $_SESSION['login_success'] ?? null,
            'pilot_signup_error' => $_SESSION['pilot_signup_error'] ?? null,
            'pilot_signup_success' => $_SESSION['pilot_signup_success'] ?? null,
            'csrf_token' => Security::generateCSRFToken(),
            'is_registration_disabled' => $isRegistrationDisabled
        ];
        
        // Clear error messages after displaying
        unset($_SESSION['login_error']);
        unset($_SESSION['login_success']);
        unset($_SESSION['pilot_signup_error']);
        unset($_SESSION['pilot_signup_success']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Direct access login page (secret route - login is always allowed)
     * This route is not publicized and should not have visible links
     */
    public function directAccessLogin()
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
            'csrf_token' => Security::generateCSRFToken(),
            'direct_access' => true, // Flag to indicate this is direct access route
            'form_action' => BASE_URL . 'access-admin-panel/process' // Use direct access route for form
        ];
        
        // Clear error messages after displaying
        unset($_SESSION['login_error']);
        unset($_SESSION['login_success']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Process direct access login (secret route - login is always allowed)
     */
    public function processDirectAccessLogin()
    {
        // NOTE: Login is always allowed - no checks needed

        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'access-admin-panel');
            exit;
        }

        // Check rate limit BEFORE processing login attempt
        try {
            RateLimitMiddleware::require('login');
        } catch (\Exception $e) {
            $_SESSION['login_error'] = $e->getMessage();
            header('Location: ' . BASE_URL . 'access-admin-panel');
            exit;
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            RateLimitMiddleware::recordAttempt('login');
            $_SESSION['login_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'access-admin-panel');
            exit;
        }

        $email = Security::sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $twoFactorCode = $_POST['two_factor_code'] ?? '';

        // Basic validation
        if (empty($email) || empty($password)) {
            RateLimitMiddleware::recordAttempt('login');
            $_SESSION['login_error'] = 'Por favor, preencha todos os campos.';
            header('Location: ' . BASE_URL . 'access-admin-panel');
            exit;
        }

        if (!Security::validateEmail($email)) {
            RateLimitMiddleware::recordAttempt('login');
            $_SESSION['login_error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'access-admin-panel');
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
            header('Location: ' . BASE_URL . 'access-admin-panel');
            exit;
        }

        // Check if user is active
        if ($user['status'] !== 'active') {
            RateLimitMiddleware::recordAttempt('login');
            $_SESSION['login_error'] = 'A sua conta está suspensa ou inativa.';
            header('Location: ' . BASE_URL . 'access-admin-panel');
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
                header('Location: ' . BASE_URL . 'access-admin-panel');
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
        $_SESSION['last_activity'] = time();

        // Reset rate limit on successful login
        RateLimitMiddleware::reset('login', $email);

        // Update last login
        $this->userModel->updateLastLogin($user['id']);

        // Log successful login
        $securityLogger = new SecurityLogger();
        $securityLogger->logSuccessfulLogin($user['id'], $email);

        // Log audit
        $this->logAudit($user['id'], 'login', 'User logged in via direct access route');

        $_SESSION['login_success'] = 'Login realizado com sucesso!';
        $this->redirectToDashboard();
    }

    /**
     * Show demo access request form
     */
    public function demoAccess()
    {
        // If already authenticated, redirect to dashboard
        if (isset($_SESSION['user'])) {
            $this->redirectToDashboard();
            exit;
        }

        // Show access request form
        $this->loadPageTranslations('demo');
        
        $this->data += [
            'viewName' => 'pages/demo/request-access.html.twig',
            'page' => [
                'titulo' => 'Acesso à Demonstração',
                'description' => 'Solicite acesso à demonstração',
            ],
            'error' => $_SESSION['demo_error'] ?? null,
            'success' => $_SESSION['demo_success'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
        ];
        
        unset($_SESSION['demo_error'], $_SESSION['demo_success']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Process demo access request
     */
    public function processDemoAccessRequest()
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'demo/access');
            exit;
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['demo_error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'demo/access');
            exit;
        }

        $email = Security::sanitize($_POST['email'] ?? '');
        $wantsNewsletter = isset($_POST['wants_newsletter']) && $_POST['wants_newsletter'] === 'on';

        // Validate email first
        if (empty($email)) {
            $_SESSION['demo_error'] = 'Por favor, introduza o seu email.';
            header('Location: ' . BASE_URL . 'demo/access');
            exit;
        }

        if (!Security::validateEmail($email)) {
            $_SESSION['demo_error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'demo/access');
            exit;
        }

        // Check rate limit by email (max 3 per hour)
        try {
            RateLimitMiddleware::require('demo_access', $email);
        } catch (\Exception $e) {
            $_SESSION['demo_error'] = 'Muitas tentativas para este email. Por favor, aguarde antes de tentar novamente.';
            header('Location: ' . BASE_URL . 'demo/access');
            exit;
        }

        // Check rate limit by IP (max 10 per hour)
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ipAddress) {
            try {
                RateLimitMiddleware::require('demo_access', $ipAddress);
            } catch (\Exception $e) {
                RateLimitMiddleware::recordAttempt('demo_access', $email);
                $_SESSION['demo_error'] = 'Muitas tentativas deste endereço IP. Por favor, aguarde antes de tentar novamente.';
                header('Location: ' . BASE_URL . 'demo/access');
                exit;
            }
        }

        // Create token
        try {
            $tokenModel = new \App\Models\DemoAccessToken();
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $token = $tokenModel->createToken($email, $wantsNewsletter, $ipAddress, $userAgent);

            // Subscribe to newsletter if requested
            if ($wantsNewsletter) {
                $newsletterService = new \App\Services\NewsletterService();
                $newsletterService->subscribe($email, 'demo_access');
            }

            // Send email with access link
            $emailService = new EmailService();
            $accessUrl = BASE_URL . 'demo/access/token?token=' . urlencode($token);
            $emailSent = $emailService->sendDemoAccessEmail($email, $token);

            if (!$emailSent) {
                error_log("Failed to send demo access email to: " . $email);
                // Still show success message to user (don't reveal if email failed)
            }

            // Reset rate limit on success (both email and IP)
            RateLimitMiddleware::reset('demo_access', $email);
            if ($ipAddress) {
                RateLimitMiddleware::reset('demo_access', $ipAddress);
            }

            $_SESSION['demo_success'] = 'Link de acesso enviado para o seu email! Verifique a sua caixa de entrada (e spam).';
        } catch (\Exception $e) {
            // Record attempt for both email and IP
            RateLimitMiddleware::recordAttempt('demo_access', $email);
            if ($ipAddress) {
                RateLimitMiddleware::recordAttempt('demo_access', $ipAddress);
            }
            error_log("Error creating demo access token: " . $e->getMessage());
            $_SESSION['demo_error'] = 'Erro ao processar pedido. Por favor, tente novamente.';
        }

        header('Location: ' . BASE_URL . 'demo/access');
        exit;
    }

    /**
     * Access demo with token
     */
    public function demoAccessWithToken()
    {
        $token = $_GET['token'] ?? '';

        if (empty($token)) {
            $_SESSION['demo_error'] = 'Token de acesso inválido ou ausente.';
            header('Location: ' . BASE_URL . 'demo/access');
            exit;
        }

        // Find token
        $tokenModel = new \App\Models\DemoAccessToken();
        $tokenData = $tokenModel->findByToken($token);

        if (!$tokenData) {
            $_SESSION['demo_error'] = 'Token de acesso inválido ou expirado. Por favor, solicite um novo acesso.';
            header('Location: ' . BASE_URL . 'demo/access');
            exit;
        }

        // Mark token as used
        $tokenModel->markAsUsed($token);

        // Get demo user
        $demoUser = $this->userModel->findByEmail('demo@predio.pt');
        
        if (!$demoUser) {
            $_SESSION['demo_error'] = 'Conta demo não encontrada. Contacte o administrador.';
            header('Location: ' . BASE_URL . 'demo/access');
            exit;
        }

        // Check if user is active
        if ($demoUser['status'] !== 'active') {
            $_SESSION['demo_error'] = 'Conta demo não está ativa.';
            header('Location: ' . BASE_URL . 'demo/access');
            exit;
        }

        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Regenerate session ID on successful access
        session_regenerate_id(true);

        // Set user session
        $_SESSION['user'] = [
            'id' => $demoUser['id'],
            'email' => $demoUser['email'],
            'name' => $demoUser['name'],
            'role' => $demoUser['role']
        ];
        
        // Set session creation time
        $_SESSION['created'] = time();
        $_SESSION['last_activity'] = time();
        
        // Set demo profile to admin by default
        $_SESSION['demo_profile'] = 'admin';

        // Update last login
        $this->userModel->updateLastLogin($demoUser['id']);

        // Log audit
        $this->logAudit($demoUser['id'], 'login', 'Demo access via token - email: ' . $tokenData['email']);

        // Redirect to dashboard
        $_SESSION['login_success'] = 'Bem-vindo à demo! Explore todas as funcionalidades. Todas as alterações serão repostas automaticamente.';
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }

    /**
     * Direct demo access for super admin (bypasses token requirement)
     * Only accessible to authenticated super admins
     */
    public function superAdminDemoAccess()
    {
        // Require authentication
        if (!isset($_SESSION['user'])) {
            $_SESSION['login_error'] = 'Precisa de estar autenticado para aceder à demo.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Check if user is super admin
        $currentUser = $_SESSION['user'];
        if ($currentUser['role'] !== 'super_admin') {
            $_SESSION['error'] = 'Apenas super administradores podem aceder diretamente à demo.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        // Get demo user
        $demoUser = $this->userModel->findByEmail('demo@predio.pt');
        
        if (!$demoUser) {
            $_SESSION['error'] = 'Conta demo não encontrada. Contacte o administrador.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        // Check if user is active
        if ($demoUser['status'] !== 'active') {
            $_SESSION['error'] = 'Conta demo não está ativa.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        // Store original user info for later return (optional)
        $_SESSION['original_user'] = $currentUser;

        // Regenerate session ID for security
        session_regenerate_id(true);

        // Set user session as demo user
        $_SESSION['user'] = [
            'id' => $demoUser['id'],
            'email' => $demoUser['email'],
            'name' => $demoUser['name'],
            'role' => $demoUser['role']
        ];
        
        // Set session creation time
        $_SESSION['created'] = time();
        $_SESSION['last_activity'] = time();
        
        // Set demo profile to admin by default
        $_SESSION['demo_profile'] = 'admin';

        // Update last login
        $this->userModel->updateLastLogin($demoUser['id']);

        // Log audit
        $this->logAudit($demoUser['id'], 'login', 'Demo access by super admin: ' . $currentUser['email'] . ' (ID: ' . $currentUser['id'] . ')');

        // Redirect to dashboard
        $_SESSION['login_success'] = 'Bem-vindo à demo! Explore todas as funcionalidades. Todas as alterações serão repostas automaticamente.';
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }

    public function processLogin()
    {
        // Login is always allowed - no checks needed

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
        $_SESSION['last_activity'] = time();

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
        // Check for registration token in URL
        $token = $_GET['token'] ?? null;
        $hasValidToken = false;

        if ($token) {
            // Validate token
            $registrationTokenModel = new \App\Models\RegistrationToken();
            $tokenData = $registrationTokenModel->findByToken($token);
            
            if ($tokenData) {
                // Valid token - allow registration even if DISABLE_REGISTRATION is true (pioneers ignore this flag)
                $hasValidToken = true;
                $_SESSION['registration_token'] = $token;
                $_SESSION['registration_token_email'] = $tokenData['email'];
            } else {
                // Invalid or expired token
                $_SESSION['register_error'] = 'Token de convite inválido ou expirado. Por favor, solicite um novo convite.';
                header('Location: ' . BASE_URL);
                exit;
            }
        }

        // Check if registration is disabled (unless we have a valid token - pioneers ignore this flag)
        if ((defined('DISABLE_REGISTRATION') && DISABLE_REGISTRATION) && !$hasValidToken) {
            $_SESSION['login_error'] = 'O registo está temporariamente desativado. A aplicação encontra-se em fase de testes.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

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
            'has_registration_token' => $hasValidToken,
            'registration_token_email' => $_SESSION['registration_token_email'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
        ];
        
        unset($_SESSION['register_error']);
        unset($_SESSION['register_success']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function processRegister()
    {
        // Check for registration token in session
        $registrationToken = $_SESSION['registration_token'] ?? null;
        $hasValidToken = false;
        $tokenEmail = $_SESSION['registration_token_email'] ?? null;

        if ($registrationToken) {
            // Validate token
            $registrationTokenModel = new \App\Models\RegistrationToken();
            $tokenData = $registrationTokenModel->findByToken($registrationToken);
            
            if ($tokenData) {
                $hasValidToken = true;
                // Ensure email matches token email
                $tokenEmail = $tokenData['email'];
            } else {
                // Token invalid or expired - clear session and redirect
                unset($_SESSION['registration_token'], $_SESSION['registration_token_email']);
                $_SESSION['register_error'] = 'Token de convite inválido ou expirado. Por favor, solicite um novo convite.';
                header('Location: ' . BASE_URL . 'register');
                exit;
            }
        }

        // Check if direct registration is disabled (unless we have a valid token - pioneers ignore this flag)
        if ((defined('DISABLE_REGISTRATION') && DISABLE_REGISTRATION) && !$hasValidToken) {
            http_response_code(403);
            $_SESSION['error'] = 'O registo está temporariamente desativado. Por favor, utilize a demonstração para explorar o sistema.';
            header('Location: ' . BASE_URL);
            exit;
        }

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
        
        // If we have a valid token, ensure email matches token email
        if ($hasValidToken && $email !== $tokenEmail) {
            RateLimitMiddleware::recordAttempt('register');
            $_SESSION['register_error'] = 'O email deve corresponder ao email do convite (' . htmlspecialchars($tokenEmail) . ').';
            header('Location: ' . BASE_URL . 'register');
            exit;
        }
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
            // Preserve registration token in session if present
            $registrationToken = $_SESSION['registration_token'] ?? null;
            $registrationTokenEmail = $_SESSION['registration_token_email'] ?? null;
            
            $_SESSION['pending_registration'] = [
                'email' => $email,
                'password' => $password,
                'name' => $name,
                'phone' => $phone,
                'nif' => $nif,
                'role' => 'admin',
                'account_type' => 'admin'
            ];
            
            // Restore token in session if it was there
            if ($registrationToken) {
                $_SESSION['registration_token'] = $registrationToken;
                if ($registrationTokenEmail) {
                    $_SESSION['registration_token_email'] = $registrationTokenEmail;
                }
            }
            
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
                'status' => 'active',
                'is_pioneer' => $hasValidToken // Mark as pioneer if registered with token
            ]);

            // Mark registration token as used if present
            if ($hasValidToken && $registrationToken) {
                $registrationTokenModel = new \App\Models\RegistrationToken();
                $registrationTokenModel->markAsUsed($registrationToken);
                // Clear token from session
                unset($_SESSION['registration_token'], $_SESSION['registration_token_email']);
            }

            // Log audit
            $this->logAudit($userId, 'register', 'User registered' . ($hasValidToken ? ' via registration invite' : ''));

            // Auto login after registration
            $user = $this->userModel->findById($userId);
            if ($user) {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'role' => $user['role']
                ];
                
                // Set session creation time and last activity
                $_SESSION['created'] = time();
                $_SESSION['last_activity'] = time();
                
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
        // Password reset is always allowed - no checks needed

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
        // Password reset is always allowed - no checks needed

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
        // Password reset is always allowed - no checks needed

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
        // Password reset is always allowed - no checks needed

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
        // Google OAuth is always allowed for login
        // Registration blocking is checked in callback when user doesn't exist
        $source = $_GET['source'] ?? 'login';

        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user'])) {
            $this->redirectToDashboard();
            exit;
        }

        // If coming from registration page with token, ensure token is preserved
        // Token should already be in session from register() method, but we ensure it's there
        if ($source === 'register' && isset($_SESSION['registration_token'])) {
            // Token is already in session, just ensure it's preserved
            // No action needed, session will persist through Google OAuth redirect
        }

        try {
            $oauthService = new GoogleOAuthService();
            $authUrl = $oauthService->getAuthUrl();
            
            // Store source (login, register, or direct-access) in session for redirect after callback
            $_SESSION['google_oauth_source'] = $source;
            
            header('Location: ' . $authUrl);
            exit;
        } catch (\Exception $e) {
            $_SESSION['login_error'] = 'Erro ao iniciar autenticação Google: ' . $e->getMessage();
            $redirectUrl = ($source === 'direct-access') ? BASE_URL . 'access-admin-panel' : BASE_URL . 'login';
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    /**
     * Handle Google OAuth callback
     */
    public function googleCallback()
    {
        // Login via Google OAuth is always allowed
        // Registration blocking is checked when user doesn't exist
        $source = $_SESSION['google_oauth_source'] ?? 'login';

        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user'])) {
            $this->redirectToDashboard();
            exit;
        }

        $code = $_GET['code'] ?? '';
        $error = $_GET['error'] ?? '';

        $getRedirectUrl = function($src) {
            if ($src === 'direct-access') {
                return BASE_URL . 'access-admin-panel';
            }
            return BASE_URL . ($src === 'register' ? 'register' : 'login');
        };

        if (!empty($error)) {
            $_SESSION['login_error'] = 'Autenticação Google cancelada ou falhou.';
            unset($_SESSION['google_oauth_source']);
            header('Location: ' . $getRedirectUrl($source));
            exit;
        }

        if (empty($code)) {
            $_SESSION['login_error'] = 'Código de autorização não recebido.';
            unset($_SESSION['google_oauth_source']);
            header('Location: ' . $getRedirectUrl($source));
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
                        header('Location: ' . $getRedirectUrl($source));
                        exit;
                    }
                }
            }

            // If user found, log them in
            if ($user) {
                // Check if user is active
                if ($user['status'] !== 'active') {
                    $_SESSION['login_error'] = 'A sua conta está suspensa ou inativa.';
                    header('Location: ' . $getRedirectUrl($source));
                    exit;
                }

                // If user authenticated via Google and email is verified by Google, ensure email_verified_at is set
                if ($userInfo['verified_email'] && empty($user['email_verified_at'])) {
                    $this->userModel->update($user['id'], ['email_verified_at' => date('Y-m-d H:i:s')]);
                }

                // Set user session
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'role' => $user['role']
                ];
                
                // Set session creation time and last activity
                $_SESSION['created'] = time();
                $_SESSION['last_activity'] = time();

                // Update last login
                $this->userModel->updateLastLogin($user['id']);

                // Log audit
                $this->logAudit($user['id'], 'login', 'User logged in via Google OAuth');

                $_SESSION['login_success'] = 'Login realizado com sucesso via Google!';
                $this->redirectToDashboard();
                exit;
            }

            // User doesn't exist - check if registration is allowed
            // Check for registration token in session (pioneers ignore DISABLE_REGISTRATION flag)
            $registrationToken = $_SESSION['registration_token'] ?? null;
            $hasValidToken = false;
            $tokenEmail = null;
            
            if ($registrationToken) {
                // Validate token
                $registrationTokenModel = new \App\Models\RegistrationToken();
                $tokenData = $registrationTokenModel->findByToken($registrationToken);
                
                if ($tokenData) {
                    $hasValidToken = true;
                    $tokenEmail = $tokenData['email'];
                    // Verify email matches token email (pioneers must use the email from the token)
                    if (strtolower($email) !== strtolower($tokenEmail)) {
                        // Email doesn't match token - don't allow registration
                        $_SESSION['login_error'] = 'O email do Google (' . htmlspecialchars($email) . ') não corresponde ao email do convite (' . htmlspecialchars($tokenEmail) . '). Por favor, use o email do convite.';
                        header('Location: ' . BASE_URL . 'login');
                        exit;
                    }
                }
            }
            
            // Check if registration is allowed
            // If DISABLE_REGISTRATION is true, only allow if we have a valid token (pioneer)
            // If DISABLE_REGISTRATION is false, allow if source is 'register'
            $canRegister = false;
            if (defined('DISABLE_REGISTRATION') && DISABLE_REGISTRATION) {
                // Registration disabled - only allow with valid token
                $canRegister = $hasValidToken;
            } else {
                // Registration enabled - allow if source is 'register'
                $canRegister = ($source === 'register');
            }
            
            if (!$canRegister) {
                // Registration is blocked
                $_SESSION['login_error'] = 'O registo está temporariamente desativado. Por favor, utilize a demonstração para explorar o sistema.';
                header('Location: ' . BASE_URL . 'login');
                exit;
            }
            
            // Registration is allowed - proceed with account creation
            // If we have a valid token, preserve it in the Google OAuth pending data
            if ($hasValidToken && $registrationToken) {
                $_SESSION['registration_token'] = $registrationToken;
                if ($tokenEmail) {
                    $_SESSION['registration_token_email'] = $tokenEmail;
                }
            }
            
            if ($source === 'register' || $hasValidToken) {
                // Store Google OAuth data in session for account creation
                $_SESSION['google_oauth_pending'] = [
                    'email' => $email,
                    'name' => $name,
                    'google_id' => $googleId,
                    'auth_provider' => 'google',
                    'verified_email' => $userInfo['verified_email'] ?? false
                ];
                // Redirect to account type selection
                header('Location: ' . BASE_URL . 'auth/select-account-type');
                exit;
            } else {
                // Trying to login but account doesn't exist
                $_SESSION['login_error'] = 'Conta não encontrada. Por favor, registe-se primeiro.';
                header('Location: ' . $getRedirectUrl($source));
                exit;
            }
        } catch (\Exception $e) {
            $_SESSION['login_error'] = 'Erro ao processar autenticação Google: ' . $e->getMessage();
            $source = $_SESSION['google_oauth_source'] ?? 'login';
            unset($_SESSION['google_oauth_source']);
            header('Location: ' . $getRedirectUrl($source));
            exit;
        }
    }

    /**
     * Show account type selection page (for Google OAuth)
     */
    public function selectAccountType()
    {
        // Check if registration is disabled (unless we have a valid token - pioneers ignore this flag)
        $registrationToken = $_SESSION['registration_token'] ?? null;
        $hasValidToken = false;
        
        if ($registrationToken) {
            // Validate token
            $registrationTokenModel = new \App\Models\RegistrationToken();
            $tokenData = $registrationTokenModel->findByToken($registrationToken);
            
            if ($tokenData) {
                $hasValidToken = true;
            }
        }
        
        if ((defined('DISABLE_REGISTRATION') && DISABLE_REGISTRATION) && !$hasValidToken) {
            $_SESSION['info'] = 'O registo está temporariamente desativado. Por favor, utilize a demonstração para explorar o sistema.';
            header('Location: ' . BASE_URL);
            exit;
        }

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
        // Check if registration is disabled (unless we have a valid token - pioneers ignore this flag)
        $registrationToken = $_SESSION['registration_token'] ?? null;
        $hasValidToken = false;
        
        if ($registrationToken) {
            // Validate token
            $registrationTokenModel = new \App\Models\RegistrationToken();
            $tokenData = $registrationTokenModel->findByToken($registrationToken);
            
            if ($tokenData) {
                $hasValidToken = true;
            }
        }
        
        if ((defined('DISABLE_REGISTRATION') && DISABLE_REGISTRATION) && !$hasValidToken) {
            http_response_code(403);
            $_SESSION['error'] = 'O registo está temporariamente desativado. Por favor, utilize a demonstração para explorar o sistema.';
            header('Location: ' . BASE_URL);
            exit;
        }

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
                // Check for registration token (pioneer)
                $registrationToken = $_SESSION['registration_token'] ?? null;
                $hasValidToken = false;
                if ($registrationToken) {
                    $registrationTokenModel = new \App\Models\RegistrationToken();
                    $tokenData = $registrationTokenModel->findByToken($registrationToken);
                    if ($tokenData) {
                        $hasValidToken = true;
                    }
                }
                
                // Get verified_email from Google OAuth data if available
                $userData = [
                    'email' => $googleData['email'],
                    'name' => $googleData['name'],
                    'role' => 'condomino',
                    'status' => 'active',
                    'google_id' => $googleData['google_id'],
                    'auth_provider' => $googleData['auth_provider'],
                    'is_pioneer' => $hasValidToken // Mark as pioneer if registered with token
                ];
                
                // If email is verified by Google, set email_verified_at
                if (isset($googleData['verified_email']) && $googleData['verified_email']) {
                    $userData['email_verified_at'] = date('Y-m-d H:i:s');
                }
                
                $userId = $this->userModel->create($userData);

                // Mark registration token as used if present (pioneer registration)
                if ($hasValidToken && $registrationToken) {
                    $registrationTokenModel = new \App\Models\RegistrationToken();
                    $tokenData = $registrationTokenModel->findByToken($registrationToken);
                    if ($tokenData) {
                        $registrationTokenModel->markAsUsed($registrationToken);
                        unset($_SESSION['registration_token'], $_SESSION['registration_token_email']);
                    }
                }

                // Log audit
                $this->logAudit($userId, 'register', 'User registered via Google OAuth' . ($registrationToken ? ' via registration invite' : ''));

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
                    
                    // Set session creation time and last activity
                    $_SESSION['created'] = time();
                    $_SESSION['last_activity'] = time();
                    
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
        // Check for registration token in session
        $registrationToken = $_SESSION['registration_token'] ?? null;
        $hasValidToken = false;

        if ($registrationToken) {
            // Validate token
            $registrationTokenModel = new \App\Models\RegistrationToken();
            $tokenData = $registrationTokenModel->findByToken($registrationToken);
            
            if ($tokenData) {
                $hasValidToken = true;
            } else {
                // Token invalid or expired - clear session
                unset($_SESSION['registration_token'], $_SESSION['registration_token_email']);
            }
        }

        // Check if direct registration is disabled (unless we have a valid token - pioneers ignore this flag)
        if ((defined('DISABLE_REGISTRATION') && DISABLE_REGISTRATION) && !$hasValidToken) {
            $_SESSION['register_error'] = 'O registo está temporariamente desativado. Por favor, utilize a demonstração para explorar o sistema.';
            header('Location: ' . BASE_URL);
            exit;
        }

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
            'is_pioneer' => $hasValidToken, // Flag to indicate pioneer user
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
        // Check for registration token in session
        $registrationToken = $_SESSION['registration_token'] ?? null;
        $hasValidToken = false;

        if ($registrationToken) {
            // Validate token
            $registrationTokenModel = new \App\Models\RegistrationToken();
            $tokenData = $registrationTokenModel->findByToken($registrationToken);
            
            if ($tokenData) {
                $hasValidToken = true;
            } else {
                // Token invalid or expired - clear session
                unset($_SESSION['registration_token'], $_SESSION['registration_token_email']);
            }
        }

        // Check if direct registration is disabled (unless we have a valid token - pioneers ignore this flag)
        if ((defined('DISABLE_REGISTRATION') && DISABLE_REGISTRATION) && !$hasValidToken) {
            http_response_code(403);
            $_SESSION['error'] = 'O registo está temporariamente desativado. Por favor, utilize a demonstração para explorar o sistema.';
            header('Location: ' . BASE_URL);
            exit;
        }

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
            // Check for registration token (pioneer)
            $registrationToken = $_SESSION['registration_token'] ?? null;
            $hasValidToken = false;
            if ($registrationToken) {
                $registrationTokenModel = new \App\Models\RegistrationToken();
                $tokenData = $registrationTokenModel->findByToken($registrationToken);
                if ($tokenData && $tokenData['email'] === $pendingData['email']) {
                    $hasValidToken = true;
                }
            }
            
            // Create user account
            $userData = [
                'email' => $pendingData['email'],
                'name' => $pendingData['name'],
                'role' => 'admin',
                'status' => 'active',
                'is_pioneer' => $hasValidToken // Mark as pioneer if registered with token
            ];

            if ($isGoogleOAuth) {
                $userData['google_id'] = $pendingData['google_id'];
                $userData['auth_provider'] = $pendingData['auth_provider'];
                // If email is verified by Google, set email_verified_at
                if (isset($pendingData['verified_email']) && $pendingData['verified_email']) {
                    $userData['email_verified_at'] = date('Y-m-d H:i:s');
                }
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

            // Mark registration token as used if present
            if ($hasValidToken && $registrationToken) {
                $registrationTokenModel = new \App\Models\RegistrationToken();
                $tokenData = $registrationTokenModel->findByToken($registrationToken);
                if ($tokenData) {
                    $registrationTokenModel->markAsUsed($registrationToken);
                    // Clear token from session
                    unset($_SESSION['registration_token'], $_SESSION['registration_token_email']);
                }
            }

            // Log audit
            $this->logAudit($userId, 'register', 'Admin registered' . ($isGoogleOAuth ? ' via Google OAuth' : '') . (isset($registrationToken) && isset($tokenData) && $tokenData ? ' via registration invite' : ''));

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
                
                // Set session creation time and last activity
                $_SESSION['created'] = time();
                $_SESSION['last_activity'] = time();
                
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

    /**
     * Process pilot user signup request
     */
    public function processPilotSignup()
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL);
            exit;
        }

        // Determine redirect URL based on referer or form field
        $redirectUrl = BASE_URL . 'login';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, BASE_URL) === 0) {
            // If coming from homepage, redirect back to homepage
            if (strpos($referer, BASE_URL . 'login') === false && (strpos($referer, BASE_URL) === 0 && (strpos($referer, BASE_URL . '?') === false || strpos($referer, BASE_URL . '?') === 0))) {
                $redirectUrl = BASE_URL;
            }
        }
        
        // Check if source is specified in form
        $source = $_POST['source'] ?? '';
        if ($source === 'homepage') {
            $redirectUrl = BASE_URL;
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['pilot_signup_error'] = 'Token de segurança inválido.';
            header('Location: ' . $redirectUrl);
            exit;
        }

        $email = Security::sanitize($_POST['email'] ?? '');

        // Validate email
        if (empty($email)) {
            $_SESSION['pilot_signup_error'] = 'Por favor, introduza o seu email.';
            header('Location: ' . $redirectUrl);
            exit;
        }

        if (!Security::validateEmail($email)) {
            $_SESSION['pilot_signup_error'] = 'Email inválido.';
            header('Location: ' . $redirectUrl);
            exit;
        }

        // Subscribe to newsletter with source 'pilot_user'
        try {
            $newsletterService = new \App\Services\NewsletterService();
            $success = $newsletterService->subscribe($email, 'pilot_user');

            if ($success) {
                // Get subscription date for notification
                global $db;
                $stmt = $db->prepare("SELECT subscribed_at FROM newsletter_subscribers WHERE email = :email AND source = 'pilot_user' LIMIT 1");
                $stmt->execute([':email' => $email]);
                $subscriber = $stmt->fetch();
                $subscribedAt = $subscriber ? date('d/m/Y H:i', strtotime($subscriber['subscribed_at'])) : date('d/m/Y H:i');

                // Create email service instance once
                $emailService = new \App\Core\EmailService();

                // Send thank you email to pilot user
                $thankYouSent = false;
                try {
                    $thankYouSent = $emailService->sendPilotSignupThankYouEmail($email);
                    if (!$thankYouSent) {
                        error_log("AuthController: Failed to send pilot signup thank you email to: {$email}");
                    } else {
                        error_log("AuthController: Successfully sent pilot signup thank you email to: {$email}");
                    }
                } catch (\Exception $emailException) {
                    // Log email error but don't fail the signup
                    error_log("AuthController: Exception sending pilot signup thank you email to {$email}: " . $emailException->getMessage());
                    error_log("AuthController: Exception trace: " . $emailException->getTraceAsString());
                }

                // Send notification email to super admin
                $notificationSent = false;
                try {
                    $notificationSent = $emailService->sendPilotUserNotificationEmail($email, $subscribedAt);
                    if (!$notificationSent) {
                        error_log("AuthController: Failed to send pilot user notification to super admin for: {$email}");
                    } else {
                        error_log("AuthController: Successfully sent pilot user notification to super admin for: {$email}");
                    }
                } catch (\Exception $notificationException) {
                    // Log notification error but don't fail the signup
                    error_log("AuthController: Exception sending pilot user notification to super admin for {$email}: " . $notificationException->getMessage());
                    error_log("AuthController: Exception trace: " . $notificationException->getTraceAsString());
                }
                
                $_SESSION['pilot_signup_success'] = 'Obrigado pelo seu interesse! Entraremos em contacto em breve.';
            } else {
                $_SESSION['pilot_signup_error'] = 'Erro ao processar inscrição. Por favor, tente novamente.';
            }
        } catch (\Exception $e) {
            error_log("AuthController: Error processing pilot signup for {$email}: " . $e->getMessage());
            error_log("AuthController: Exception trace: " . $e->getTraceAsString());
            $_SESSION['pilot_signup_error'] = 'Erro ao processar inscrição. Por favor, tente novamente.';
        }

        header('Location: ' . $redirectUrl);
        exit;
    }
}

