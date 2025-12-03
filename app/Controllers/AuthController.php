<?php

namespace App\Controllers;

use App\Core\Controller;

class AuthController extends Controller
{
    public function login()
    {
        // If already logged in, redirect to home
        if (isset($_SESSION['user'])) {
            header('Location: ' . BASE_URL);
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
            'success' => $_SESSION['login_success'] ?? null
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

        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        // Basic validation
        if (empty($email) || empty($password)) {
            $_SESSION['login_error'] = 'Por favor, preencha todos os campos.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Example authentication (replace with your actual authentication logic)
        // For demo purposes, accept any email/password combination
        // In production, you should:
        // 1. Check against database
        // 2. Verify password hash
        // 3. Implement proper security measures
        
        // Example: Simple demo authentication
        // Replace this with your actual authentication logic
        if ($this->authenticate($email, $password)) {
            // Set user session
            $_SESSION['user'] = [
                'email' => $email,
                'id' => 1, // Replace with actual user ID from database
                'name' => explode('@', $email)[0] // Replace with actual user name
            ];
            
            $_SESSION['login_success'] = 'Login realizado com sucesso!';
            header('Location: ' . BASE_URL);
            exit;
        } else {
            $_SESSION['login_error'] = 'Email ou senha incorretos.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }
    }

    private function authenticate(string $email, string $password): bool
    {
        // Example authentication logic
        // Replace this with your actual authentication (database check, etc.)
        
        // For demo: accept any non-empty email/password
        // In production, check against database:
        /*
        $this->requireDatabase();
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            return true;
        }
        return false;
        */
        
        // Demo: simple check
        return !empty($email) && !empty($password);
    }

    public function logout()
    {
        // Destroy session
        session_start();
        session_unset();
        session_destroy();
        
        // Start new session
        session_start();
        
        // Redirect to home
        header('Location: ' . BASE_URL);
        exit;
    }
}

