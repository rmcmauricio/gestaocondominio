<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Services\SecurityLogger;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Plan;

class ApiKeyController extends Controller
{
    protected $userModel;
    protected $subscriptionModel;
    protected $planModel;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
        $this->subscriptionModel = new Subscription();
        $this->planModel = new Plan();
    }

    public function index()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);
        
        $hasApiAccess = $subscription && $this->subscriptionModel->hasFeature($userId, 'api_access');
        $apiKeyInfo = $this->userModel->getApiKeyInfo($userId);

        $this->loadPageTranslations('api');
        
        $this->data += [
            'viewName' => 'pages/api/index.html.twig',
            'page' => ['titulo' => 'API Keys'],
            'has_business_plan' => $hasApiAccess,
            'api_key_info' => $apiKeyInfo,
            'api_key_generated' => $_SESSION['api_key_generated'] ?? null,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['api_key_generated']);
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function generate()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'api-keys');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'api-keys');
            exit;
        }

        // Check rate limit for API key generation
        try {
            RateLimitMiddleware::require('api_key_generate');
        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: ' . BASE_URL . 'api-keys');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);

        if (!$subscription || !$this->subscriptionModel->hasFeature($userId, 'api_access')) {
            RateLimitMiddleware::recordAttempt('api_key_generate');
            $_SESSION['error'] = 'Acesso à API requer um plano com acesso à API (Enterprise).';
            header('Location: ' . BASE_URL . 'api-keys');
            exit;
        }

        try {
            $apiKey = $this->userModel->generateApiKey($userId);
            
            // Log API key generation
            $securityLogger = new SecurityLogger();
            $securityLogger->logApiKeyUsage('generated', $userId);
            
            $_SESSION['api_key_generated'] = $apiKey;
            $_SESSION['success'] = 'API Key gerada com sucesso! Guarde-a em segurança, pois não será exibida novamente.';
            header('Location: ' . BASE_URL . 'api-keys');
            exit;
        } catch (\Exception $e) {
            RateLimitMiddleware::recordAttempt('api_key_generate');
            $_SESSION['error'] = 'Erro ao gerar API Key. Por favor, tente novamente.';
            error_log("API Key generation error for user {$userId}: " . $e->getMessage());
            header('Location: ' . BASE_URL . 'api-keys');
            exit;
        }
    }

    public function revoke()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'api-keys');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'api-keys');
            exit;
        }

        $userId = AuthMiddleware::userId();

        if ($this->userModel->revokeApiKey($userId)) {
            $_SESSION['success'] = 'API Key revogada com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao revogar API Key.';
        }

        header('Location: ' . BASE_URL . 'api-keys');
        exit;
    }

    public function documentation()
    {
        AuthMiddleware::require();

        $this->loadPageTranslations('api');
        
        $this->data += [
            'viewName' => 'pages/api/documentation.html.twig',
            'page' => ['titulo' => 'Documentação da API']
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

