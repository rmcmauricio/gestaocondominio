<?php

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\SubscriptionCondominium;
use App\Models\Condominium;
use App\Services\SubscriptionService;
use App\Services\LicenseService;

class SubscriptionManagementController extends Controller
{
    protected $subscriptionModel;
    protected $planModel;
    protected $subscriptionService;
    protected $licenseService;

    public function __construct()
    {
        parent::__construct();
        
        // Require admin access
        AuthMiddleware::require();
        RoleMiddleware::require(['admin', 'super_admin']);
        
        $this->subscriptionModel = new Subscription();
        $this->planModel = new Plan();
        $this->subscriptionService = new SubscriptionService();
        $this->licenseService = new LicenseService();
    }

    /**
     * List all subscriptions with filters
     */
    public function index()
    {
        global $db;
        
        $filterPlan = $_GET['plan'] ?? null;
        $filterStatus = $_GET['status'] ?? null;
        $filterLicenses = $_GET['licenses'] ?? null;

        $sql = "SELECT s.*, p.name as plan_name, p.slug as plan_slug, p.plan_type, 
                       u.name as user_name, u.email as user_email,
                       COUNT(DISTINCT sc.id) as condominium_count
                FROM subscriptions s
                INNER JOIN plans p ON s.plan_id = p.id
                INNER JOIN users u ON s.user_id = u.id
                LEFT JOIN subscription_condominiums sc ON sc.subscription_id = s.id AND sc.status = 'active'
                WHERE 1=1";

        $params = [];

        if ($filterPlan) {
            $sql .= " AND p.slug = :plan";
            $params[':plan'] = $filterPlan;
        }

        if ($filterStatus) {
            $sql .= " AND s.status = :status";
            $params[':status'] = $filterStatus;
        }

        $sql .= " GROUP BY s.id ORDER BY s.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $subscriptions = $stmt->fetchAll() ?: [];

        // Get plans for filter
        $plans = $this->planModel->getAll();

        $this->loadPageTranslations('admin');

        $this->data += [
            'viewName' => 'pages/admin/subscriptions/index.html.twig',
            'page' => [
                'titulo' => 'Gestão de Subscrições',
                'description' => 'Manage all subscriptions'
            ],
            'subscriptions' => $subscriptions,
            'plans' => $plans,
            'filter_plan' => $filterPlan,
            'filter_status' => $filterStatus,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * View subscription details
     */
    public function view(int $subscriptionId)
    {
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            $_SESSION['error'] = 'Subscrição não encontrada.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $plan = $this->planModel->findById($subscription['plan_id']);
        $subscriptionCondominiumModel = new SubscriptionCondominium();
        $associatedCondominiums = $subscriptionCondominiumModel->getBySubscription($subscriptionId, 'all');

        // Get pricing preview
        $pricingPreview = null;
        try {
            $pricingPreview = $this->subscriptionService->getCurrentCharge($subscriptionId);
        } catch (\Exception $e) {
            // Ignore
        }

        // Validate limits
        $validation = $this->subscriptionService->validateSubscriptionLimits($subscriptionId);

        $this->loadPageTranslations('admin');

        $this->data += [
            'viewName' => 'pages/admin/subscriptions-manage/view.html.twig',
            'page' => [
                'titulo' => 'Detalhes da Subscrição',
                'description' => 'Subscription details'
            ],
            'subscription' => $subscription,
            'plan' => $plan,
            'associated_condominiums' => $associatedCondominiums,
            'pricing_preview' => $pricingPreview,
            'validation' => $validation,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Admin attach condominium
     */
    public function attachCondominium()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
        $condominiumId = (int)($_POST['condominium_id'] ?? 0);
        $userId = AuthMiddleware::userId();

        if (!$subscriptionId || !$condominiumId) {
            $_SESSION['error'] = 'Dados inválidos.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        try {
            $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId, $userId);
            $_SESSION['success'] = 'Condomínio associado com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao associar condomínio: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/subscriptions/view/' . $subscriptionId);
        exit;
    }

    /**
     * Admin detach condominium
     */
    public function detachCondominium()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
        $condominiumId = (int)($_POST['condominium_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $userId = AuthMiddleware::userId();

        if (!$subscriptionId || !$condominiumId) {
            $_SESSION['error'] = 'Dados inválidos.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        try {
            $this->subscriptionService->detachCondominium($subscriptionId, $condominiumId, $userId, $reason);
            $_SESSION['success'] = 'Condomínio desassociado com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao desassociar condomínio: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/subscriptions/view/' . $subscriptionId);
        exit;
    }

    /**
     * Recalculate licenses
     */
    public function recalculateLicenses()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $subscriptionId = (int)($_POST['subscription_id'] ?? 0);

        if (!$subscriptionId) {
            $_SESSION['error'] = 'Subscrição não especificada.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        try {
            $count = $this->subscriptionService->recalculateUsedLicenses($subscriptionId);
            $_SESSION['success'] = "Licenças recalculadas: {$count} licenças ativas.";
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao recalcular licenças: ' . $e->getMessage();
        }

        $redirect = $_POST['redirect'] ?? BASE_URL . 'admin/subscriptions/view/' . $subscriptionId;
        header('Location: ' . $redirect);
        exit;
    }

    /**
     * Lock/unlock condominium
     */
    public function toggleCondominiumLock()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $condominiumId = (int)($_POST['condominium_id'] ?? 0);
        $action = $_POST['action'] ?? 'lock';
        $reason = trim($_POST['reason'] ?? '');

        if (!$condominiumId) {
            $_SESSION['error'] = 'Condomínio não especificado.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $condominiumModel = new Condominium();
        $userId = AuthMiddleware::userId();

        try {
            if ($action === 'lock') {
                $condominiumModel->lock($condominiumId, $userId, $reason);
                $_SESSION['success'] = 'Condomínio bloqueado com sucesso.';
            } else {
                $condominiumModel->unlock($condominiumId);
                $_SESSION['success'] = 'Condomínio desbloqueado com sucesso.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro: ' . $e->getMessage();
        }

        $redirect = $_POST['redirect'] ?? BASE_URL . 'admin/subscriptions';
        header('Location: ' . $redirect);
        exit;
    }
}
