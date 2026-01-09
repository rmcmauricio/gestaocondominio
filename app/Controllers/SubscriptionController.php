<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;

class SubscriptionController extends Controller
{
    protected $planModel;
    protected $subscriptionModel;
    protected $subscriptionService;

    public function __construct()
    {
        parent::__construct();
        $this->planModel = new Plan();
        $this->subscriptionModel = new Subscription();
        $this->subscriptionService = new SubscriptionService();
    }

    /**
     * Convert plan features from JSON to readable array
     */
    protected function convertFeaturesToReadable(array $plans): array
    {
        $featureLabels = [
            'financas_basicas' => 'Finanças Básicas',
            'financas_completas' => 'Finanças Completas',
            'documentos' => 'Gestão de Documentos',
            'ocorrencias_simples' => 'Ocorrências Simples',
            'votacoes_online' => 'Votações Online',
            'reservas_espacos' => 'Reservas de Espaços',
            'gestao_contratos' => 'Gestão de Contratos',
            'gestao_fornecedores' => 'Gestão de Fornecedores',
            'api' => 'API REST',
            'branding_personalizado' => 'Branding Personalizado',
            'app_mobile' => 'App Mobile',
            'app_mobile_premium' => 'App Mobile Premium',
            'todos_modulos' => 'Todos os Módulos',
            'suporte_prioritario' => 'Suporte Prioritário'
        ];
        
        foreach ($plans as &$plan) {
            $featuresArray = [];
            if (isset($plan['features']) && is_string($plan['features'])) {
                $featuresJson = json_decode($plan['features'], true) ?: [];
                // Convert boolean features to readable list
                foreach ($featuresJson as $key => $value) {
                    if ($value === true && isset($featureLabels[$key])) {
                        $featuresArray[] = $featureLabels[$key];
                    }
                }
            }
            $plan['features'] = $featuresArray;
        }
        unset($plan); // Break reference
        
        return $plans;
    }

    public function index()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);
        $plans = $this->planModel->getActivePlans();
        
        // Convert features to readable format
        $plans = $this->convertFeaturesToReadable($plans);

        $this->loadPageTranslations('subscription');
        
        $this->data += [
            'viewName' => 'pages/subscription/index.html.twig',
            'page' => [
                'titulo' => 'Subscrição',
                'description' => 'Manage your subscription'
            ],
            'subscription' => $subscription,
            'plans' => $plans,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function choosePlan()
    {
        // Allow both guests and logged in users
        $plans = $this->planModel->getActivePlans();
        
        // Convert features to readable format
        $plans = $this->convertFeaturesToReadable($plans);

        $this->loadPageTranslations('subscription');
        
        $this->data += [
            'viewName' => 'pages/subscription/choose-plan.html.twig',
            'page' => [
                'titulo' => 'Escolher Plano - MeuPrédio',
                'description' => 'Escolha o plano ideal para o seu condomínio'
            ],
            'plans' => $plans,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function startTrial()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $planId = (int)($_POST['plan_id'] ?? 0);
        $plan = $this->planModel->findById($planId);

        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $userId = AuthMiddleware::userId();

        // Check if user already has active subscription
        $existing = $this->subscriptionModel->getActiveSubscription($userId);
        if ($existing) {
            $_SESSION['error'] = 'Já tem uma subscrição ativa.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        try {
            $this->subscriptionService->startTrial($userId, $planId, 14);
            $_SESSION['success'] = 'Período experimental iniciado com sucesso!';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao iniciar período experimental: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
    }

    public function upgrade()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $newPlanId = (int)($_POST['plan_id'] ?? 0);
        $plan = $this->planModel->findById($newPlanId);

        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $userId = AuthMiddleware::userId();

        try {
            $this->subscriptionService->upgrade($userId, $newPlanId);
            $_SESSION['success'] = 'Subscrição atualizada com sucesso!';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar subscrição: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
    }

    public function cancel()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);

        if (!$subscription) {
            $_SESSION['error'] = 'Subscrição não encontrada.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        if ($this->subscriptionModel->cancel($subscription['id'])) {
            $_SESSION['success'] = 'Subscrição cancelada com sucesso.';
        } else {
            $_SESSION['error'] = 'Erro ao cancelar subscrição.';
        }

        header('Location: ' . BASE_URL . 'subscription');
        exit;
    }

    public function reactivate()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $userId = AuthMiddleware::userId();
        
        // Get subscription including cancelled ones
        global $db;
        $stmt = $db->prepare("
            SELECT * FROM subscriptions 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $subscription = $stmt->fetch();

        if (!$subscription || $subscription['status'] !== 'canceled') {
            $_SESSION['error'] = 'Subscrição não encontrada ou não está cancelada.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $now = date('Y-m-d H:i:s');
        $periodEnd = date('Y-m-d H:i:s', strtotime("+1 month"));
        
        if ($this->subscriptionModel->update($subscription['id'], [
            'status' => 'active',
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'canceled_at' => null
        ])) {
            $_SESSION['success'] = 'Subscrição reativada com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao reativar subscrição.';
        }

        header('Location: ' . BASE_URL . 'subscription');
        exit;
    }

    public function changePlan()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $newPlanId = (int)($_POST['plan_id'] ?? 0);
        $plan = $this->planModel->findById($newPlanId);

        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $userId = AuthMiddleware::userId();

        try {
            $this->subscriptionService->changePlan($userId, $newPlanId);
            $_SESSION['success'] = 'Plano alterado com sucesso!';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao alterar plano: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
    }
}

