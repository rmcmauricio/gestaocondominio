<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\DemoProtectionMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Services\SecurityLogger;
use App\Models\User;
use App\Models\UserEmailPreference;

class ProfileController extends Controller
{
    protected $userModel;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
    }

    public function show()
    {
        AuthMiddleware::require();

        $user = AuthMiddleware::user();
        $userId = AuthMiddleware::userId();
        
        // Get full user data
        $userData = $this->userModel->findById($userId);
        
        if (!$userData) {
            $_SESSION['error'] = 'Utilizador não encontrado.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        // Check if user is demo
        $isDemo = DemoProtectionMiddleware::isDemoUser($userId);

        // Get email preferences
        $preferenceModel = new UserEmailPreference();
        $emailPreferences = $preferenceModel->getPreferences($userId);

        $this->loadPageTranslations('profile');
        
        $this->data += [
            'viewName' => 'pages/profile/show.html.twig',
            'page' => ['titulo' => 'Meu Perfil'],
            'user' => $userData,
            'is_demo' => $isDemo,
            'email_preferences' => $emailPreferences,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function update()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        
        // Prevent demo user from editing
        DemoProtectionMiddleware::preventDemoUserEdit($userId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Verify CSRF token
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $nif = trim($_POST['nif'] ?? '');

        // Validation
        if (empty($email)) {
            $_SESSION['error'] = 'O email é obrigatório.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Formato de email inválido.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Check if email already exists (excluding current user)
        $existingUser = $this->userModel->findByEmail($email);
        if ($existingUser && $existingUser['id'] != $userId) {
            $_SESSION['error'] = 'Este email já está em uso por outra conta.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        if (empty($name)) {
            $_SESSION['error'] = 'O nome é obrigatório.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        if (strlen($name) < 2) {
            $_SESSION['error'] = 'O nome deve ter pelo menos 2 caracteres.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Validate NIF if provided (Portuguese NIF format: 9 digits)
        if (!empty($nif) && (!preg_match('/^\d{9}$/', $nif))) {
            $_SESSION['error'] = 'O NIF deve ter 9 dígitos.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Validate phone if provided
        if (!empty($phone) && (!preg_match('/^[\d\s\-\+\(\)]+$/', $phone))) {
            $_SESSION['error'] = 'Formato de telefone inválido.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Update user
        $updateData = [
            'email' => $email,
            'name' => $name,
            'phone' => !empty($phone) ? $phone : null,
            'nif' => !empty($nif) ? $nif : null
        ];

        if ($this->userModel->update($userId, $updateData)) {
            // Update session
            $updatedUser = $this->userModel->findById($userId);
            $_SESSION['user']['name'] = $updatedUser['name'];
            $_SESSION['user']['email'] = $updatedUser['email'];
            
            $_SESSION['success'] = 'Perfil atualizado com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao atualizar perfil. Tente novamente.';
        }

        header('Location: ' . BASE_URL . 'profile');
        exit;
    }

    public function updatePassword()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        
        // Prevent demo user from editing
        DemoProtectionMiddleware::preventDemoUserEdit($userId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Verify CSRF token
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $_SESSION['error'] = 'Todos os campos são obrigatórios.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Get user
        $user = $this->userModel->findById($userId);
        if (!$user) {
            $_SESSION['error'] = 'Utilizador não encontrado.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Check rate limit for password change
        try {
            RateLimitMiddleware::require('password_change');
        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Verify current password
        if (!Security::verifyPassword($currentPassword, $user['password'])) {
            RateLimitMiddleware::recordAttempt('password_change');
            // Log failed password change attempt
            $securityLogger = new SecurityLogger();
            $securityLogger->logSuspiciousActivity('failed_password_change', ['user_id' => $userId]);
            $_SESSION['error'] = 'Palavra-passe atual incorreta.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Check if new password is different
        if ($currentPassword === $newPassword) {
            $_SESSION['error'] = 'A nova palavra-passe deve ser diferente da atual.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Validate new password strength (comprehensive validation)
        $passwordValidation = Security::validatePasswordStrength($newPassword, $user['email'] ?? null, $user['name'] ?? null);
        if (!$passwordValidation['valid']) {
            RateLimitMiddleware::recordAttempt('password_change');
            $_SESSION['error'] = implode(' ', $passwordValidation['errors']);
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Check if passwords match
        if ($newPassword !== $confirmPassword) {
            RateLimitMiddleware::recordAttempt('password_change');
            $_SESSION['error'] = 'As palavras-passe não coincidem.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Update password
        if ($this->userModel->update($userId, ['password' => $newPassword])) {
            // Reset rate limit on success
            RateLimitMiddleware::reset('password_change');
            
            // Log password change
            $securityLogger = new SecurityLogger();
            $securityLogger->logAccountModification('password_change', $userId);
            
            $_SESSION['success'] = 'Palavra-passe alterada com sucesso!';
        } else {
            RateLimitMiddleware::recordAttempt('password_change');
            $_SESSION['error'] = 'Erro ao alterar palavra-passe. Tente novamente.';
        }

        header('Location: ' . BASE_URL . 'profile');
        exit;
    }

    public function updateEmailPreferences()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        
        // Prevent demo users from updating preferences
        if (DemoProtectionMiddleware::isDemoUser($userId)) {
            $_SESSION['error'] = 'Não é possível alterar preferências de email para utilizadores demo.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        // Verify CSRF token
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'profile');
            exit;
        }

        $preferenceModel = new UserEmailPreference();
        
        $preferences = [
            'email_notifications_enabled' => isset($_POST['email_notifications_enabled']),
            'email_messages_enabled' => isset($_POST['email_messages_enabled'])
        ];

        try {
            if ($preferenceModel->updatePreferences($userId, $preferences)) {
                $_SESSION['success'] = 'Preferências de email atualizadas com sucesso!';
            } else {
                $_SESSION['error'] = 'Erro ao atualizar preferências de email. Tente novamente.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'profile');
        exit;
    }
}
