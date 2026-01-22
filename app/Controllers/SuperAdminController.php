<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Payment;
use App\Models\Condominium;
use App\Models\Plan;

class SuperAdminController extends Controller
{
    protected $userModel;
    protected $subscriptionModel;
    protected $paymentModel;
    protected $condominiumModel;
    protected $planModel;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
        $this->subscriptionModel = new Subscription();
        $this->paymentModel = new Payment();
        $this->condominiumModel = new Condominium();
        $this->planModel = new Plan();
    }

    /**
     * List all users
     */
    public function users()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        global $db;
        
        // Get users with their latest subscription
        $stmt = $db->query("
            SELECT u.*,
                   (SELECT COUNT(*) FROM condominiums WHERE user_id = u.id) as total_condominiums
            FROM users u
            WHERE u.role != 'super_admin'
            ORDER BY u.created_at DESC
        ");
        
        $users = $stmt->fetchAll() ?: [];
        
        // Get subscription info and condominiums for each user
        foreach ($users as &$user) {
            $subStmt = $db->prepare("
                SELECT s.*, p.name as plan_name, p.slug as plan_slug
                FROM subscriptions s
                INNER JOIN plans p ON p.id = s.plan_id
                WHERE s.user_id = :user_id
                ORDER BY s.created_at DESC
                LIMIT 1
            ");
            $subStmt->execute([':user_id' => $user['id']]);
            $subscription = $subStmt->fetch();
            
            if ($subscription) {
                $user['subscription_id'] = $subscription['id'];
                $user['subscription_status'] = $subscription['status'];
                $user['plan_name'] = $subscription['plan_name'];
            } else {
                $user['subscription_id'] = null;
                $user['subscription_status'] = null;
                $user['plan_name'] = null;
            }
            
            // Get condominiums for admin users
            if ($user['role'] === 'admin') {
                $condoStmt = $db->prepare("
                    SELECT id, name, is_active
                    FROM condominiums
                    WHERE user_id = :user_id
                    ORDER BY name ASC
                ");
                $condoStmt->execute([':user_id' => $user['id']]);
                $user['condominiums'] = $condoStmt->fetchAll() ?: [];
            } else {
                $user['condominiums'] = [];
            }
        }
        unset($user);

        // Get all plans for filter
        $plans = $this->planModel->getActivePlans();
        
        // Get unique roles and statuses for filters
        $roles = ['admin', 'condomino'];
        $statuses = ['active', 'suspended', 'inactive'];

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/admin/users/index.html.twig',
            'page' => ['titulo' => 'Gestão de Utilizadores'],
            'users' => $users,
            'plans' => $plans,
            'roles' => $roles,
            'statuses' => $statuses,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * List all subscriptions
     */
    public function subscriptions()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        global $db;
        
        $stmt = $db->query("
            SELECT s.*, 
                   u.name as user_name,
                   u.email as user_email,
                   p.name as plan_name,
                   p.slug as plan_slug
            FROM subscriptions s
            INNER JOIN users u ON u.id = s.user_id
            INNER JOIN plans p ON p.id = s.plan_id
            ORDER BY s.created_at DESC
        ");
        
        $subscriptions = $stmt->fetchAll() ?: [];

        // Get all plans for plan change dropdown
        $plans = $this->planModel->getActivePlans();

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/admin/subscriptions/index.html.twig',
            'page' => ['titulo' => 'Gestão de Subscrições'],
            'subscriptions' => $subscriptions,
            'plans' => $plans,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * List condominiums by admin
     */
    public function condominiums()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        global $db;
        
        $stmt = $db->query("
            SELECT c.*, 
                   u.name as admin_name,
                   u.email as admin_email
            FROM condominiums c
            INNER JOIN users u ON u.id = c.user_id
            ORDER BY c.created_at DESC
        ");
        
        $condominiums = $stmt->fetchAll() ?: [];
        
        // Add statistics for each condominium
        foreach ($condominiums as &$condominium) {
            $id = $condominium['id'];
            
            // Basic stats
            $fracStmt = $db->prepare("SELECT COUNT(*) as count FROM fractions WHERE condominium_id = :id");
            $fracStmt->execute([':id' => $id]);
            $fracResult = $fracStmt->fetch();
            $condominium['total_fractions'] = (int)($fracResult['count'] ?? 0);
            
            $resStmt = $db->prepare("SELECT COUNT(DISTINCT user_id) as count FROM condominium_users WHERE condominium_id = :id AND (ended_at IS NULL OR ended_at > CURDATE())");
            $resStmt->execute([':id' => $id]);
            $resResult = $resStmt->fetch();
            $condominium['total_residents'] = (int)($resResult['count'] ?? 0);
            
            // Activity stats (last 30 days)
            $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
            
            // Messages (last 30 days)
            $msgStmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE condominium_id = :id AND created_at >= :date");
            $msgStmt->execute([':id' => $id, ':date' => $thirtyDaysAgo]);
            $msgResult = $msgStmt->fetch();
            $condominium['recent_messages'] = (int)($msgResult['count'] ?? 0);
            
            // Total messages
            $msgTotalStmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE condominium_id = :id");
            $msgTotalStmt->execute([':id' => $id]);
            $msgTotalResult = $msgTotalStmt->fetch();
            $condominium['total_messages'] = (int)($msgTotalResult['count'] ?? 0);
            
            // Occurrences (last 30 days)
            $occStmt = $db->prepare("SELECT COUNT(*) as count FROM occurrences WHERE condominium_id = :id AND created_at >= :date");
            $occStmt->execute([':id' => $id, ':date' => $thirtyDaysAgo]);
            $occResult = $occStmt->fetch();
            $condominium['recent_occurrences'] = (int)($occResult['count'] ?? 0);
            
            // Total occurrences
            $occTotalStmt = $db->prepare("SELECT COUNT(*) as count FROM occurrences WHERE condominium_id = :id");
            $occTotalStmt->execute([':id' => $id]);
            $occTotalResult = $occTotalStmt->fetch();
            $condominium['total_occurrences'] = (int)($occTotalResult['count'] ?? 0);
            
            // Documents (last 30 days)
            $docStmt = $db->prepare("SELECT COUNT(*) as count FROM documents WHERE condominium_id = :id AND created_at >= :date");
            $docStmt->execute([':id' => $id, ':date' => $thirtyDaysAgo]);
            $docResult = $docStmt->fetch();
            $condominium['recent_documents'] = (int)($docResult['count'] ?? 0);
            
            // Total documents
            $docTotalStmt = $db->prepare("SELECT COUNT(*) as count FROM documents WHERE condominium_id = :id");
            $docTotalStmt->execute([':id' => $id]);
            $docTotalResult = $docTotalStmt->fetch();
            $condominium['total_documents'] = (int)($docTotalResult['count'] ?? 0);
            
            // Reservations (last 30 days)
            $resStmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE condominium_id = :id AND created_at >= :date");
            $resStmt->execute([':id' => $id, ':date' => $thirtyDaysAgo]);
            $resResult = $resStmt->fetch();
            $condominium['recent_reservations'] = (int)($resResult['count'] ?? 0);
            
            // Total reservations
            $resTotalStmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE condominium_id = :id");
            $resTotalStmt->execute([':id' => $id]);
            $resTotalResult = $resTotalStmt->fetch();
            $condominium['total_reservations'] = (int)($resTotalResult['count'] ?? 0);
            
            // Assemblies (last 30 days)
            $asmStmt = $db->prepare("SELECT COUNT(*) as count FROM assemblies WHERE condominium_id = :id AND created_at >= :date");
            $asmStmt->execute([':id' => $id, ':date' => $thirtyDaysAgo]);
            $asmResult = $asmStmt->fetch();
            $condominium['recent_assemblies'] = (int)($asmResult['count'] ?? 0);
            
            // Total assemblies
            $asmTotalStmt = $db->prepare("SELECT COUNT(*) as count FROM assemblies WHERE condominium_id = :id");
            $asmTotalStmt->execute([':id' => $id]);
            $asmTotalResult = $asmTotalStmt->fetch();
            $condominium['total_assemblies'] = (int)($asmTotalResult['count'] ?? 0);
            
            // Calculate activity score (weighted sum)
            // Recent activity gets more weight
            $condominium['activity_score'] = 
                ($condominium['recent_messages'] * 2) +
                ($condominium['recent_occurrences'] * 3) +
                ($condominium['recent_documents'] * 1) +
                ($condominium['recent_reservations'] * 1) +
                ($condominium['recent_assemblies'] * 2) +
                ($condominium['total_messages'] * 0.1) +
                ($condominium['total_occurrences'] * 0.2) +
                ($condominium['total_documents'] * 0.05) +
                ($condominium['total_reservations'] * 0.05) +
                ($condominium['total_assemblies'] * 0.1);
        }
        unset($condominium);
        
        // Sort by activity score
        usort($condominiums, function($a, $b) {
            return $b['activity_score'] <=> $a['activity_score'];
        });
        
        // Get top 20 for chart
        $top20 = array_slice($condominiums, 0, 20);
        $chartData = [
            'labels' => array_column($top20, 'name'),
            'activity_scores' => array_column($top20, 'activity_score'),
            'recent_messages' => array_column($top20, 'recent_messages'),
            'recent_occurrences' => array_column($top20, 'recent_occurrences'),
            'recent_documents' => array_column($top20, 'recent_documents'),
        ];

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/admin/condominiums/index.html.twig',
            'page' => ['titulo' => 'Gestão de Condomínios'],
            'condominiums' => $condominiums,
            'top20' => $top20,
            'chart_data' => $chartData,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show condominium statistics
     */
    public function condominiumStats(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        global $db;
        
        $condominium = $this->condominiumModel->findById($id);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'admin/condominiums');
            exit;
        }

        // Get admin info
        $admin = $this->userModel->findById($condominium['user_id']);
        if ($admin) {
            $condominium['admin_name'] = $admin['name'];
            $condominium['admin_email'] = $admin['email'];
        }

        // Get statistics
        $stats = $this->getCondominiumStatistics($id);

        $this->loadPageTranslations('dashboard');
        
        // Check if it's a modal request (via AJAX or modal parameter)
        $isModal = isset($_GET['modal']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
        
        if ($isModal) {
            // Return only the content without main template
            // BASE_URL is already a global Twig variable, so it's available
            $modalData = [
                'condominium' => $condominium,
                'stats' => $stats,
                'is_modal' => true,
                'BASE_URL' => BASE_URL
            ];
            
            echo $GLOBALS['twig']->render('pages/admin/condominiums/stats.html.twig', $modalData);
        } else {
            // Return full page with template
            $this->data += [
                'viewName' => 'pages/admin/condominiums/stats.html.twig',
                'page' => ['titulo' => 'Estatísticas do Condomínio'],
                'condominium' => $condominium,
                'stats' => $stats,
                'csrf_token' => Security::generateCSRFToken(),
                'user' => AuthMiddleware::user()
            ];

            echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
        }
    }

    /**
     * Activate subscription manually
     */
    public function activateSubscription()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

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
        $months = (int)($_POST['months'] ?? 1);

        if ($subscriptionId <= 0 || $months <= 0) {
            $_SESSION['error'] = 'Dados inválidos.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            $_SESSION['error'] = 'Subscrição não encontrada.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $oldStatus = $subscription['status'];
        $currentPeriodStart = date('Y-m-d H:i:s');
        $currentPeriodEnd = date('Y-m-d H:i:s', strtotime("+{$months} months"));

        $success = $this->subscriptionModel->update($subscriptionId, [
            'status' => 'active',
            'current_period_start' => $currentPeriodStart,
            'current_period_end' => $currentPeriodEnd
        ]);

        if ($success) {
            // Log audit
            $this->logAudit([
                'action' => 'subscription_activated',
                'model' => 'subscription',
                'model_id' => $subscriptionId,
                'description' => "Subscrição ativada manualmente. Status: {$oldStatus} → active. Período: {$currentPeriodStart} até {$currentPeriodEnd} ({$months} meses)"
            ]);

            $_SESSION['success'] = "Subscrição ativada com sucesso até " . date('d/m/Y', strtotime($currentPeriodEnd)) . ".";
        } else {
            $_SESSION['error'] = 'Erro ao ativar subscrição.';
        }

        header('Location: ' . BASE_URL . 'admin/subscriptions');
        exit;
    }

    /**
     * Change subscription plan
     */
    public function changePlan()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

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
        $newPlanId = (int)($_POST['plan_id'] ?? 0);

        if ($subscriptionId <= 0 || $newPlanId <= 0) {
            $_SESSION['error'] = 'Dados inválidos.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            $_SESSION['error'] = 'Subscrição não encontrada.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $oldPlan = $this->planModel->findById($subscription['plan_id']);
        $newPlan = $this->planModel->findById($newPlanId);
        
        if (!$newPlan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $oldPlanId = $subscription['plan_id'];
        $oldPlanName = $oldPlan['name'] ?? 'N/A';
        $newPlanName = $newPlan['name'];

        $success = $this->subscriptionModel->update($subscriptionId, [
            'plan_id' => $newPlanId
        ]);

        if ($success) {
            // Log audit
            $this->logAudit([
                'action' => 'subscription_plan_changed',
                'model' => 'subscription',
                'model_id' => $subscriptionId,
                'description' => "Plano alterado: {$oldPlanName} (ID: {$oldPlanId}) → {$newPlanName} (ID: {$newPlanId})"
            ]);

            $_SESSION['success'] = "Plano alterado de {$oldPlanName} para {$newPlanName} com sucesso.";
        } else {
            $_SESSION['error'] = 'Erro ao alterar plano.';
        }

        header('Location: ' . BASE_URL . 'admin/subscriptions');
        exit;
    }

    /**
     * Deactivate subscription
     */
    public function deactivateSubscription()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

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
        $reason = Security::sanitize($_POST['reason'] ?? 'Desativada manualmente pelo super admin');

        if ($subscriptionId <= 0) {
            $_SESSION['error'] = 'Dados inválidos.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $subscription = $this->subscriptionModel->findById($subscriptionId);
        if (!$subscription) {
            $_SESSION['error'] = 'Subscrição não encontrada.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        $oldStatus = $subscription['status'];
        $plan = $this->planModel->findById($subscription['plan_id']);

        $success = $this->subscriptionModel->update($subscriptionId, [
            'status' => 'suspended',
            'canceled_at' => date('Y-m-d H:i:s')
        ]);

        if ($success) {
            // Log audit
            $this->logAudit([
                'action' => 'subscription_deactivated',
                'model' => 'subscription',
                'model_id' => $subscriptionId,
                'description' => "Subscrição desativada. Status: {$oldStatus} → suspended. Motivo: {$reason}. Plano: " . ($plan['name'] ?? 'N/A')
            ]);

            $_SESSION['success'] = "Subscrição desativada com sucesso.";
        } else {
            $_SESSION['error'] = 'Erro ao desativar subscrição.';
        }

        header('Location: ' . BASE_URL . 'admin/subscriptions');
        exit;
    }

    /**
     * View payments
     */
    public function payments()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        global $db;
        
        $stmt = $db->query("
            SELECT p.*, 
                   u.name as user_name,
                   u.email as user_email,
                   s.plan_id,
                   pl.name as plan_name
            FROM payments p
            INNER JOIN users u ON u.id = p.user_id
            LEFT JOIN subscriptions s ON s.id = p.subscription_id
            LEFT JOIN plans pl ON pl.id = s.plan_id
            ORDER BY p.created_at DESC
            LIMIT 100
        ");
        
        $payments = $stmt->fetchAll() ?: [];

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/admin/payments/index.html.twig',
            'page' => ['titulo' => 'Consulta de Pagamentos'],
            'payments' => $payments,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Get condominium statistics
     */
    protected function getCondominiumStatistics(int $condominiumId): array
    {
        global $db;
        
        $stats = [];

        // Total fractions
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM fractions WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_fractions'] = (int)($result['count'] ?? 0);
        
        // Active fractions
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM fractions WHERE condominium_id = :id AND is_active = TRUE");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['active_fractions'] = (int)($result['count'] ?? 0);

        // Total residents
        $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) as count FROM condominium_users WHERE condominium_id = :id AND (ended_at IS NULL OR ended_at > CURDATE())");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_residents'] = (int)($result['count'] ?? 0);

        // Messages count
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_messages'] = (int)($result['count'] ?? 0);

        // Notifications count
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_notifications'] = (int)($result['count'] ?? 0);

        // Receipts count
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM receipts WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_receipts'] = (int)($result['count'] ?? 0);

        // Total documents
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM documents WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_documents'] = (int)($result['count'] ?? 0);

        // Minutes count
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM documents WHERE condominium_id = :id AND document_type = 'minutes'");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_minutes'] = (int)($result['count'] ?? 0);

        // Total occurrences
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM occurrences WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_occurrences'] = (int)($result['count'] ?? 0);

        // Open occurrences
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM occurrences WHERE condominium_id = :id AND status IN ('open', 'in_analysis', 'assigned')");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['open_occurrences'] = (int)($result['count'] ?? 0);

        // Completed occurrences
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM occurrences WHERE condominium_id = :id AND status = 'completed'");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['completed_occurrences'] = (int)($result['count'] ?? 0);

        // Total assemblies
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM assemblies WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_assemblies'] = (int)($result['count'] ?? 0);

        // Total suppliers
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM suppliers WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_suppliers'] = (int)($result['count'] ?? 0);

        // Total reservations
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_reservations'] = (int)($result['count'] ?? 0);

        // Active reservations
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE condominium_id = :id AND end_date >= CURDATE()");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['active_reservations'] = (int)($result['count'] ?? 0);

        // Total spaces
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM spaces WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_spaces'] = (int)($result['count'] ?? 0);

        // Total budgets
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM budgets WHERE condominium_id = :id");
        $stmt->execute([':id' => $condominiumId]);
        $result = $stmt->fetch();
        $stats['total_budgets'] = (int)($result['count'] ?? 0);

        // Storage space
        $storagePath = __DIR__ . '/../../storage/condominiums/' . $condominiumId;
        $stats['storage_space'] = $this->calculateDirectorySize($storagePath);
        $stats['storage_space_formatted'] = $this->formatBytes($stats['storage_space']);

        return $stats;
    }

    /**
     * Calculate directory size recursively
     */
    protected function calculateDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Log audit entry
     */
    protected function logAudit(array $data): void
    {
        global $db;
        
        if (!$db) {
            return;
        }

        $userId = AuthMiddleware::userId();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $db->prepare("
            INSERT INTO audit_logs (
                user_id, action, model, model_id, description, ip_address, user_agent
            )
            VALUES (
                :user_id, :action, :model, :model_id, :description, :ip_address, :user_agent
            )
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $data['action'],
            ':model' => $data['model'] ?? null,
            ':model_id' => $data['model_id'] ?? null,
            ':description' => $data['description'] ?? null,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
    }
}
