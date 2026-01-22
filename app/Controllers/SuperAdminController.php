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
use App\Services\AuditService;

class SuperAdminController extends Controller
{
    protected $userModel;
    protected $subscriptionModel;
    protected $paymentModel;
    protected $condominiumModel;
    protected $planModel;
    protected $auditService;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
        $this->subscriptionModel = new Subscription();
        $this->paymentModel = new Payment();
        $this->condominiumModel = new Condominium();
        $this->planModel = new Plan();
        $this->auditService = new AuditService();
    }

    /**
     * List all users
     */
    public function users()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        global $db;
        
        // Get all users including super admins
        $stmt = $db->query("
            SELECT u.*,
                   (SELECT COUNT(*) FROM condominiums WHERE user_id = u.id) as total_condominiums
            FROM users u
            ORDER BY 
                CASE WHEN u.role = 'super_admin' THEN 0 ELSE 1 END,
                u.created_at DESC
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
        $roles = ['super_admin', 'admin', 'condomino'];
        $statuses = ['active', 'suspended', 'inactive'];
        
        // Get current user ID to prevent self-demotion
        $currentUserId = AuthMiddleware::userId();

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/admin/users/index.html.twig',
            'page' => ['titulo' => 'Gestão de Utilizadores'],
            'users' => $users,
            'plans' => $plans,
            'roles' => $roles,
            'statuses' => $statuses,
            'current_user_id' => $currentUserId,
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
            // Log audit to subscription audit table
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => $subscription['user_id'],
                'action' => 'subscription_activated',
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'old_period_start' => $subscription['current_period_start'] ?? null,
                'new_period_start' => $currentPeriodStart,
                'old_period_end' => $subscription['current_period_end'] ?? null,
                'new_period_end' => $currentPeriodEnd,
                'description' => "Subscrição ativada manualmente pelo super admin. Período: {$currentPeriodStart} até {$currentPeriodEnd} ({$months} meses)"
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
            // Log audit to subscription audit table
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => $subscription['user_id'],
                'action' => 'subscription_plan_changed',
                'old_plan_id' => $oldPlanId,
                'new_plan_id' => $newPlanId,
                'old_status' => $subscription['status'],
                'new_status' => $subscription['status'], // Status doesn't change on plan change
                'description' => "Plano alterado pelo super admin: {$oldPlanName} (ID: {$oldPlanId}) → {$newPlanName} (ID: {$newPlanId})"
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
            // Log audit to subscription audit table
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => $subscription['user_id'],
                'action' => 'subscription_deactivated',
                'old_status' => $oldStatus,
                'new_status' => 'suspended',
                'old_period_end' => $subscription['current_period_end'] ?? null,
                'description' => "Subscrição desativada pelo super admin. Motivo: {$reason}. Plano: " . ($plan['name'] ?? 'N/A')
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
     * Assign super admin role to a user
     */
    public function assignSuperAdmin()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $currentUserId = AuthMiddleware::userId();

        if ($targetUserId <= 0) {
            $_SESSION['error'] = 'ID de utilizador inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        // Prevent self-demotion - critical security check
        if ($targetUserId === $currentUserId) {
            $_SESSION['error'] = 'Não pode remover o seu próprio cargo de super admin. Esta ação é bloqueada por segurança.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        $targetUser = $this->userModel->findById($targetUserId);
        if (!$targetUser) {
            $_SESSION['error'] = 'Utilizador não encontrado.';
            // Log attempt to assign to non-existent user
            $this->logAudit([
                'action' => 'super_admin_assignment_attempt_failed',
                'model' => 'user',
                'model_id' => $targetUserId,
                'description' => "Tentativa de atribuir cargo de super admin falhou: Utilizador não encontrado (ID: {$targetUserId})"
            ]);
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        global $db;
        
        try {
            $db->beginTransaction();
            
            $oldRole = $targetUser['role'];
            $currentUser = AuthMiddleware::user();
            
            // Update target user to super_admin
            $stmt = $db->prepare("UPDATE users SET role = 'super_admin' WHERE id = :id");
            $stmt->execute([':id' => $targetUserId]);
            
            // Log audit with detailed information
            $this->logAudit([
                'action' => 'super_admin_assigned',
                'model' => 'user',
                'model_id' => $targetUserId,
                'description' => "Cargo de super admin atribuído a {$targetUser['name']} ({$targetUser['email']}, ID: {$targetUserId}). Role anterior: {$oldRole} → Novo role: super_admin. Ação realizada por: {$currentUser['name']} ({$currentUser['email']}, ID: {$currentUserId})"
            ]);
            
            $db->commit();
            
            $_SESSION['success'] = "Cargo de super admin atribuído com sucesso a {$targetUser['name']}.";
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error assigning super admin: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao atribuir cargo de super admin.';
            // Log error
            $this->logAudit([
                'action' => 'super_admin_assignment_error',
                'model' => 'user',
                'model_id' => $targetUserId,
                'description' => "Erro ao atribuir cargo de super admin a {$targetUser['name']} ({$targetUser['email']}): " . $e->getMessage()
            ]);
        }

        header('Location: ' . BASE_URL . 'admin/users');
        exit;
    }

    /**
     * Remove super admin role from a user
     */
    public function removeSuperAdmin()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $currentUserId = AuthMiddleware::userId();
        $newRole = Security::sanitize($_POST['new_role'] ?? 'admin');

        if ($targetUserId <= 0) {
            $_SESSION['error'] = 'ID de utilizador inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        // Validate new role first
        if (!in_array($newRole, ['admin', 'user', 'condomino'])) {
            $_SESSION['error'] = 'Role inválido.';
            // Log invalid role attempt
            $this->logAudit([
                'action' => 'super_admin_removal_attempt_failed',
                'model' => 'user',
                'model_id' => $targetUserId,
                'description' => "Tentativa de remover cargo de super admin falhou: Role inválido fornecido ({$newRole}). Role deve ser: admin, user ou condomino"
            ]);
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        $targetUser = $this->userModel->findById($targetUserId);
        if (!$targetUser) {
            $_SESSION['error'] = 'Utilizador não encontrado.';
            // Log attempt to remove non-existent user
            $this->logAudit([
                'action' => 'super_admin_removal_attempt_failed',
                'model' => 'user',
                'model_id' => $targetUserId,
                'description' => "Tentativa de remover cargo de super admin falhou: Utilizador não encontrado (ID: {$targetUserId})"
            ]);
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        if ($targetUser['role'] !== 'super_admin') {
            $_SESSION['error'] = 'Este utilizador não é super admin.';
            // Log attempt to remove super admin from non-super-admin user
            $this->logAudit([
                'action' => 'super_admin_removal_attempt_failed',
                'model' => 'user',
                'model_id' => $targetUserId,
                'description' => "Tentativa de remover cargo de super admin falhou: Utilizador {$targetUser['name']} ({$targetUser['email']}) não é super admin (role atual: {$targetUser['role']})"
            ]);
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        // Prevent self-demotion - critical security check
        if ($targetUserId === $currentUserId) {
            $_SESSION['error'] = 'Não pode remover o seu próprio cargo de super admin. Esta ação é bloqueada por segurança.';
            // Log blocked self-demotion attempt
            $currentUser = AuthMiddleware::user();
            $this->logAudit([
                'action' => 'super_admin_self_removal_blocked',
                'model' => 'user',
                'model_id' => $currentUserId,
                'description' => "Tentativa de auto-remoção de super admin bloqueada: {$currentUser['name']} ({$currentUser['email']}) tentou remover o seu próprio cargo. Ação bloqueada por segurança."
            ]);
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        global $db;
        
        try {
            $db->beginTransaction();
            
            $oldRole = $targetUser['role'];
            $currentUser = AuthMiddleware::user();
            
            // Update target user role
            $stmt = $db->prepare("UPDATE users SET role = :role WHERE id = :id");
            $stmt->execute([':id' => $targetUserId, ':role' => $newRole]);
            
            // Log audit with detailed information
            $this->logAudit([
                'action' => 'super_admin_removed',
                'model' => 'user',
                'model_id' => $targetUserId,
                'description' => "Cargo de super admin removido de {$targetUser['name']} ({$targetUser['email']}, ID: {$targetUserId}). Role anterior: {$oldRole} → Novo role: {$newRole}. Ação realizada por: {$currentUser['name']} ({$currentUser['email']}, ID: {$currentUserId})"
            ]);
            
            $db->commit();
            
            $_SESSION['success'] = "Cargo de super admin removido com sucesso. {$targetUser['name']} agora tem role: {$newRole}.";
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error removing super admin: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao remover cargo de super admin.';
            // Log error
            $this->logAudit([
                'action' => 'super_admin_removal_error',
                'model' => 'user',
                'model_id' => $targetUserId,
                'description' => "Erro ao remover cargo de super admin de {$targetUser['name']} ({$targetUser['email']}): " . $e->getMessage()
            ]);
        }

        header('Location: ' . BASE_URL . 'admin/users');
        exit;
    }

    /**
     * View audit logs
     */
    public function auditLogs()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        global $db;
        
        // Get filter parameters
        $auditTypeFilter = $_GET['audit_type'] ?? 'all'; // 'all', 'general', 'payments', 'financial', 'subscriptions', 'documents'
        $actionFilter = $_GET['action'] ?? '';
        $modelFilter = $_GET['model'] ?? '';
        $userIdFilter = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
        
        // Default to today if no date filters provided
        $hasDateFilter = isset($_GET['date_from']) || isset($_GET['date_to']);
        $dateFrom = $_GET['date_from'] ?? ($hasDateFilter ? '' : date('Y-m-d'));
        $dateTo = $_GET['date_to'] ?? ($hasDateFilter ? '' : date('Y-m-d'));
        
        $search = $_GET['search'] ?? '';
        $page = isset($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) && $_GET['per_page'] > 0 ? (int)$_GET['per_page'] : 50;
        $sortBy = $_GET['sort_by'] ?? 'created_at';
        $sortOrder = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
        
        // Build where conditions
        $whereConditions = [];
        $params = [];
        
        if (!empty($actionFilter)) {
            $whereConditions[] = "action = :action";
            $params[':action'] = $actionFilter;
        }
        
        if (!empty($modelFilter)) {
            $whereConditions[] = "model = :model";
            $params[':model'] = $modelFilter;
        }
        
        if ($userIdFilter !== null) {
            $whereConditions[] = "user_id = :user_id";
            $params[':user_id'] = $userIdFilter;
        }
        
        // Optimized date filtering
        if (!empty($dateFrom)) {
            $whereConditions[] = "created_at >= :date_from_start";
            $params[':date_from_start'] = $dateFrom . ' 00:00:00';
        }
        
        if (!empty($dateTo)) {
            $whereConditions[] = "created_at <= :date_to_end";
            $params[':date_to_end'] = $dateTo . ' 23:59:59';
        }
        
        // Search in description
        if (!empty($search)) {
            $whereConditions[] = "description LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Validate sort column
        $allowedSortColumns = ['created_at', 'action', 'model', 'user_id'];
        if (!in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'created_at';
        }
        
        // Build UNION query for all audit tables with normalized columns
        $unionQueries = [];
        
        // General audit logs
        if ($auditTypeFilter === 'all' || $auditTypeFilter === 'general') {
            $unionQueries[] = "
                SELECT 
                    id,
                    user_id,
                    action,
                    model,
                    model_id,
                    description,
                    ip_address,
                    user_agent,
                    created_at,
                    'general' as audit_type,
                    NULL as amount,
                    NULL as payment_method,
                    NULL as status,
                    NULL as subscription_id,
                    NULL as payment_id
                FROM audit_logs
                {$whereClause}
            ";
        }
        
        // Payment audits
        if ($auditTypeFilter === 'all' || $auditTypeFilter === 'payments') {
            $unionQueries[] = "
                SELECT 
                    id,
                    user_id,
                    action,
                    'payment' as model,
                    payment_id as model_id,
                    description,
                    ip_address,
                    user_agent,
                    created_at,
                    'payments' as audit_type,
                    amount,
                    payment_method,
                    status,
                    subscription_id,
                    payment_id
                FROM audit_payments
                {$whereClause}
            ";
        }
        
        // Financial audits
        if ($auditTypeFilter === 'all' || $auditTypeFilter === 'financial') {
            $unionQueries[] = "
                SELECT 
                    id,
                    user_id,
                    action,
                    entity_type as model,
                    entity_id as model_id,
                    description,
                    ip_address,
                    user_agent,
                    created_at,
                    'financial' as audit_type,
                    amount,
                    NULL as payment_method,
                    new_status as status,
                    NULL as subscription_id,
                    NULL as payment_id
                FROM audit_financial
                {$whereClause}
            ";
        }
        
        // Subscription audits
        if ($auditTypeFilter === 'all' || $auditTypeFilter === 'subscriptions') {
            $unionQueries[] = "
                SELECT 
                    id,
                    user_id,
                    action,
                    'subscription' as model,
                    subscription_id as model_id,
                    description,
                    ip_address,
                    user_agent,
                    created_at,
                    'subscriptions' as audit_type,
                    NULL as amount,
                    NULL as payment_method,
                    new_status as status,
                    subscription_id,
                    NULL as payment_id
                FROM audit_subscriptions
                {$whereClause}
            ";
        }
        
        // Document audits
        if ($auditTypeFilter === 'all' || $auditTypeFilter === 'documents') {
            $unionQueries[] = "
                SELECT 
                    id,
                    user_id,
                    action,
                    document_type as model,
                    document_id as model_id,
                    description,
                    ip_address,
                    user_agent,
                    created_at,
                    'documents' as audit_type,
                    NULL as amount,
                    NULL as payment_method,
                    NULL as status,
                    NULL as subscription_id,
                    NULL as payment_id
                FROM audit_documents
                {$whereClause}
            ";
        }
        
        if (empty($unionQueries)) {
            $unionQueries[] = "SELECT NULL as id, NULL as user_id, NULL as action, NULL as model, NULL as model_id, NULL as description, NULL as ip_address, NULL as user_agent, NULL as created_at, NULL as audit_type, NULL as amount, NULL as payment_method, NULL as status, NULL as subscription_id, NULL as payment_id WHERE 1=0";
        }
        
        // Count total records
        $countSql = "
            SELECT COUNT(*) as total FROM (
                " . implode(' UNION ALL ', $unionQueries) . "
            ) as combined_audits
        ";
        $countStmt = $db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetch()['total'];
        $totalPages = $totalCount > 0 ? ceil($totalCount / $perPage) : 1;
        
        // Ensure page is within valid range
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }
        
        // Main query with user join
        $offset = ($page - 1) * $perPage;
        $orderByClause = "combined_audits.{$sortBy} {$sortOrder}";
        
        $sql = "
            SELECT 
                combined_audits.*,
                u.name as user_name,
                u.email as user_email,
                u.role as user_role
            FROM (
                " . implode(' UNION ALL ', $unionQueries) . "
            ) as combined_audits
            LEFT JOIN users u ON u.id = combined_audits.user_id
            ORDER BY {$orderByClause}
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll() ?: [];
        
        // Enrich document logs with additional data
        foreach ($logs as &$log) {
            if ($log['audit_type'] === 'documents' && $log['id']) {
                // Get full document audit data
                $docStmt = $db->prepare("
                    SELECT document_type, document_id, assembly_id, receipt_id, fee_id, 
                           file_path, file_name, file_size, metadata
                    FROM audit_documents 
                    WHERE id = :id
                ");
                $docStmt->execute([':id' => $log['id']]);
                $docData = $docStmt->fetch();
                if ($docData) {
                    $log['document_type'] = $docData['document_type'];
                    $log['document_id'] = $docData['document_id'];
                    $log['assembly_id'] = $docData['assembly_id'];
                    $log['receipt_id'] = $docData['receipt_id'];
                    $log['fee_id'] = $docData['fee_id'];
                    $log['file_path'] = $docData['file_path'];
                    $log['file_name'] = $docData['file_name'];
                    $log['file_size'] = $docData['file_size'];
                    $log['metadata'] = $docData['metadata'] ? json_decode($docData['metadata'], true) : null;
                }
            }
        }
        unset($log);
        
        // Get unique actions and models from all audit tables (last 30 days)
        $recentDateLimit = date('Y-m-d', strtotime('-30 days'));
        
        // Get actions from all tables
        $actionsSql = "
            SELECT DISTINCT action FROM (
                SELECT action FROM audit_logs WHERE created_at >= :recent_date
                UNION
                SELECT action FROM audit_payments WHERE created_at >= :recent_date
                UNION
                SELECT action FROM audit_financial WHERE created_at >= :recent_date
                UNION
                SELECT action FROM audit_subscriptions WHERE created_at >= :recent_date
                UNION
                SELECT action FROM audit_documents WHERE created_at >= :recent_date
            ) as all_actions
            ORDER BY action
            LIMIT 200
        ";
        $actionsStmt = $db->prepare($actionsSql);
        $actionsStmt->execute([':recent_date' => $recentDateLimit . ' 00:00:00']);
        $actions = $actionsStmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // Get models from all tables
        $modelsSql = "
            SELECT DISTINCT model FROM (
                SELECT model FROM audit_logs WHERE model IS NOT NULL AND created_at >= :recent_date
                UNION
                SELECT 'payment' as model FROM audit_payments WHERE created_at >= :recent_date
                UNION
                SELECT entity_type as model FROM audit_financial WHERE created_at >= :recent_date
                UNION
                SELECT 'subscription' as model FROM audit_subscriptions WHERE created_at >= :recent_date
                UNION
                SELECT document_type as model FROM audit_documents WHERE created_at >= :recent_date
            ) as all_models
            WHERE model IS NOT NULL
            ORDER BY model
            LIMIT 100
        ";
        $modelsStmt = $db->prepare($modelsSql);
        $modelsStmt->execute([':recent_date' => $recentDateLimit . ' 00:00:00']);
        $models = $modelsStmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // Get users from all audit tables
        $usersSql = "
            SELECT DISTINCT u.id, u.name, u.email FROM (
                SELECT user_id FROM audit_logs WHERE created_at >= :recent_date AND user_id IS NOT NULL
                UNION
                SELECT user_id FROM audit_payments WHERE created_at >= :recent_date AND user_id IS NOT NULL
                UNION
                SELECT user_id FROM audit_financial WHERE created_at >= :recent_date AND user_id IS NOT NULL
                UNION
                SELECT user_id FROM audit_subscriptions WHERE created_at >= :recent_date AND user_id IS NOT NULL
                UNION
                SELECT user_id FROM audit_documents WHERE created_at >= :recent_date AND user_id IS NOT NULL
            ) as all_user_ids
            INNER JOIN users u ON u.id = all_user_ids.user_id
            ORDER BY u.name
            LIMIT 500
        ";
        $usersStmt = $db->prepare($usersSql);
        $usersStmt->execute([':recent_date' => $recentDateLimit . ' 00:00:00']);
        $users = $usersStmt->fetchAll() ?: [];
        
        // If a user is selected but not in recent users list, add it
        if ($userIdFilter !== null) {
            $userFound = false;
            foreach ($users as $user) {
                if ($user['id'] == $userIdFilter) {
                    $userFound = true;
                    break;
                }
            }
            
            if (!$userFound) {
                $selectedUserStmt = $db->prepare("SELECT id, name, email FROM users WHERE id = :user_id");
                $selectedUserStmt->execute([':user_id' => $userIdFilter]);
                $selectedUser = $selectedUserStmt->fetch();
                if ($selectedUser) {
                    array_unshift($users, $selectedUser);
                }
            }
        }

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/admin/audit-logs/index.html.twig',
            'page' => ['titulo' => 'Logs de Auditoria'],
            'logs' => $logs,
            'actions' => $actions,
            'models' => $models,
            'users' => $users,
            'filters' => [
                'audit_type' => $auditTypeFilter,
                'action' => $actionFilter,
                'model' => $modelFilter,
                'user_id' => $userIdFilter,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder
            ],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'per_page' => $perPage
            ],
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Search users for audit logs autocomplete
     */
    public function searchUsersForAudit()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        global $db;
        
        $query = $_GET['q'] ?? '';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        
        if (empty($query) || strlen($query) < 2) {
            header('Content-Type: application/json');
            echo json_encode([]);
            exit;
        }
        
        // Search users who have audit logs (from all audit tables)
        $searchTerm = '%' . $query . '%';
        
        // Optimized query: search users and check if they have audit logs
        // Using EXISTS for better performance than IN with UNION
        $sql = "
            SELECT DISTINCT u.id, u.name, u.email, u.role
            FROM users u
            WHERE (
                u.name LIKE :search 
                OR u.email LIKE :search
            )
            AND (
                EXISTS (SELECT 1 FROM audit_logs WHERE user_id = u.id)
                OR EXISTS (SELECT 1 FROM audit_payments WHERE user_id = u.id)
                OR EXISTS (SELECT 1 FROM audit_financial WHERE user_id = u.id)
                OR EXISTS (SELECT 1 FROM audit_subscriptions WHERE user_id = u.id)
            )
            ORDER BY u.name ASC
            LIMIT :limit
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':search', $searchTerm);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll() ?: [];
        
        // Format for autocomplete
        $results = [];
        foreach ($users as $user) {
            $results[] = [
                'id' => $user['id'],
                'text' => $user['name'] . ' (' . $user['email'] . ')',
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ];
        }
        
        header('Content-Type: application/json');
        echo json_encode($results);
        exit;
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
