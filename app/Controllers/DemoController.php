<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Middleware\DemoProtectionMiddleware;
use App\Models\User;
use App\Models\CondominiumUser;

class DemoController extends Controller
{
    protected $userModel;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
    }

    public function index()
    {
        $this->loadPageTranslations('demo');
        
        $this->data += [
            'viewName' => 'pages/demo.html.twig',
            'page' => [
                'titulo' => 'Framework Demo & Documentation',
                'description' => 'Learn how to use the MVC Framework',
                'keywords' => 'mvc, framework, documentation, tutorial'
            ]
        ];
        
        $this->renderMainTemplate();
    }

    /**
     * Switch demo profile (admin or condomino)
     */
    public function switchProfile()
    {
        AuthMiddleware::require();
        
        $user = AuthMiddleware::user();
        
        // Check if current user is demo user OR if they're switching from demo context
        $isDemoContext = false;
        
        // Check if current user is demo
        if (DemoProtectionMiddleware::isDemoUser($user['id'])) {
            $isDemoContext = true;
        }
        
        // Also check if switching from a demo condomino user
        if (!$isDemoContext && isset($_SESSION['demo_profile'])) {
            $isDemoContext = true;
        }
        
        if (!$isDemoContext) {
            $_SESSION['error'] = 'Apenas utilizadores demo podem alternar perfis.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        $profileType = $_GET['profile'] ?? 'admin';
        
        if ($profileType === 'admin') {
            // Switch to admin profile (demo user)
            $demoUser = $this->userModel->findByEmail('demo@predio.pt');
            if ($demoUser) {
                $_SESSION['user'] = [
                    'id' => $demoUser['id'],
                    'email' => $demoUser['email'],
                    'name' => $demoUser['name'],
                    'role' => $demoUser['role']
                ];
                $_SESSION['demo_profile'] = 'admin';
                unset($_SESSION['demo_condominium_id']);
                unset($_SESSION['demo_fraction_id']);
            }
        } else {
            // Switch to condomino profile (first demo condomino user)
            global $db;
            $stmt = $db->prepare("
                SELECT u.id, u.email, u.name, u.role, cu.condominium_id, cu.fraction_id, f.identifier as fraction_identifier
                FROM users u
                INNER JOIN condominium_users cu ON cu.user_id = u.id
                INNER JOIN fractions f ON f.id = cu.fraction_id
                INNER JOIN condominiums c ON c.id = cu.condominium_id
                WHERE c.is_demo = TRUE
                AND u.role = 'condomino'
                AND cu.is_primary = TRUE
                ORDER BY u.id ASC
                LIMIT 1
            ");
            $stmt->execute();
            $condominoUser = $stmt->fetch();
            
            if ($condominoUser) {
                $_SESSION['user'] = [
                    'id' => $condominoUser['id'],
                    'email' => $condominoUser['email'],
                    'name' => $condominoUser['name'],
                    'role' => 'condomino'
                ];
                $_SESSION['demo_profile'] = 'condomino';
                $_SESSION['demo_condominium_id'] = $condominoUser['condominium_id'];
                $_SESSION['demo_fraction_id'] = $condominoUser['fraction_id'];
            }
        }

        // Redirect back to dashboard
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }
}

