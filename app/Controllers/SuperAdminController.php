<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\Condominium;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\PlanExtraCondominiumsPricing;
use App\Models\PlanPricingTier;
use App\Models\EmailTemplate;
use App\Services\AuditService;
use App\Services\PaymentService;
use App\Services\LogService;
use App\Services\CondominiumDeletionService;

class SuperAdminController extends Controller
{
    protected $userModel;
    protected $subscriptionModel;
    protected $paymentModel;
    protected $invoiceModel;
    protected $condominiumModel;
    protected $planModel;
    protected $promotionModel;
    protected $extraCondominiumsPricingModel;
    protected $planPricingTierModel;
    protected $emailTemplateModel;
    protected $auditService;
    protected $paymentService;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
        $this->subscriptionModel = new Subscription();
        $this->paymentModel = new Payment();
        $this->invoiceModel = new Invoice();
        $this->condominiumModel = new Condominium();
        $this->planModel = new Plan();
        $this->promotionModel = new Promotion();
        $this->extraCondominiumsPricingModel = new PlanExtraCondominiumsPricing();
        $this->planPricingTierModel = new PlanPricingTier();
        $this->emailTemplateModel = new EmailTemplate();
        $this->auditService = new AuditService();
        $this->paymentService = new PaymentService();
    }

    /**
     * List all users
     */
    public function users()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

        global $db;

        // Get all users including super admins with additional calculated fields
        $stmt = $db->query("
            SELECT u.*,
                   (SELECT COUNT(*) FROM condominiums WHERE user_id = u.id) as total_condominiums,
                   (SELECT COUNT(*) FROM condominiums WHERE user_id = u.id) as condominium_count_as_admin,
                   (SELECT COUNT(*) FROM condominium_users WHERE user_id = u.id AND (ended_at IS NULL OR ended_at > CURDATE())) as condominium_count_as_member
            FROM users u
            ORDER BY
                CASE WHEN u.role = 'super_admin' THEN 0 ELSE 1 END,
                u.created_at DESC
        ");

        $users = $stmt->fetchAll() ?: [];

        // Get subscription info and condominiums for each user
        foreach ($users as &$user) {
            // Check if user has only trial (no active subscription)
            $trialStmt = $db->prepare("
                SELECT COUNT(*) as trial_count
                FROM subscriptions
                WHERE user_id = :user_id AND status = 'trial'
            ");
            $trialStmt->execute([':user_id' => $user['id']]);
            $trialCount = (int)$trialStmt->fetch()['trial_count'];

            $activeStmt = $db->prepare("
                SELECT COUNT(*) as active_count
                FROM subscriptions
                WHERE user_id = :user_id AND status = 'active'
            ");
            $activeStmt->execute([':user_id' => $user['id']]);
            $activeCount = (int)$activeStmt->fetch()['active_count'];

            // User has only trial if has trial but no active subscriptions
            $user['has_only_trial'] = ($trialCount > 0 && $activeCount === 0 && $user['role'] === 'admin');

            // Check if user has no active plan (no active subscriptions)
            $user['has_no_active_plan'] = ($activeCount === 0 && $user['role'] === 'admin');

            // Check if user has no condominium (neither as owner nor as member)
            $user['has_no_condominium'] = (
                (int)$user['condominium_count_as_admin'] === 0 && 
                (int)$user['condominium_count_as_member'] === 0
            );

            // Check if email is not verified
            // Users with google_id are considered verified (Google verifies emails)
            $user['email_not_verified'] = empty($user['email_verified_at']) && empty($user['google_id']);

            // Get latest subscription info
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
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info'],
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

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

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

        // Get pending license additions for each subscription
        $invoiceModel = new \App\Models\Invoice();
        $pendingLicenseAdditions = [];

        foreach ($subscriptions as &$subscription) {
            $pendingInvoice = $invoiceModel->getPendingBySubscriptionId($subscription['id']);
            if ($pendingInvoice) {
                $metadata = null;
                if (isset($pendingInvoice['metadata']) && $pendingInvoice['metadata']) {
                    $metadata = is_string($pendingInvoice['metadata']) ? json_decode($pendingInvoice['metadata'], true) : $pendingInvoice['metadata'];
                } elseif (isset($pendingInvoice['notes']) && $pendingInvoice['notes']) {
                    $decoded = json_decode($pendingInvoice['notes'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $metadata = $decoded;
                    }
                }

                if ($metadata && isset($metadata['is_license_addition']) && $metadata['is_license_addition']) {
                    $pendingLicenseAdditions[$subscription['id']] = [
                        'invoice_id' => $pendingInvoice['id'],
                        'additional_licenses' => (int)($metadata['additional_licenses'] ?? 0),
                        'amount' => $pendingInvoice['amount']
                    ];
                }
            }
        }
        unset($subscription);

        // Get all plans for plan change dropdown (including inactive demo plan for testing)
        $activePlans = $this->planModel->getActivePlans();
        
        // Also include demo plan (even if inactive) for super admin testing purposes
        global $db;
        $demoPlanStmt = $db->prepare("
            SELECT * FROM plans 
            WHERE slug = 'demo' OR (limit_condominios = 2 AND is_active = FALSE)
            ORDER BY id ASC 
            LIMIT 1
        ");
        $demoPlanStmt->execute();
        $demoPlan = $demoPlanStmt->fetch();
        
        $plans = $activePlans;
        if ($demoPlan) {
            // Check if demo plan is not already in active plans
            $demoPlanExists = false;
            foreach ($activePlans as $plan) {
                if ($plan['id'] == $demoPlan['id']) {
                    $demoPlanExists = true;
                    break;
                }
            }
            if (!$demoPlanExists) {
                $plans[] = $demoPlan;
            }
        }

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/subscriptions/index.html.twig',
            'page' => ['titulo' => 'Gestão de Subscrições'],
            'subscriptions' => $subscriptions,
            'plans' => $plans,
            'pending_license_additions' => $pendingLicenseAdditions,
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info'],
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Approve pending license addition (process as free payment)
     */
    public function approveLicenseAddition()
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
        if (!$subscriptionId) {
            $_SESSION['error'] = 'Subscrição não especificada.';
            header('Location: ' . BASE_URL . 'admin/subscriptions');
            exit;
        }

        global $db;

        try {
            $db->beginTransaction();

            // Get subscription
            $subscription = $this->subscriptionModel->findById($subscriptionId);
            if (!$subscription) {
                throw new \Exception('Subscrição não encontrada.');
            }

            // Get pending invoice with license addition
            $invoiceModel = new \App\Models\Invoice();
            $pendingInvoice = $invoiceModel->getPendingBySubscriptionId($subscriptionId);

            if (!$pendingInvoice) {
                throw new \Exception('Não há pagamentos pendentes para esta subscrição.');
            }

            // Check metadata
            $metadata = null;
            if (isset($pendingInvoice['metadata']) && $pendingInvoice['metadata']) {
                $metadata = is_string($pendingInvoice['metadata']) ? json_decode($pendingInvoice['metadata'], true) : $pendingInvoice['metadata'];
            } elseif (isset($pendingInvoice['notes']) && $pendingInvoice['notes']) {
                $decoded = json_decode($pendingInvoice['notes'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $metadata = $decoded;
                }
            }

            if (!$metadata || !isset($metadata['is_license_addition']) || !$metadata['is_license_addition']) {
                throw new \Exception('Esta invoice não está relacionada com adição de frações extras.');
            }

            // Get plan details
            $plan = $this->planModel->findById($subscription['plan_id']);
            if (!$plan || empty($plan['plan_type'])) {
                throw new \Exception('Plano não encontrado ou inválido.');
            }

            // Extract metadata values
            $newExtraLicenses = (int)($metadata['new_extra_licenses'] ?? null);
            $newLicenseLimit = (int)($metadata['new_total_licenses'] ?? null);
            $additionalLicenses = (int)($metadata['additional_licenses'] ?? 0);

            if ($newExtraLicenses === null || $newLicenseLimit === null) {
                throw new \Exception('Dados incompletos na invoice.');
            }

            // Create a free payment record for audit purposes
            // Use 'transfer' as payment method since it's the closest to admin approval
            $paymentData = [
                'subscription_id' => $subscriptionId,
                'invoice_id' => $pendingInvoice['id'],
                'user_id' => $subscription['user_id'],
                'amount' => 0.00,
                'status' => 'completed',
                'payment_method' => 'transfer', // Using transfer as it's closest to admin approval
                'external_payment_id' => 'admin_approval_' . time() . '_' . $subscriptionId,
                'reference' => "Aprovação administrativa de {$additionalLicenses} frações extras (gratuito)",
                'metadata' => [
                    'admin_approved' => true,
                    'admin_user_id' => AuthMiddleware::userId(),
                    'approved_at' => date('Y-m-d H:i:s'),
                    'original_amount' => $pendingInvoice['amount']
                ]
            ];
            $paymentId = $this->paymentModel->create($paymentData);

            // Update processed_at since payment is completed
            $db->prepare("UPDATE payments SET processed_at = NOW() WHERE id = :id")->execute([':id' => $paymentId]);

            // Mark invoice as paid
            $invoiceModel->markAsPaid($pendingInvoice['id']);

            // Update subscription with new licenses
            // Note: price_monthly is not stored in subscriptions table, it's calculated from plan + tiers

            // Determine period start
            $periodStart = $subscription['current_period_end'] ?? date('Y-m-d H:i:s');
            if (!$subscription['current_period_end'] || strtotime($subscription['current_period_end']) < time()) {
                $periodStart = date('Y-m-d H:i:s');
            }
            $newPeriodEnd = date('Y-m-d H:i:s', strtotime('+1 month', strtotime($periodStart)));

            $updateData = [
                'extra_licenses' => $newExtraLicenses,
                'license_limit' => $newLicenseLimit,
                'current_period_start' => $periodStart,
                'current_period_end' => $newPeriodEnd
            ];

            // Ensure subscription is active
            if ($subscription['status'] !== 'active') {
                $updateData['status'] = 'active';
            }

            $this->subscriptionModel->update($subscriptionId, $updateData);

            // Log the approval
            $this->auditService->logSubscription([
                'subscription_id' => $subscriptionId,
                'user_id' => AuthMiddleware::userId(),
                'action' => 'extra_licenses_approved',
                'description' => "Aprovação administrativa: {$additionalLicenses} frações extras adicionadas gratuitamente (Total: {$subscription['license_limit']} → {$newLicenseLimit})"
            ]);

            // Log payment
            $this->auditService->logPayment([
                'payment_id' => $paymentId,
                'subscription_id' => $subscriptionId,
                'invoice_id' => $pendingInvoice['id'],
                'user_id' => $subscription['user_id'],
                'action' => 'admin_approval',
                'payment_method' => 'admin_approval',
                'amount' => 0.00,
                'status' => 'completed',
                'description' => "Aprovação administrativa de {$additionalLicenses} frações extras"
            ]);

            $db->commit();

            $_SESSION['success'] = "{$additionalLicenses} fração(ões) extra(s) aprovada(s) com sucesso!";
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error approving license addition: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao aprovar frações extras: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/subscriptions');
        exit;
    }

    /**
     * List pending payments for admin approval
     */
    public function payments()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

        global $db;

        // Get filter and search parameters
        $search = Security::sanitize(trim($_GET['search'] ?? ''));
        // Validate status against allowed values
        $allowedStatuses = ['pending', 'completed', 'failed', 'cancelled', 'refunded'];
        $statusInput = Security::sanitize($_GET['status'] ?? '');
        $statusFilter = in_array($statusInput, $allowedStatuses) ? $statusInput : '';
        // Validate method against allowed values
        $allowedMethods = ['card', 'mbway', 'bank_transfer', 'paypal'];
        $methodInput = Security::sanitize($_GET['method'] ?? '');
        $methodFilter = in_array($methodInput, $allowedMethods) ? $methodInput : '';
        // Validate type against allowed values
        $allowedTypes = ['subscription', 'one_time', 'trial'];
        $typeInput = Security::sanitize($_GET['type'] ?? '');
        $typeFilter = in_array($typeInput, $allowedTypes) ? $typeInput : '';
        // Validate sort column
        $allowedSortColumns = ['created_at', 'amount', 'status', 'method'];
        $sortInput = Security::sanitize($_GET['sort'] ?? 'created_at');
        $sortBy = in_array($sortInput, $allowedSortColumns) ? $sortInput : 'created_at';
        $sortOrderInput = strtoupper(Security::sanitize($_GET['order'] ?? 'DESC'));
        $sortOrder = in_array($sortOrderInput, ['ASC', 'DESC']) ? $sortOrderInput : 'DESC';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // Validate sort order
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }

        // Validate sort column
        $allowedSorts = ['id', 'created_at', 'amount', 'status', 'user_name', 'plan_name'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }

        // Check if metadata column exists in invoices table
        $checkStmt = $db->query("SHOW COLUMNS FROM invoices LIKE 'metadata'");
        $hasMetadataColumn = $checkStmt->rowCount() > 0;

        // Use metadata column if it exists, otherwise use notes (which stores JSON metadata)
        $metadataField = $hasMetadataColumn ? 'i.metadata' : 'i.notes';

        // Build WHERE clause
        $whereConditions = ["1=1"];
        $params = [];

        // Search by user name or email
        if ($search) {
            $whereConditions[] = "(u.name LIKE :search OR u.email LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        // Filter by status
        if ($statusFilter) {
            $whereConditions[] = "p.status = :status";
            $params[':status'] = $statusFilter;
        }

        // Filter by payment method
        if ($methodFilter) {
            $whereConditions[] = "p.payment_method = :method";
            $params[':method'] = $methodFilter;
        }

        // Filter by type (requires checking invoice metadata)
        if ($typeFilter) {
            if ($typeFilter === 'license_addition') {
                $whereConditions[] = "(" . $metadataField . " LIKE :type_license OR " . $metadataField . " LIKE :type_license2)";
                $params[':type_license'] = '%"is_license_addition":true%';
                $params[':type_license2'] = '%is_license_addition%';
            } elseif ($typeFilter === 'extra_update') {
                $whereConditions[] = "(" . $metadataField . " LIKE :type_extra OR " . $metadataField . " LIKE :type_extra2)";
                $params[':type_extra'] = '%"is_extra_update":true%';
                $params[':type_extra2'] = '%is_extra_update%';
            } elseif ($typeFilter === 'plan_change') {
                $whereConditions[] = "(" . $metadataField . " LIKE :type_plan OR " . $metadataField . " LIKE :type_plan2)";
                $params[':type_plan'] = '%"is_plan_change":true%';
                $params[':type_plan2'] = '%is_plan_change%';
            } elseif ($typeFilter === 'subscription') {
                // Subscription type: no special metadata flags
                $whereConditions[] = "(" . $metadataField . " IS NULL OR (" . $metadataField . " NOT LIKE '%is_license_addition%' AND " . $metadataField . " NOT LIKE '%is_extra_update%' AND " . $metadataField . " NOT LIKE '%is_plan_change%'))";
            }
        }

        // Build base query
        $baseSql = "SELECT p.*,
                   s.status as subscription_status,
                   s.plan_id,
                   u.name as user_name,
                   u.email as user_email,
                   pl.name as plan_name,
                   pl.slug as plan_slug,
                   i.amount as invoice_amount,
                   i.status as invoice_status,
                   " . $metadataField . " as invoice_metadata,
                   i.notes as invoice_notes
            FROM payments p
            INNER JOIN subscriptions s ON p.subscription_id = s.id
            INNER JOIN users u ON p.user_id = u.id
            INNER JOIN plans pl ON s.plan_id = pl.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            WHERE " . implode(' AND ', $whereConditions);

        // Get total count for pagination (simplified query without ORDER BY)
        $countSql = "SELECT COUNT(DISTINCT p.id) as total
            FROM payments p
            INNER JOIN subscriptions s ON p.subscription_id = s.id
            INNER JOIN users u ON p.user_id = u.id
            INNER JOIN plans pl ON s.plan_id = pl.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            WHERE " . implode(' AND ', $whereConditions);

        $countParams = [];
        foreach ($params as $key => $value) {
            // Skip type filter params that use LIKE with JSON
            if (strpos($key, ':type_') === 0 && $typeFilter) {
                continue; // Will handle separately
            }
            $countParams[$key] = $value;
        }

        // Re-add type filter conditions for count query
        if ($typeFilter) {
            if ($typeFilter === 'license_addition') {
                $countSql .= " AND (" . $metadataField . " LIKE :type_license OR " . $metadataField . " LIKE :type_license2)";
                $countParams[':type_license'] = '%"is_license_addition":true%';
                $countParams[':type_license2'] = '%is_license_addition%';
            } elseif ($typeFilter === 'extra_update') {
                $countSql .= " AND (" . $metadataField . " LIKE :type_extra OR " . $metadataField . " LIKE :type_extra2)";
                $countParams[':type_extra'] = '%"is_extra_update":true%';
                $countParams[':type_extra2'] = '%is_extra_update%';
            } elseif ($typeFilter === 'plan_change') {
                $countSql .= " AND (" . $metadataField . " LIKE :type_plan OR " . $metadataField . " LIKE :type_plan2)";
                $countParams[':type_plan'] = '%"is_plan_change":true%';
                $countParams[':type_plan2'] = '%is_plan_change%';
            } elseif ($typeFilter === 'subscription') {
                $countSql .= " AND (" . $metadataField . " IS NULL OR (" . $metadataField . " NOT LIKE '%is_license_addition%' AND " . $metadataField . " NOT LIKE '%is_extra_update%' AND " . $metadataField . " NOT LIKE '%is_plan_change%'))";
            }
        }

        $countStmt = $db->prepare($countSql);
        $countStmt->execute($countParams);
        $totalCount = (int)$countStmt->fetch()['total'];
        $totalPages = ceil($totalCount / $perPage);

        // Build ORDER BY clause
        $orderBy = "p." . $sortBy;
        if ($sortBy === 'user_name') {
            $orderBy = "u.name";
        } elseif ($sortBy === 'plan_name') {
            $orderBy = "pl.name";
        }

        // Build final query with sorting and pagination
        $sql = $baseSql . " ORDER BY " . $orderBy . " " . $sortOrder . " LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $payments = $stmt->fetchAll() ?: [];

        // Decode metadata for each payment/invoice
        foreach ($payments as &$payment) {
            // Decode payment metadata if exists
            if (isset($payment['metadata']) && $payment['metadata']) {
                $payment['metadata'] = is_string($payment['metadata']) ? json_decode($payment['metadata'], true) : $payment['metadata'];
            }

            // Decode invoice metadata if exists
            if (isset($payment['invoice_metadata']) && $payment['invoice_metadata']) {
                $payment['invoice_metadata'] = is_string($payment['invoice_metadata']) ? json_decode($payment['invoice_metadata'], true) : $payment['invoice_metadata'];
            } elseif (isset($payment['invoice_notes']) && $payment['invoice_notes']) {
                $decoded = json_decode($payment['invoice_notes'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payment['invoice_metadata'] = $decoded;
                }
            }
        }
        unset($payment);

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/payments/index.html.twig',
            'page' => ['titulo' => 'Gestão de Pagamentos'],
            'payments' => $payments,
            'search' => $search,
            'status_filter' => $statusFilter,
            'method_filter' => $methodFilter,
            'type_filter' => $typeFilter,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'per_page' => $perPage,
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info'],
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Approve payment manually (mark as completed and process)
     */
    public function approvePayment()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/payments');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/payments');
            exit;
        }

        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if (!$paymentId) {
            $_SESSION['error'] = 'Pagamento não especificado.';
            header('Location: ' . BASE_URL . 'admin/payments');
            exit;
        }

        $adminComment = trim($_POST['admin_comment'] ?? '');

        try {
            // Get payment
            $payment = $this->paymentModel->findById($paymentId);
            if (!$payment) {
                throw new \Exception('Pagamento não encontrado.');
            }

            if ($payment['status'] === 'completed') {
                $_SESSION['info'] = 'Este pagamento já foi processado.';
                header('Location: ' . BASE_URL . 'admin/payments');
                exit;
            }

            // Use PaymentService to confirm payment (this handles all the logic)
            // Generate a unique external payment ID for admin approval
            $externalPaymentId = 'admin_approval_' . time() . '_' . $paymentId;

            // Update external_payment_id if not set
            if (empty($payment['external_payment_id'])) {
                global $db;
                $db->prepare("UPDATE payments SET external_payment_id = :external_id WHERE id = :id")
                   ->execute([':external_id' => $externalPaymentId, ':id' => $paymentId]);
            } else {
                $externalPaymentId = $payment['external_payment_id'];
            }

            // Prepare metadata with admin approval info and comment
            $approvalMetadata = [
                'approved_by' => AuthMiddleware::userId(),
                'approved_at' => date('Y-m-d H:i:s'),
                'approval_type' => 'manual_admin',
                'admin_comment' => $adminComment
            ];

            // Update payment metadata with admin comment before confirming
            if ($adminComment) {
                global $db;
                $currentMetadata = [];
                if (!empty($payment['metadata'])) {
                    $currentMetadata = is_string($payment['metadata'])
                        ? json_decode($payment['metadata'], true)
                        : $payment['metadata'];
                }
                $currentMetadata['admin_approval'] = $approvalMetadata;

                $updateStmt = $db->prepare("UPDATE payments SET metadata = :metadata WHERE id = :id");
                $updateStmt->execute([
                    ':metadata' => json_encode($currentMetadata),
                    ':id' => $paymentId
                ]);
            }

            // Confirm payment using PaymentService (this processes everything)
            $result = $this->paymentService->confirmPayment($externalPaymentId, $approvalMetadata);

            if ($result) {
                // Build description with comment if provided
                $description = "Pagamento aprovado manualmente pelo superadmin";
                if ($adminComment) {
                    $description .= ". Comentário: " . $adminComment;
                }

                // Log admin approval with detailed information
                $this->auditService->logPayment([
                    'payment_id' => $paymentId,
                    'subscription_id' => $payment['subscription_id'],
                    'invoice_id' => $payment['invoice_id'],
                    'user_id' => $payment['user_id'],
                    'action' => 'admin_manual_approval',
                    'payment_method' => $payment['payment_method'] ?? 'admin_approval',
                    'amount' => $payment['amount'],
                    'status' => 'completed',
                    'description' => $description,
                    'metadata' => [
                        'admin_user_id' => AuthMiddleware::userId(),
                        'admin_comment' => $adminComment,
                        'approved_at' => date('Y-m-d H:i:s')
                    ]
                ]);

                $_SESSION['success'] = 'Pagamento aprovado e processado com sucesso!';
            } else {
                throw new \Exception('Erro ao processar o pagamento.');
            }
        } catch (\Exception $e) {
            error_log("Error approving payment: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao aprovar pagamento: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/payments');
        exit;
    }

    /**
     * List condominiums by admin
     */
    public function condominiums()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

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
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info'],
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

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

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
                'user' => AuthMiddleware::user(),
                'error' => $messages['error'],
                'success' => $messages['success'],
                'info' => $messages['info']
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

        // Get filter parameters - sanitize and validate all inputs
        // Validate audit type against allowed values
        $allowedAuditTypes = ['all', 'general', 'payments', 'financial', 'subscriptions', 'documents'];
        $auditTypeInput = Security::sanitize($_GET['audit_type'] ?? 'all');
        $auditTypeFilter = in_array($auditTypeInput, $allowedAuditTypes) ? $auditTypeInput : 'all';
        $actionFilter = Security::sanitize($_GET['action'] ?? '');
        $modelFilter = Security::sanitize($_GET['model'] ?? '');
        $userIdFilter = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;

        // Default to today if no date filters provided
        $hasDateFilter = isset($_GET['date_from']) || isset($_GET['date_to']);
        $dateFromInput = Security::sanitize($_GET['date_from'] ?? '');
        $dateToInput = Security::sanitize($_GET['date_to'] ?? '');
        // Validate date format (YYYY-MM-DD)
        $dateFrom = ($hasDateFilter && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromInput)) ? $dateFromInput : ($hasDateFilter ? '' : date('Y-m-d'));
        $dateTo = ($hasDateFilter && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToInput)) ? $dateToInput : ($hasDateFilter ? '' : date('Y-m-d'));

        $search = Security::sanitize($_GET['search'] ?? '');
        $page = isset($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) && $_GET['per_page'] > 0 ? min((int)$_GET['per_page'], 100) : 50; // Limit per_page to 100
        // Validate sort column
        $allowedSortColumns = ['created_at', 'action', 'model', 'user_id'];
        $sortInput = Security::sanitize($_GET['sort_by'] ?? 'created_at');
        $sortBy = in_array($sortInput, $allowedSortColumns) ? $sortInput : 'created_at';
        $sortOrderInput = isset($_GET['sort_order']) && strtoupper(Security::sanitize($_GET['sort_order'])) === 'ASC' ? 'ASC' : 'DESC';
        $sortOrder = $sortOrderInput;

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

        // Get all separated audit tables dynamically
        $separatedAuditTables = [];
        try {
            $stmt = $db->query("SHOW TABLES LIKE 'audit_%'");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            // Exclude specialized tables that are handled separately
            $excludedTables = ['audit_payments', 'audit_financial', 'audit_subscriptions', 'audit_documents'];
            
            foreach ($tables as $table) {
                if (!in_array($table, $excludedTables)) {
                    $separatedAuditTables[] = $table;
                }
            }
        } catch (\Exception $e) {
            // If query fails, continue without separated tables
            error_log("Error fetching separated audit tables: " . $e->getMessage());
        }

        // General audit logs (for custom logs that don't fit in separated tables)
        if ($auditTypeFilter === 'all' || $auditTypeFilter === 'general') {
            // Check if audit_logs has old_data and new_data columns
            try {
                $checkStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'old_data'");
                $hasOldData = $checkStmt->rowCount() > 0;
                $checkStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'new_data'");
                $hasNewData = $checkStmt->rowCount() > 0;
            } catch (\Exception $e) {
                $hasOldData = false;
                $hasNewData = false;
            }
            
            $oldDataColumn = $hasOldData ? 'old_data' : 'NULL as old_data';
            $newDataColumn = $hasNewData ? 'new_data' : 'NULL as new_data';
            
            // Check if table_name and operation columns exist
            try {
                $checkStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'table_name'");
                $hasTableName = $checkStmt->rowCount() > 0;
                $checkStmt = $db->query("SHOW COLUMNS FROM audit_logs LIKE 'operation'");
                $hasOperation = $checkStmt->rowCount() > 0;
            } catch (\Exception $e) {
                $hasTableName = false;
                $hasOperation = false;
            }
            
            $tableNameColumn = $hasTableName ? 'table_name' : 'NULL as table_name';
            $operationColumn = $hasOperation ? 'operation' : 'NULL as operation';
            
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
                    NULL as payment_id,
                    {$oldDataColumn},
                    {$newDataColumn},
                    {$tableNameColumn},
                    {$operationColumn}
                FROM audit_logs
                {$whereClause}
            ";
            
            // Add all separated audit tables
            foreach ($separatedAuditTables as $auditTable) {
                // Check which columns exist in this table
                try {
                    $columnsStmt = $db->query("SHOW COLUMNS FROM `{$auditTable}`");
                    $columns = $columnsStmt->fetchAll(\PDO::FETCH_COLUMN);
                    $hasModelColumn = in_array('model', $columns);
                    $hasModelIdColumn = in_array('model_id', $columns);
                    $hasTableNameColumn = in_array('table_name', $columns);
                } catch (\Exception $e) {
                    // Skip this table if we can't check columns
                    continue;
                }
                
                // Build where clause for separated tables
                // For separated tables, if filtering by model, we need to filter by table_name instead
                $separatedWhereConditions = [];
                
                if (!empty($modelFilter)) {
                    // For separated tables, filter by table_name matching the model
                    $tableName = str_replace('audit_', '', $auditTable);
                    if ($tableName === $modelFilter) {
                        // Only include this table if it matches the model filter
                        if ($hasTableNameColumn) {
                            $separatedWhereConditions[] = "table_name = :model";
                        }
                    } else {
                        // Skip this table if it doesn't match the model filter
                        continue;
                    }
                }
                
                if ($userIdFilter !== null) {
                    $separatedWhereConditions[] = "user_id = :user_id";
                }
                
                if (!empty($dateFrom)) {
                    $separatedWhereConditions[] = "created_at >= :date_from_start";
                }
                
                if (!empty($dateTo)) {
                    $separatedWhereConditions[] = "created_at <= :date_to_end";
                }
                
                if (!empty($search)) {
                    $separatedWhereConditions[] = "description LIKE :search";
                }
                
                $separatedWhereClause = !empty($separatedWhereConditions) ? 'WHERE ' . implode(' AND ', $separatedWhereConditions) : '';
                
                // Use appropriate columns based on what exists in the table
                $modelColumn = $hasModelColumn ? 'model' : 'NULL as model';
                $modelIdColumn = $hasModelIdColumn ? 'model_id' : 'NULL as model_id';
                $hasOldDataColumn = in_array('old_data', $columns);
                $hasNewDataColumn = in_array('new_data', $columns);
                $hasOperationColumn = in_array('operation', $columns);
                
                $oldDataColumn = $hasOldDataColumn ? 'old_data' : 'NULL as old_data';
                $newDataColumn = $hasNewDataColumn ? 'new_data' : 'NULL as new_data';
                $tableNameColumn = $hasTableNameColumn ? 'table_name' : 'NULL as table_name';
                $operationColumn = $hasOperationColumn ? 'operation' : 'NULL as operation';
                
                $unionQueries[] = "
                    SELECT
                        id,
                        user_id,
                        action,
                        {$modelColumn},
                        {$modelIdColumn},
                        description,
                        ip_address,
                        user_agent,
                        created_at,
                        'general' as audit_type,
                        NULL as amount,
                        NULL as payment_method,
                        NULL as status,
                        NULL as subscription_id,
                        NULL as payment_id,
                        {$oldDataColumn},
                        {$newDataColumn},
                        {$tableNameColumn},
                        {$operationColumn}
                    FROM {$auditTable}
                    {$separatedWhereClause}
                ";
            }
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
                    payment_id,
                    NULL as old_data,
                    NULL as new_data,
                    NULL as table_name,
                    NULL as operation
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
                    NULL as payment_id,
                    NULL as old_data,
                    NULL as new_data,
                    NULL as table_name,
                    NULL as operation
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
                    NULL as payment_id,
                    NULL as old_data,
                    NULL as new_data,
                    NULL as table_name,
                    NULL as operation
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
                    NULL as payment_id,
                    NULL as old_data,
                    NULL as new_data,
                    NULL as table_name,
                    NULL as operation
                FROM audit_documents
                {$whereClause}
            ";
        }

        if (empty($unionQueries)) {
            $unionQueries[] = "SELECT NULL as id, NULL as user_id, NULL as action, NULL as model, NULL as model_id, NULL as description, NULL as ip_address, NULL as user_agent, NULL as created_at, NULL as audit_type, NULL as amount, NULL as payment_method, NULL as status, NULL as subscription_id, NULL as payment_id, NULL as old_data, NULL as new_data, NULL as table_name, NULL as operation WHERE 1=0";
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

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

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
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info'],
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

        $query = Security::sanitize($_GET['q'] ?? '');
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20; // Limit to max 100 results

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
     * List all plans
     */
    public function plans()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $plans = $this->planModel->getAll();

        // Get session messages and clear them
        $messages = $this->getSessionMessages();
        $error = $messages['error'];
        $success = $messages['success'];

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/plans/index.html.twig',
            'page' => ['titulo' => 'Gestão de Planos'],
            'plans' => $plans,
            'error' => $error,
            'success' => $success,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show create plan form
     */
    public function createPlan()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        // Get session messages and clear them
        $messages = $this->getSessionMessages();
        $error = $messages['error'];
        $success = $messages['success'];

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/plans/form.html.twig',
            'page' => ['titulo' => 'Criar Plano'],
            'plan' => null,
            'error' => $error,
            'success' => $success,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Store new plan
     */
    public function storePlan()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $name = Security::sanitize($_POST['name'] ?? '');
        $slug = Security::sanitize($_POST['slug'] ?? '');
        $description = Security::sanitize($_POST['description'] ?? '');
        $priceMonthly = floatval($_POST['price_monthly'] ?? 0);
        $priceYearly = !empty($_POST['price_yearly']) ? floatval($_POST['price_yearly']) : null;

        // Novos campos do modelo de licenças
        $planType = Security::sanitize($_POST['plan_type'] ?? null);
        $licenseMin = !empty($_POST['license_min']) ? intval($_POST['license_min']) : null;
        $licenseLimit = !empty($_POST['license_limit']) ? intval($_POST['license_limit']) : null;
        $allowMultipleCondos = isset($_POST['allow_multiple_condos']) ? (bool)$_POST['allow_multiple_condos'] : false;
        $allowOverage = isset($_POST['allow_overage']) ? (bool)$_POST['allow_overage'] : false;
        $pricingMode = Security::sanitize($_POST['pricing_mode'] ?? 'flat');
        $annualDiscountPercentage = !empty($_POST['annual_discount_percentage']) ? floatval($_POST['annual_discount_percentage']) : 0;

        // Limite de condomínios (campo ativo, não legado)
        $limitCondominios = !empty($_POST['limit_condominios']) ? intval($_POST['limit_condominios']) : null;

        // Campos legados (compatibilidade)
        $limitFracoes = !empty($_POST['limit_fracoes']) ? intval($_POST['limit_fracoes']) : null;
        $features = !empty($_POST['features']) ? json_decode($_POST['features'], true) : [];
        $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        $sortOrder = intval($_POST['sort_order'] ?? 0);

        // Validation
        if (empty($name) || empty($slug) || $priceMonthly < 0) {
            $_SESSION['error'] = 'Dados inválidos. Verifique os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'admin/plans/create');
            exit;
        }

        // Validar que plano base deve ter limit_condominios = 1
        if ($planType === 'condominio' && !$allowMultipleCondos) {
            if ($limitCondominios !== 1) {
                $_SESSION['error'] = 'O plano base (Condomínio) deve ter limite de condomínios igual a 1.';
                header('Location: ' . BASE_URL . 'admin/plans/create');
                exit;
            }
        }

        // Validar que plano base deve ter limit_condominios = 1
        if ($planType === 'condominio' && !$allowMultipleCondos) {
            if ($limitCondominios !== 1) {
                $_SESSION['error'] = 'O plano base (Condomínio) deve ter limite de condomínios igual a 1.';
                header('Location: ' . BASE_URL . 'admin/plans/create');
                exit;
            }
        }

        // Check if slug already exists
        $existingPlan = $this->planModel->findBySlug($slug);
        if ($existingPlan) {
            $_SESSION['error'] = 'Já existe um plano com este slug.';
            header('Location: ' . BASE_URL . 'admin/plans/create');
            exit;
        }

        try {
            $planData = [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'price_monthly' => $priceMonthly,
                'price_yearly' => $priceYearly,
                'limit_condominios' => $limitCondominios,
                'limit_fracoes' => $limitFracoes,
                'features' => $features,
                'is_active' => $isActive,
                'sort_order' => $sortOrder
            ];

            // Adicionar campos do novo modelo se existirem na tabela
            if ($planType) {
                $planData['plan_type'] = $planType;
                $planData['license_min'] = $licenseMin;
                $planData['license_limit'] = $licenseLimit;
                $planData['allow_multiple_condos'] = $allowMultipleCondos ? 1 : 0;
                $planData['allow_overage'] = $allowOverage ? 1 : 0;
                $planData['pricing_mode'] = $pricingMode;
                $planData['annual_discount_percentage'] = $annualDiscountPercentage;
            }

            $planId = $this->planModel->create($planData);

            $this->logAudit([
                'action' => 'plan_created',
                'model' => 'plan',
                'model_id' => $planId,
                'description' => "Plano criado: {$name} (slug: {$slug})"
            ]);

            $_SESSION['success'] = 'Plano criado com sucesso.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar plano: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'admin/plans/create');
            exit;
        }
    }

    /**
     * Show edit plan form
     */
    public function editPlan(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $plan = $this->planModel->findById($id);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        // Get session messages and clear them
        $messages = $this->getSessionMessages();
        $error = $messages['error'];
        $success = $messages['success'];

        // Decode features JSON
        if (!empty($plan['features'])) {
            $plan['features'] = json_decode($plan['features'], true);
        }

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/plans/form.html.twig',
            'page' => ['titulo' => 'Editar Plano'],
            'plan' => $plan,
            'error' => $error,
            'success' => $success,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Update plan
     */
    public function updatePlan(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $plan = $this->planModel->findById($id);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $name = Security::sanitize($_POST['name'] ?? '');
        $slug = Security::sanitize($_POST['slug'] ?? '');
        $description = Security::sanitize($_POST['description'] ?? '');
        $priceMonthly = floatval($_POST['price_monthly'] ?? 0);
        $priceYearly = !empty($_POST['price_yearly']) ? floatval($_POST['price_yearly']) : null;

        // Novos campos do modelo de licenças
        $planType = Security::sanitize($_POST['plan_type'] ?? null);
        $licenseMin = !empty($_POST['license_min']) ? intval($_POST['license_min']) : null;
        $licenseLimit = !empty($_POST['license_limit']) ? intval($_POST['license_limit']) : null;
        $allowMultipleCondos = isset($_POST['allow_multiple_condos']) ? (bool)$_POST['allow_multiple_condos'] : false;
        $allowOverage = isset($_POST['allow_overage']) ? (bool)$_POST['allow_overage'] : false;
        $pricingMode = Security::sanitize($_POST['pricing_mode'] ?? 'flat');
        $annualDiscountPercentage = !empty($_POST['annual_discount_percentage']) ? floatval($_POST['annual_discount_percentage']) : 0;

        // Campos legados (compatibilidade)
        $limitCondominios = !empty($_POST['limit_condominios']) ? intval($_POST['limit_condominios']) : null;
        $limitFracoes = !empty($_POST['limit_fracoes']) ? intval($_POST['limit_fracoes']) : null;
        $features = !empty($_POST['features']) ? json_decode($_POST['features'], true) : [];
        $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        $sortOrder = intval($_POST['sort_order'] ?? 0);

        // Validation
        if (empty($name) || empty($slug) || $priceMonthly < 0) {
            $_SESSION['error'] = 'Dados inválidos. Verifique os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $id . '/edit');
            exit;
        }

        // Validar que plano base deve ter limit_condominios = 1
        if ($planType === 'condominio' && !$allowMultipleCondos) {
            if ($limitCondominios !== 1) {
                $_SESSION['error'] = 'O plano base (Condomínio) deve ter limite de condomínios igual a 1.';
                header('Location: ' . BASE_URL . 'admin/plans/' . $id . '/edit');
                exit;
            }
        }

        // Check if slug already exists (excluding current plan)
        $existingPlan = $this->planModel->findBySlug($slug);
        if ($existingPlan && $existingPlan['id'] != $id) {
            $_SESSION['error'] = 'Já existe um plano com este slug.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $id . '/edit');
            exit;
        }

        try {
            $planData = [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'price_monthly' => $priceMonthly,
                'price_yearly' => $priceYearly,
                'limit_condominios' => $limitCondominios,
                'limit_fracoes' => $limitFracoes,
                'features' => $features,
                'is_active' => $isActive,
                'sort_order' => $sortOrder
            ];

            // Adicionar campos do novo modelo se existirem na tabela
            if ($planType) {
                $planData['plan_type'] = $planType;
                $planData['license_min'] = $licenseMin;
                $planData['license_limit'] = $licenseLimit;
                $planData['allow_multiple_condos'] = $allowMultipleCondos ? 1 : 0;
                $planData['allow_overage'] = $allowOverage ? 1 : 0;
                $planData['pricing_mode'] = $pricingMode;
                $planData['annual_discount_percentage'] = $annualDiscountPercentage;
            }

            $success = $this->planModel->update($id, $planData);

            if ($success) {
                $this->logAudit([
                    'action' => 'plan_updated',
                    'model' => 'plan',
                    'model_id' => $id,
                    'description' => "Plano atualizado: {$name} (slug: {$slug})"
                ]);

                $_SESSION['success'] = 'Plano atualizado com sucesso.';
            } else {
                $_SESSION['error'] = 'Erro ao atualizar plano.';
            }

            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar plano: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'admin/plans/' . $id . '/edit');
            exit;
        }
    }

    /**
     * Toggle plan active status
     */
    public function togglePlanActive(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $plan = $this->planModel->findById($id);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        try {
            $success = $this->planModel->toggleActive($id);
            if ($success) {
                $newStatus = !$plan['is_active'] ? 'ativado' : 'desativado';
                $this->logAudit([
                    'action' => 'plan_toggled',
                    'model' => 'plan',
                    'model_id' => $id,
                    'description' => "Plano {$newStatus}: {$plan['name']}"
                ]);

                $_SESSION['success'] = "Plano {$newStatus} com sucesso.";
            } else {
                $_SESSION['error'] = 'Erro ao alterar status do plano.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/plans');
        exit;
    }

    /**
     * Delete plan
     */
    public function deletePlan(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $plan = $this->planModel->findById($id);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        try {
            $success = $this->planModel->delete($id);

            if ($success) {
                $this->logAudit([
                    'action' => 'plan_deleted',
                    'model' => 'plan',
                    'model_id' => $id,
                    'description' => "Plano deletado: {$plan['name']}"
                ]);

                $_SESSION['success'] = 'Plano deletado com sucesso.';
            } else {
                $_SESSION['error'] = 'Erro ao deletar plano. Verifique se não há subscrições ativas associadas.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao deletar plano: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/plans');
        exit;
    }

    /**
     * List all promotions
     */
    public function promotions()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

        $promotions = $this->promotionModel->getAll();

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/promotions/index.html.twig',
            'page' => ['titulo' => 'Gestão de Promoções'],
            'promotions' => $promotions,
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info'],
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show create promotion form
     */
    public function createPromotion()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $plans = $this->planModel->getAll();

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/promotions/form.html.twig',
            'page' => ['titulo' => 'Criar Promoção'],
            'promotion' => null,
            'plans' => $plans,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user(),
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info']
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * View PHP error logs
     */
    public function phpLogs()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        // Temporarily suppress Xdebug function trace output if Xdebug is enabled
        $originalXdebugMode = null;
        if (function_exists('xdebug_info') && extension_loaded('xdebug')) {
            // Try to disable function trace output
            $originalXdebugMode = ini_get('xdebug.mode');
            if ($originalXdebugMode !== false) {
                $modes = explode(',', $originalXdebugMode);
                $modes = array_filter($modes, function($mode) {
                    return trim($mode) !== 'trace';
                });
                @ini_set('xdebug.mode', implode(',', $modes));
            }
        }

        // Start output buffering to capture any debug output
        if (ob_get_level() === 0) {
            ob_start();
        }

        $logService = new LogService();

        // Get filter parameters - sanitize all inputs
        $page = isset($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) && $_GET['per_page'] > 0 ? min((int)$_GET['per_page'], 500) : 50;
        $search = Security::sanitize($_GET['search'] ?? '');
        $dateFrom = Security::sanitize($_GET['date_from'] ?? '');
        $dateTo = Security::sanitize($_GET['date_to'] ?? '');
        // Validate level against allowed values
        $allowedLevels = ['ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'];
        $levelInput = Security::sanitize($_GET['level'] ?? '');
        $level = in_array($levelInput, $allowedLevels) ? $levelInput : '';
        $direction = isset($_GET['direction']) && $_GET['direction'] === 'asc' ? 'asc' : 'desc';

        $filters = [
            'search' => $search,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'level' => $level,
            'direction' => $direction
        ];

        // Calculate offset
        $offset = ($page - 1) * $perPage;

        // Get log lines
        $logLines = $logService->readLogLines($offset, $perPage, $filters);

        // Get total count (with limit for performance)
        $totalCount = $logService->countLogLines($filters);
        $totalPages = ceil($totalCount / $perPage);

        // Get file info
        $fileInfo = $logService->getLogFileInfo();

        // Get session messages
        $messages = $this->getSessionMessages();

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/php-logs/index.html.twig',
            'page' => ['titulo' => 'Logs PHP'],
            'log_lines' => $logLines,
            'file_info' => $fileInfo,
            'filters' => [
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'level' => $level,
                'direction' => $direction
            ],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'per_page' => $perPage
            ],
            'error' => $messages['error'],
            'success' => $messages['success'],
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        // Clean any debug output that might have been sent (e.g., from Xdebug)
        if (ob_get_level() > 0) {
            ob_clean();
        }

        // Render template (Twig uses its own output buffering internally)
        $output = $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);

        // Clean any Xdebug output that might have been added to buffer during render
        if (ob_get_level() > 0) {
            $bufferContent = ob_get_contents();
            // Check if buffer contains Xdebug trace output (starts with "PHP N.")
            if (!empty($bufferContent) && preg_match('/^PHP \d+\./', trim($bufferContent))) {
                ob_clean(); // Remove Xdebug output
            }
            ob_end_clean();
        }

        // Filter out any Xdebug trace output from the rendered output
        // Xdebug function traces typically start with "PHP N." where N is a number
        // This removes lines that start with "PHP" followed by a number and period
        $lines = explode("\n", $output);
        $filteredLines = [];
        foreach ($lines as $line) {
            // Skip lines that look like Xdebug function trace output
            if (preg_match('/^PHP \d+\./', trim($line))) {
                continue;
            }
            $filteredLines[] = $line;
        }
        $output = implode("\n", $filteredLines);

        // Restore original Xdebug mode if it was changed
        if ($originalXdebugMode !== null) {
            @ini_set('xdebug.mode', $originalXdebugMode);
        }

        echo $output;
    }

    /**
     * Clear PHP error log
     */
    public function clearPhpLog()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = 'Método inválido.';
            header('Location: ' . BASE_URL . 'admin/php-logs');
            exit;
        }

        // Verify CSRF token
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/php-logs');
            exit;
        }

        $logService = new LogService();

        if ($logService->clearLog()) {
            $_SESSION['success'] = 'Log PHP limpo com sucesso.';
            $this->auditService->log([
                'action' => 'php_log_cleared',
                'model' => 'system',
                'description' => 'Log PHP foi limpo pelo super admin'
            ]);
        } else {
            $_SESSION['error'] = 'Erro ao limpar log PHP.';
        }

        header('Location: ' . BASE_URL . 'admin/php-logs');
        exit;
    }

    /**
     * Store new promotion
     */
    public function storePromotion()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $name = Security::sanitize($_POST['name'] ?? '');
        $code = strtoupper(Security::sanitize($_POST['code'] ?? ''));
        $description = Security::sanitize($_POST['description'] ?? '');
        $discountType = $_POST['discount_type'] ?? 'percentage';
        $discountValue = floatval($_POST['discount_value'] ?? 0);
        $planId = !empty($_POST['plan_id']) ? intval($_POST['plan_id']) : null;
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $maxUses = !empty($_POST['max_uses']) ? intval($_POST['max_uses']) : null;
        $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        $isVisible = isset($_POST['is_visible']) ? (bool)$_POST['is_visible'] : false;
        $durationMonths = !empty($_POST['duration_months']) ? intval($_POST['duration_months']) : null;

        // Validation
        if (empty($name) || empty($code) || $discountValue <= 0) {
            $_SESSION['error'] = 'Dados inválidos. Verifique os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'admin/promotions/create');
            exit;
        }

        if ($discountType === 'percentage' && $discountValue > 100) {
            $_SESSION['error'] = 'O desconto percentual não pode ser superior a 100%.';
            header('Location: ' . BASE_URL . 'admin/promotions/create');
            exit;
        }

        if (empty($startDate) || empty($endDate)) {
            $_SESSION['error'] = 'As datas de início e fim são obrigatórias.';
            header('Location: ' . BASE_URL . 'admin/promotions/create');
            exit;
        }

        if (strtotime($endDate) <= strtotime($startDate)) {
            $_SESSION['error'] = 'A data de fim deve ser posterior à data de início.';
            header('Location: ' . BASE_URL . 'admin/promotions/create');
            exit;
        }

        // Check if code already exists
        $existingPromotion = $this->promotionModel->findByCode($code);
        if ($existingPromotion) {
            $_SESSION['error'] = 'Já existe uma promoção com este código.';
            header('Location: ' . BASE_URL . 'admin/promotions/create');
            exit;
        }

        // Validate plan_id if provided
        if ($planId) {
            $plan = $this->planModel->findById($planId);
            if (!$plan) {
                $_SESSION['error'] = 'Plano não encontrado.';
                header('Location: ' . BASE_URL . 'admin/promotions/create');
                exit;
            }
        }

        try {
            $promotionId = $this->promotionModel->create([
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'plan_id' => $planId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'max_uses' => $maxUses,
                'is_active' => $isActive,
                'is_visible' => $isVisible,
                'duration_months' => $durationMonths
            ]);

            $this->logAudit([
                'action' => 'promotion_created',
                'model' => 'promotion',
                'model_id' => $promotionId,
                'description' => "Promoção criada: {$name} (código: {$code})"
            ]);

            $_SESSION['success'] = 'Promoção criada com sucesso.';
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar promoção: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'admin/promotions/create');
            exit;
        }
    }

    /**
     * Show edit promotion form
     */
    public function editPromotion(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $promotion = $this->promotionModel->findById($id);
        if (!$promotion) {
            $_SESSION['error'] = 'Promoção não encontrada.';
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $plans = $this->planModel->getAll();

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/promotions/form.html.twig',
            'page' => ['titulo' => 'Editar Promoção'],
            'promotion' => $promotion,
            'plans' => $plans,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user(),
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info']
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Update promotion
     */
    public function updatePromotion(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $promotion = $this->promotionModel->findById($id);
        if (!$promotion) {
            $_SESSION['error'] = 'Promoção não encontrada.';
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $name = Security::sanitize($_POST['name'] ?? '');
        $code = strtoupper(Security::sanitize($_POST['code'] ?? ''));
        $description = Security::sanitize($_POST['description'] ?? '');
        $discountType = $_POST['discount_type'] ?? 'percentage';
        $discountValue = floatval($_POST['discount_value'] ?? 0);
        $planId = !empty($_POST['plan_id']) ? intval($_POST['plan_id']) : null;
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $maxUses = !empty($_POST['max_uses']) ? intval($_POST['max_uses']) : null;
        $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        $isVisible = isset($_POST['is_visible']) ? (bool)$_POST['is_visible'] : false;
        $durationMonths = !empty($_POST['duration_months']) ? intval($_POST['duration_months']) : null;

        // Validation
        if (empty($name) || empty($code) || $discountValue <= 0) {
            $_SESSION['error'] = 'Dados inválidos. Verifique os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'admin/promotions/' . $id . '/edit');
            exit;
        }

        if ($discountType === 'percentage' && $discountValue > 100) {
            $_SESSION['error'] = 'O desconto percentual não pode ser superior a 100%.';
            header('Location: ' . BASE_URL . 'admin/promotions/' . $id . '/edit');
            exit;
        }

        if (empty($startDate) || empty($endDate)) {
            $_SESSION['error'] = 'As datas de início e fim são obrigatórias.';
            header('Location: ' . BASE_URL . 'admin/promotions/' . $id . '/edit');
            exit;
        }

        if (strtotime($endDate) <= strtotime($startDate)) {
            $_SESSION['error'] = 'A data de fim deve ser posterior à data de início.';
            header('Location: ' . BASE_URL . 'admin/promotions/' . $id . '/edit');
            exit;
        }

        // Check if code already exists (excluding current promotion)
        $existingPromotion = $this->promotionModel->findByCode($code);
        if ($existingPromotion && $existingPromotion['id'] != $id) {
            $_SESSION['error'] = 'Já existe uma promoção com este código.';
            header('Location: ' . BASE_URL . 'admin/promotions/' . $id . '/edit');
            exit;
        }

        // Validate plan_id if provided
        if ($planId) {
            $plan = $this->planModel->findById($planId);
            if (!$plan) {
                $_SESSION['error'] = 'Plano não encontrado.';
                header('Location: ' . BASE_URL . 'admin/promotions/' . $id . '/edit');
                exit;
            }
        }

        try {
            $success = $this->promotionModel->update($id, [
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'plan_id' => $planId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'max_uses' => $maxUses,
                'is_active' => $isActive,
                'is_visible' => $isVisible,
                'duration_months' => $durationMonths
            ]);

            if ($success) {
                $this->logAudit([
                    'action' => 'promotion_updated',
                    'model' => 'promotion',
                    'model_id' => $id,
                    'description' => "Promoção atualizada: {$name} (código: {$code})"
                ]);

                $_SESSION['success'] = 'Promoção atualizada com sucesso.';
            } else {
                $_SESSION['error'] = 'Erro ao atualizar promoção.';
            }

            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar promoção: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'admin/promotions/' . $id . '/edit');
            exit;
        }
    }

    /**
     * Toggle promotion active status
     */
    public function togglePromotionActive(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $promotion = $this->promotionModel->findById($id);
        if (!$promotion) {
            $_SESSION['error'] = 'Promoção não encontrada.';
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        try {
            $success = $this->promotionModel->toggleActive($id);
            if ($success) {
                $newStatus = !$promotion['is_active'] ? 'ativada' : 'desativada';
                $this->logAudit([
                    'action' => 'promotion_toggled',
                    'model' => 'promotion',
                    'model_id' => $id,
                    'description' => "Promoção {$newStatus}: {$promotion['name']}"
                ]);

                $_SESSION['success'] = "Promoção {$newStatus} com sucesso.";
            } else {
                $_SESSION['error'] = 'Erro ao alterar status da promoção.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/promotions');
        exit;
    }

    /**
     * Delete promotion
     */
    public function deletePromotion(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        $promotion = $this->promotionModel->findById($id);
        if (!$promotion) {
            $_SESSION['error'] = 'Promoção não encontrada.';
            header('Location: ' . BASE_URL . 'admin/promotions');
            exit;
        }

        try {
            global $db;
            $stmt = $db->prepare("DELETE FROM promotions WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $this->logAudit([
                'action' => 'promotion_deleted',
                'model' => 'promotion',
                'model_id' => $id,
                'description' => "Promoção deletada: {$promotion['name']}"
            ]);

            $_SESSION['success'] = 'Promoção deletada com sucesso.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao deletar promoção: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/promotions');
        exit;
    }

    /**
     * List extra condominiums pricing for a plan
     */
    public function extraCondominiumsPricing(int $planId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $pricingTiers = $this->extraCondominiumsPricingModel->getByPlanId($planId);

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/plans/extra-condominiums-pricing.html.twig',
            'page' => ['titulo' => 'Preços de Condomínios Extras - ' . $plan['name']],
            'plan' => $plan,
            'pricing_tiers' => $pricingTiers,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user(),
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info']
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show create pricing tier form
     */
    public function createExtraCondominiumsPricing(int $planId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/plans/extra-condominiums-pricing-form.html.twig',
            'page' => ['titulo' => 'Criar Escalão de Preço - ' . $plan['name']],
            'plan' => $plan,
            'pricing_tier' => null,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Store new pricing tier
     */
    public function storeExtraCondominiumsPricing(int $planId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $minCondominios = intval($_POST['min_condominios'] ?? 1);
        $maxCondominios = !empty($_POST['max_condominios']) ? intval($_POST['max_condominios']) : null;
        $pricePerCondominium = floatval($_POST['price_per_condominium'] ?? 0);
        $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        $sortOrder = intval($_POST['sort_order'] ?? 0);

        // Validation
        if ($minCondominios < 1 || $pricePerCondominium <= 0) {
            $_SESSION['error'] = 'Dados inválidos. Verifique os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing/create');
            exit;
        }

        if ($maxCondominios !== null && $maxCondominios <= $minCondominios) {
            $_SESSION['error'] = 'O número máximo de condomínios deve ser maior que o mínimo.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing/create');
            exit;
        }

        try {
            $tierId = $this->extraCondominiumsPricingModel->create([
                'plan_id' => $planId,
                'min_condominios' => $minCondominios,
                'max_condominios' => $maxCondominios,
                'price_per_condominium' => $pricePerCondominium,
                'is_active' => $isActive,
                'sort_order' => $sortOrder
            ]);

            $this->logAudit([
                'action' => 'extra_condominiums_pricing_created',
                'model' => 'plan_extra_condominiums_pricing',
                'model_id' => $tierId,
                'description' => "Escalão de preço criado para plano {$plan['name']}: {$minCondominios}-" . ($maxCondominios ?? '∞') . " condomínios, €{$pricePerCondominium}/condomínio"
            ]);

            $_SESSION['success'] = 'Escalão de preço criado com sucesso.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar escalão de preço: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing/create');
            exit;
        }
    }

    /**
     * Show edit pricing tier form
     */
    public function editExtraCondominiumsPricing(int $planId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $pricingTier = $this->extraCondominiumsPricingModel->findById($id);
        if (!$pricingTier || $pricingTier['plan_id'] != $planId) {
            $_SESSION['error'] = 'Escalão de preço não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        // Get session messages and clear them
        $messages = $this->getSessionMessages();

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/plans/extra-condominiums-pricing-form.html.twig',
            'page' => ['titulo' => 'Editar Escalão de Preço - ' . $plan['name']],
            'plan' => $plan,
            'pricing_tier' => $pricingTier,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user(),
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info']
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Update pricing tier
     */
    public function updateExtraCondominiumsPricing(int $planId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $pricingTier = $this->extraCondominiumsPricingModel->findById($id);
        if (!$pricingTier || $pricingTier['plan_id'] != $planId) {
            $_SESSION['error'] = 'Escalão de preço não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        $minCondominios = intval($_POST['min_condominios'] ?? 1);
        $maxCondominios = !empty($_POST['max_condominios']) ? intval($_POST['max_condominios']) : null;
        $pricePerCondominium = floatval($_POST['price_per_condominium'] ?? 0);
        $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        $sortOrder = intval($_POST['sort_order'] ?? 0);

        // Validation
        if ($minCondominios < 1 || $pricePerCondominium <= 0) {
            $_SESSION['error'] = 'Dados inválidos. Verifique os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing/' . $id . '/edit');
            exit;
        }

        if ($maxCondominios !== null && $maxCondominios <= $minCondominios) {
            $_SESSION['error'] = 'O número máximo de condomínios deve ser maior que o mínimo.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing/' . $id . '/edit');
            exit;
        }

        try {
            $success = $this->extraCondominiumsPricingModel->update($id, [
                'min_condominios' => $minCondominios,
                'max_condominios' => $maxCondominios,
                'price_per_condominium' => $pricePerCondominium,
                'is_active' => $isActive,
                'sort_order' => $sortOrder
            ]);

            if ($success) {
                $this->logAudit([
                    'action' => 'extra_condominiums_pricing_updated',
                    'model' => 'plan_extra_condominiums_pricing',
                    'model_id' => $id,
                    'description' => "Escalão de preço atualizado para plano {$plan['name']}: {$minCondominios}-" . ($maxCondominios ?? '∞') . " condomínios, €{$pricePerCondominium}/condomínio"
                ]);

                $_SESSION['success'] = 'Escalão de preço atualizado com sucesso.';
            } else {
                $_SESSION['error'] = 'Erro ao atualizar escalão de preço.';
            }

            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar escalão de preço: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing/' . $id . '/edit');
            exit;
        }
    }

    /**
     * Delete pricing tier
     */
    public function deleteExtraCondominiumsPricing(int $planId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        $pricingTier = $this->extraCondominiumsPricingModel->findById($id);
        if (!$pricingTier || $pricingTier['plan_id'] != $planId) {
            $_SESSION['error'] = 'Escalão de preço não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        try {
            $this->extraCondominiumsPricingModel->delete($id);
            $this->logAudit([
                'action' => 'extra_condominiums_pricing_deleted',
                'model' => 'plan_extra_condominiums_pricing',
                'model_id' => $id,
                'description' => "Escalão de preço deletado: {$pricingTier['min_condominios']}-" . ($pricingTier['max_condominios'] ?? '∞') . " condomínios"
            ]);

            $_SESSION['success'] = 'Escalão de preço deletado com sucesso.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao deletar escalão de preço: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
        exit;
    }

    /**
     * Toggle pricing tier active status
     */
    public function toggleExtraCondominiumsPricingActive(int $planId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        $pricingTier = $this->extraCondominiumsPricingModel->findById($id);
        if (!$pricingTier || $pricingTier['plan_id'] != $planId) {
            $_SESSION['error'] = 'Escalão de preço não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
            exit;
        }

        try {
            $success = $this->extraCondominiumsPricingModel->toggleActive($id);
            if ($success) {
                $newStatus = !$pricingTier['is_active'] ? 'ativado' : 'desativado';
                $this->logAudit([
                    'action' => 'extra_condominiums_pricing_toggled',
                    'model' => 'plan_extra_condominiums_pricing',
                    'model_id' => $id,
                    'description' => "Escalão de preço {$newStatus}: {$pricingTier['min_condominios']}-" . ($pricingTier['max_condominios'] ?? '∞') . " condomínios"
                ]);

                $_SESSION['success'] = "Escalão de preço {$newStatus} com sucesso.";
            } else {
                $_SESSION['error'] = 'Erro ao alterar status do escalão de preço.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/extra-condominiums-pricing');
        exit;
    }

    /**
     * List pricing tiers for a plan
     */
    public function planPricingTiers(int $planId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        // Get session messages and clear them
        $messages = $this->getSessionMessages();
        $error = $messages['error'];
        $success = $messages['success'];

        $tiers = $this->planPricingTierModel->getByPlanId($planId, false);

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/plans/pricing-tiers.html.twig',
            'page' => ['titulo' => 'Escalões de Pricing - ' . $plan['name']],
            'plan' => $plan,
            'tiers' => $tiers,
            'error' => $error,
            'success' => $success,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show create pricing tier form
     */
    public function createPlanPricingTier(int $planId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        // Get session messages and clear them
        $messages = $this->getSessionMessages();
        $error = $messages['error'];
        $success = $messages['success'];

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/plans/pricing-tier-form.html.twig',
            'page' => ['titulo' => 'Criar Escalão de Pricing'],
            'plan' => $plan,
            'tier' => null,
            'error' => $error,
            'success' => $success,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Store new pricing tier
     */
    public function storePlanPricingTier(int $planId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        }

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $minLicenses = intval($_POST['min_licenses'] ?? 0);
        $maxLicenses = !empty($_POST['max_licenses']) ? intval($_POST['max_licenses']) : null;
        $pricePerLicense = floatval($_POST['price_per_license'] ?? 0);
        $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        $sortOrder = intval($_POST['sort_order'] ?? 0);

        // Validation
        if ($minLicenses < 0 || $pricePerLicense < 0) {
            $_SESSION['error'] = 'Dados inválidos. Verifique os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers/create');
            exit;
        }

        if ($maxLicenses !== null && $maxLicenses < $minLicenses) {
            $_SESSION['error'] = 'O máximo de licenças deve ser maior ou igual ao mínimo.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers/create');
            exit;
        }

        try {
            $tierId = $this->planPricingTierModel->create([
                'plan_id' => $planId,
                'min_licenses' => $minLicenses,
                'max_licenses' => $maxLicenses,
                'price_per_license' => $pricePerLicense,
                'is_active' => $isActive ? 1 : 0,
                'sort_order' => $sortOrder
            ]);

            $this->logAudit([
                'action' => 'pricing_tier_created',
                'model' => 'plan_pricing_tier',
                'model_id' => $tierId,
                'description' => "Escalão de pricing criado para plano {$plan['name']}: {$minLicenses}-" . ($maxLicenses ?? '∞') . " licenças a €{$pricePerLicense}/licença"
            ]);

            $_SESSION['success'] = 'Escalão de pricing criado com sucesso.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar escalão de pricing: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers/create');
            exit;
        }
    }

    /**
     * Show edit pricing tier form
     */
    public function editPlanPricingTier(int $planId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $tier = $this->planPricingTierModel->findById($id);
        if (!$tier || $tier['plan_id'] != $planId) {
            $_SESSION['error'] = 'Escalão de pricing não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        }

        // Get session messages and clear them
        $messages = $this->getSessionMessages();
        $error = $messages['error'];
        $success = $messages['success'];

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/plans/pricing-tier-form.html.twig',
            'page' => ['titulo' => 'Editar Escalão de Pricing'],
            'plan' => $plan,
            'tier' => $tier,
            'error' => $error,
            'success' => $success,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Update pricing tier
     */
    public function updatePlanPricingTier(int $planId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        }

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $tier = $this->planPricingTierModel->findById($id);
        if (!$tier || $tier['plan_id'] != $planId) {
            $_SESSION['error'] = 'Escalão de pricing não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        }

        $minLicenses = intval($_POST['min_licenses'] ?? 0);
        $maxLicenses = !empty($_POST['max_licenses']) ? intval($_POST['max_licenses']) : null;
        $pricePerLicense = floatval($_POST['price_per_license'] ?? 0);
        $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        $sortOrder = intval($_POST['sort_order'] ?? 0);

        // Validation
        if ($minLicenses < 0 || $pricePerLicense < 0) {
            $_SESSION['error'] = 'Dados inválidos. Verifique os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers/' . $id . '/edit');
            exit;
        }

        if ($maxLicenses !== null && $maxLicenses < $minLicenses) {
            $_SESSION['error'] = 'O máximo de licenças deve ser maior ou igual ao mínimo.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers/' . $id . '/edit');
            exit;
        }

        try {
            $success = $this->planPricingTierModel->update($id, [
                'min_licenses' => $minLicenses,
                'max_licenses' => $maxLicenses,
                'price_per_license' => $pricePerLicense,
                'is_active' => $isActive ? 1 : 0,
                'sort_order' => $sortOrder
            ]);

            if ($success) {
                $this->logAudit([
                    'action' => 'pricing_tier_updated',
                    'model' => 'plan_pricing_tier',
                    'model_id' => $id,
                    'description' => "Escalão de pricing atualizado para plano {$plan['name']}: {$minLicenses}-" . ($maxLicenses ?? '∞') . " licenças a €{$pricePerLicense}/licença"
                ]);

                $_SESSION['success'] = 'Escalão de pricing atualizado com sucesso.';
            } else {
                $_SESSION['error'] = 'Erro ao atualizar escalão de pricing.';
            }

            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar escalão de pricing: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers/' . $id . '/edit');
            exit;
        }
    }

    /**
     * Delete pricing tier
     */
    public function deletePlanPricingTier(int $planId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        }

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans');
            exit;
        }

        $tier = $this->planPricingTierModel->findById($id);
        if (!$tier || $tier['plan_id'] != $planId) {
            $_SESSION['error'] = 'Escalão de pricing não encontrado.';
            header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
            exit;
        }

        try {
            $success = $this->planPricingTierModel->delete($id);
            if ($success) {
                $this->logAudit([
                    'action' => 'pricing_tier_deleted',
                    'model' => 'plan_pricing_tier',
                    'model_id' => $id,
                    'description' => "Escalão de pricing deletado do plano {$plan['name']}"
                ]);

                $_SESSION['success'] = 'Escalão de pricing deletado com sucesso.';
            } else {
                $_SESSION['error'] = 'Erro ao deletar escalão de pricing.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao deletar escalão de pricing: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/plans/' . $planId . '/pricing-tiers');
        exit;
    }

    /**
     * List all email templates
     */
    public function emailTemplates()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $messages = $this->getSessionMessages();
        $templates = $this->emailTemplateModel->getAll();
        $baseLayout = $this->emailTemplateModel->getBaseLayout();

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/email-templates/index.html.twig',
            'page' => ['titulo' => 'Gestão de Templates de Email'],
            'templates' => $templates,
            'baseLayout' => $baseLayout,
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info'],
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Edit base layout template
     */
    public function editBaseLayout()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $messages = $this->getSessionMessages();
        $baseLayout = $this->emailTemplateModel->getBaseLayout();

        if (!$baseLayout) {
            $_SESSION['error'] = 'Template base não encontrado.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/email-templates/edit-base.html.twig',
            'page' => ['titulo' => 'Editar Layout Base'],
            'template' => $baseLayout,
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info'],
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Update base layout template
     */
    public function updateBaseLayout()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/email-templates/base/edit');
            exit;
        }

        $baseLayout = $this->emailTemplateModel->getBaseLayout();
        if (!$baseLayout) {
            $_SESSION['error'] = 'Template base não encontrado.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        // Validate that {body} placeholder exists
        $htmlBody = $_POST['html_body'] ?? '';
        if (strpos($htmlBody, '{body}') === false) {
            $_SESSION['error'] = 'O template base deve conter o placeholder {body} onde o conteúdo será inserido.';
            header('Location: ' . BASE_URL . 'admin/email-templates/base/edit');
            exit;
        }

        $updateData = [
            'html_body' => $htmlBody,
            'text_body' => $_POST['text_body'] ?? null
        ];

        if ($this->emailTemplateModel->update($baseLayout['id'], $updateData)) {
            $_SESSION['success'] = 'Layout base atualizado com sucesso! Esta alteração afetará todos os emails do sistema.';
        } else {
            $_SESSION['error'] = 'Erro ao atualizar layout base.';
        }

        header('Location: ' . BASE_URL . 'admin/email-templates/base/edit');
        exit;
    }

    /**
     * Edit email template
     */
    public function editEmailTemplate(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $messages = $this->getSessionMessages();
        $template = $this->emailTemplateModel->findById($id);

        if (!$template || $template['is_base_layout']) {
            $_SESSION['error'] = 'Template não encontrado.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/email-templates/edit.html.twig',
            'page' => ['titulo' => 'Editar Template: ' . $template['name']],
            'template' => $template,
            'error' => $messages['error'],
            'success' => $messages['success'],
            'info' => $messages['info'],
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Update email template
     */
    public function updateEmailTemplate(int $id)
    {
        error_log("updateEmailTemplate - INÍCIO - ID: {$id}");
        error_log("updateEmailTemplate - REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
        error_log("updateEmailTemplate - POST data: " . json_encode($_POST));
        
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        // Check if this is actually a test email request (shouldn't happen, but just in case)
        if (isset($_POST['action_type']) && $_POST['action_type'] === 'test_email') {
            error_log("updateEmailTemplate - ERRO: Recebeu requisição de teste de email! Redirecionando para sendTestEmail...");
            // Redirect to the correct method
            $this->sendTestEmail($id);
            return;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/email-templates/' . $id . '/edit');
            exit;
        }

        $template = $this->emailTemplateModel->findById($id);
        if (!$template || $template['is_base_layout']) {
            $_SESSION['error'] = 'Template não encontrado.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        $updateData = [
            'name' => $_POST['name'] ?? $template['name'],
            'description' => $_POST['description'] ?? null,
            'subject' => $_POST['subject'] ?? null,
            'html_body' => $_POST['html_body'] ?? $template['html_body'],
            'text_body' => $_POST['text_body'] ?? null,
            'is_active' => isset($_POST['is_active']) ? (bool)$_POST['is_active'] : $template['is_active']
        ];

        if ($this->emailTemplateModel->update($id, $updateData)) {
            $_SESSION['success'] = 'Template atualizado com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao atualizar template.';
        }

        header('Location: ' . BASE_URL . 'admin/email-templates/' . $id . '/edit');
        exit;
    }

    /**
     * Preview email template
     */
    public function previewEmailTemplate(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $template = $this->emailTemplateModel->findById($id);
        if (!$template) {
            $_SESSION['error'] = 'Template não encontrado.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        $baseLayout = $this->emailTemplateModel->getBaseLayout();
        if (!$baseLayout) {
            $_SESSION['error'] = 'Template base não encontrado.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        // Generate sample data for preview
        $sampleData = $this->getSampleDataForTemplate($template['template_key']);

        // Render template
        $emailService = new \App\Core\EmailService();
        
        // Render from database templates (required)
        $html = $emailService->renderTemplate($template['template_key'], $sampleData);
        $text = $emailService->renderTextTemplate($template['template_key'], $sampleData);
        
        // If template not found or failed to render, show error
        if (empty($html)) {
            $_SESSION['error'] = 'Template não encontrado na base de dados ou base_layout não configurado. Por favor, execute o seeder.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/email-templates/preview.html.twig',
            'page' => ['titulo' => 'Preview: ' . $template['name']],
            'template' => $template,
            'html' => $html,
            'text' => $text,
            'sampleData' => $sampleData,
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Send test email for a template
     */
    public function sendTestEmail(int $id)
    {
        error_log("SuperAdminController::sendTestEmail - INÍCIO - ID: {$id}");
        error_log("SuperAdminController::sendTestEmail - REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
        
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            error_log("SuperAdminController::sendTestEmail - ERRO: Método não é POST");
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        error_log("SuperAdminController::sendTestEmail - CSRF Token recebido: " . (!empty($csrfToken) ? 'SIM' : 'NÃO'));
        
        if (!Security::verifyCSRFToken($csrfToken)) {
            error_log("SuperAdminController::sendTestEmail - ERRO: Token CSRF inválido");
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/email-templates/' . $id . '/edit');
            exit;
        }
        
        error_log("SuperAdminController::sendTestEmail - Validações passadas, continuando...");

        $template = $this->emailTemplateModel->findById($id);
        if (!$template || $template['is_base_layout']) {
            $_SESSION['error'] = 'Template não encontrado.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        // Get recipient email (default to superAdmin email)
        $user = AuthMiddleware::user();
        $recipientEmail = $_POST['test_email'] ?? $user['email'];
        
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'admin/email-templates/' . $id . '/edit');
            exit;
        }

        // Generate sample data for the template
        $sampleData = $this->getSampleDataForTemplate($template['template_key']);

        // Render and send email
        $emailService = new \App\Core\EmailService();
        
        // Try to render from database templates
        $html = $emailService->renderTemplate($template['template_key'], $sampleData);
        $text = $emailService->renderTextTemplate($template['template_key'], $sampleData);
        
        // Debug logging
        error_log("SuperAdminController::sendTestEmail - Template key: {$template['template_key']}");
        error_log("SuperAdminController::sendTestEmail - HTML length: " . strlen($html));
        error_log("SuperAdminController::sendTestEmail - Text length: " . strlen($text));
        
        // If template not found in database or empty, show error
        if (empty($html)) {
            error_log("SuperAdminController::sendTestEmail - ERROR: Template renderizado está vazio!");
            $_SESSION['error'] = 'Não foi possível renderizar o template. Verifique se o template base está configurado e se o template específico existe no banco de dados.';
            header('Location: ' . BASE_URL . 'admin/email-templates/' . $id . '/edit');
            exit;
        }

        // Prepare subject
        $subject = $template['subject'] ?? 'Email de Teste: ' . $template['name'];
        foreach ($sampleData as $key => $value) {
            $subject = str_replace('{' . $key . '}', $value, $subject);
        }

        error_log("SuperAdminController::sendTestEmail - Enviando email para: {$recipientEmail}");
        error_log("SuperAdminController::sendTestEmail - Assunto: [TESTE] {$subject}");

        // Send test email (bypass user preferences for test emails)
        $sent = $emailService->sendEmail(
            $recipientEmail,
            '[TESTE] ' . $subject,
            $html,
            $text,
            null,
            null // No userId to bypass preferences
        );
        
        error_log("SuperAdminController::sendTestEmail - Resultado do envio: " . ($sent ? 'SUCESSO' : 'FALHA'));

        if ($sent) {
            // Check if email was redirected in development
            $appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? 'development');
            $isDevelopment = (strtolower($appEnv) === 'development');
            $devEmail = $_ENV['DEV_EMAIL'] ?? '';
            
            if ($isDevelopment && !empty($devEmail)) {
                $_SESSION['success'] = "Email de teste enviado com sucesso! Em desenvolvimento, o email foi redirecionado para: {$devEmail} (destinatário original: {$recipientEmail})";
            } else {
                $_SESSION['success'] = "Email de teste enviado com sucesso para {$recipientEmail}!";
            }
        } else {
            // Get last error from logs or provide helpful message
            $errorMsg = 'Erro ao enviar email de teste. ';
            
            // Check if DEV_EMAIL is set in development
            $appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? 'development');
            if (strtolower($appEnv) === 'development') {
                $devEmail = $_ENV['DEV_EMAIL'] ?? '';
                if (empty($devEmail)) {
                    $errorMsg .= 'Em desenvolvimento, configure DEV_EMAIL no arquivo .env. ';
                } else {
                    $errorMsg .= "Em desenvolvimento, o email será redirecionado para: {$devEmail}. ";
                }
            }
            
            $errorMsg .= 'Verifique os logs (logs/php_error.log) para mais detalhes. Verifique também as configurações SMTP no arquivo .env (SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD).';
            $_SESSION['error'] = $errorMsg;
        }

        header('Location: ' . BASE_URL . 'admin/email-templates/' . $id . '/edit');
        exit;
    }

    /**
     * Send test email for base layout
     */
    public function sendTestBaseLayout()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/email-templates/base/edit');
            exit;
        }

        $baseLayout = $this->emailTemplateModel->getBaseLayout();
        if (!$baseLayout) {
            $_SESSION['error'] = 'Template base não encontrado.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        // Get recipient email (default to superAdmin email)
        $user = AuthMiddleware::user();
        $recipientEmail = $_POST['test_email'] ?? $user['email'];
        
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Email inválido.';
            header('Location: ' . BASE_URL . 'admin/email-templates/base/edit');
            exit;
        }

        // Sample body content
        $sampleBody = '<div class="greeting">Olá João Silva!</div>
<div class="message">Este é um exemplo de conteúdo do email que será inserido no placeholder {body}.</div>
<div style="text-align: center; margin: 30px 0;">
    <a href="#" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Exemplo de Botão</a>
</div>';

        // Replace placeholders
        $html = $baseLayout['html_body'];
        $html = str_replace('{body}', $sampleBody, $html);
        $html = str_replace('{subject}', 'Email de Teste - Layout Base', $html);
        $html = str_replace('{baseUrl}', BASE_URL, $html);
        $html = str_replace('{logoUrl}', '<div style="color: white; font-size: 24px; font-weight: bold;">O MEU PRÉDIO</div>', $html);
        $html = str_replace('{currentYear}', date('Y'), $html);
        $html = str_replace('{companyName}', 'O Meu Prédio', $html);

        // Send test email
        $emailService = new \App\Core\EmailService();
        $sent = $emailService->sendEmail(
            $recipientEmail,
            '[TESTE] Email de Teste - Layout Base',
            $html,
            null,
            null,
            null
        );

        if ($sent) {
            // Check if email was redirected in development
            $appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? 'development');
            $isDevelopment = (strtolower($appEnv) === 'development');
            $devEmail = $_ENV['DEV_EMAIL'] ?? '';
            
            if ($isDevelopment && !empty($devEmail)) {
                $_SESSION['success'] = "Email de teste enviado com sucesso! Em desenvolvimento, o email foi redirecionado para: {$devEmail} (destinatário original: {$recipientEmail})";
            } else {
                $_SESSION['success'] = "Email de teste enviado com sucesso para {$recipientEmail}!";
            }
        } else {
            // Get last error from logs or provide helpful message
            $errorMsg = 'Erro ao enviar email de teste. ';
            
            // Check if DEV_EMAIL is set in development
            $appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? 'development');
            if (strtolower($appEnv) === 'development') {
                $devEmail = $_ENV['DEV_EMAIL'] ?? '';
                if (empty($devEmail)) {
                    $errorMsg .= 'Em desenvolvimento, configure DEV_EMAIL no arquivo .env. ';
                } else {
                    $errorMsg .= "Em desenvolvimento, o email será redirecionado para: {$devEmail}. ";
                }
            }
            
            $errorMsg .= 'Verifique os logs (logs/php_error.log) para mais detalhes. Verifique também as configurações SMTP no arquivo .env (SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD).';
            $_SESSION['error'] = $errorMsg;
        }

        header('Location: ' . BASE_URL . 'admin/email-templates/base/edit');
        exit;
    }

    /**
     * Preview base layout
     */
    public function previewBaseLayout()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $baseLayout = $this->emailTemplateModel->getBaseLayout();
        if (!$baseLayout) {
            $_SESSION['error'] = 'Template base não encontrado.';
            header('Location: ' . BASE_URL . 'admin/email-templates');
            exit;
        }

        // Sample body content
        $sampleBody = '<div class="greeting">Olá João Silva!</div>
<div class="message">Este é um exemplo de conteúdo do email que será inserido no placeholder {body}.</div>
<div class="button-container">
    <a href="#" class="button" style="background: #F98E13; color: #ffffff !important; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; display: inline-block;">Exemplo de Botão</a>
</div>';

        // Replace placeholders
        $html = $baseLayout['html_body'];
        $html = str_replace('{body}', $sampleBody, $html);
        $html = str_replace('{subject}', 'Exemplo de Assunto', $html);
        $html = str_replace('{baseUrl}', BASE_URL, $html);
        $html = str_replace('{logoUrl}', '<div style="color: white; font-size: 24px; font-weight: bold;">O MEU PRÉDIO</div>', $html);
        $html = str_replace('{currentYear}', date('Y'), $html);
        $html = str_replace('{companyName}', 'O Meu Prédio', $html);

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/admin/email-templates/preview.html.twig',
            'page' => ['titulo' => 'Preview: Layout Base'],
            'template' => $baseLayout,
            'html' => $html,
            'text' => null,
            'sampleData' => ['body' => $sampleBody],
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Get sample data for template preview
     */
    protected function getSampleDataForTemplate(string $templateKey): array
    {
        $samples = [
            'welcome' => [
                'nome' => 'João Silva',
                'verificationUrl' => BASE_URL . 'verify-email?token=sample_token',
                'baseUrl' => BASE_URL
            ],
            'approval' => [
                'nome' => 'João Silva',
                'baseUrl' => BASE_URL
            ],
            'password_reset' => [
                'nome' => 'João Silva',
                'resetUrl' => BASE_URL . 'reset-password?token=sample_token',
                'baseUrl' => BASE_URL
            ],
            'password_reset_success' => [
                'nome' => 'João Silva',
                'baseUrl' => BASE_URL
            ],
            'new_user_notification' => [
                'userEmail' => 'novo@exemplo.com',
                'userName' => 'João Silva',
                'userId' => '123',
                'adminUrl' => BASE_URL . 'dashboard/approvals',
                'baseUrl' => BASE_URL
            ],
            'notification' => [
                'nome' => 'João Silva',
                'notificationType' => 'assembly',
                'title' => 'Nova Assembleia Agendada',
                'message' => 'Uma assembleia foi agendada para o próximo mês.',
                'link' => BASE_URL . 'condominiums/1/assemblies/1',
                'baseUrl' => BASE_URL
            ],
            'message' => [
                'nome' => 'João Silva',
                'senderName' => 'Maria Santos',
                'subject' => 'Mensagem de Teste',
                'messageContent' => 'Esta é uma mensagem de exemplo.',
                'link' => BASE_URL . 'condominiums/1/messages/1',
                'isAnnouncement' => false,
                'baseUrl' => BASE_URL
            ],
            'fraction_assignment' => [
                'nome' => 'João Silva',
                'condominiumName' => 'Condomínio Exemplo',
                'fractionIdentifier' => 'A-101',
                'link' => BASE_URL . 'condominiums/1/fractions/1',
                'baseUrl' => BASE_URL
            ],
            'subscription_renewal_reminder' => [
                'nome' => 'João Silva',
                'planName' => 'Plano PRO',
                'expirationDate' => date('d/m/Y', strtotime('+7 days')),
                'daysLeft' => 7,
                'monthlyPrice' => 29.99,
                'link' => BASE_URL . 'subscription',
                'baseUrl' => BASE_URL
            ],
            'subscription_expired' => [
                'nome' => 'João Silva',
                'planName' => 'Plano PRO',
                'link' => BASE_URL . 'subscription',
                'baseUrl' => BASE_URL
            ]
        ];

        return $samples[$templateKey] ?? [
            'nome' => 'João Silva',
            'baseUrl' => BASE_URL
        ];
    }


    /**
     * Get and clear session messages
     * Helper method to ensure messages are only shown once
     */
    protected function getSessionMessages(): array
    {
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        $info = $_SESSION['info'] ?? null;

        // Clear messages after retrieving
        unset($_SESSION['error'], $_SESSION['success'], $_SESSION['info']);

        return [
            'error' => $error,
            'success' => $success,
            'info' => $info
        ];
    }

    /**
     * Delete admin with only trial subscription
     */
    public function deleteAdminWithTrial()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        // Verify CSRF token
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Token CSRF inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $currentUserId = AuthMiddleware::userId();

        // Security checks
        if ($userId === 0) {
            $_SESSION['error'] = 'ID de utilizador inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        if ($userId === $currentUserId) {
            $_SESSION['error'] = 'Não pode apagar o seu próprio utilizador.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        global $db;

        // Get user
        $user = $this->userModel->findById($userId);
        if (!$user) {
            $_SESSION['error'] = 'Utilizador não encontrado.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        // Verify user is admin
        if ($user['role'] !== 'admin') {
            $_SESSION['error'] = 'Apenas admins com trial podem ser apagados através desta função.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        // Verify user has only trial (no active subscription)
        $trialStmt = $db->prepare("
            SELECT COUNT(*) as trial_count
            FROM subscriptions
            WHERE user_id = :user_id AND status = 'trial'
        ");
        $trialStmt->execute([':user_id' => $userId]);
        $trialCount = (int)$trialStmt->fetch()['trial_count'];

        $activeStmt = $db->prepare("
            SELECT COUNT(*) as active_count
            FROM subscriptions
            WHERE user_id = :user_id AND status = 'active'
        ");
        $activeStmt->execute([':user_id' => $userId]);
        $activeCount = (int)$activeStmt->fetch()['active_count'];

        if ($trialCount === 0 || $activeCount > 0) {
            $_SESSION['error'] = 'Este utilizador não tem apenas trial ou tem subscrições ativas.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        try {
            // Get all condominiums created by this admin
            $condoStmt = $db->prepare("SELECT id, name FROM condominiums WHERE user_id = :user_id");
            $condoStmt->execute([':user_id' => $userId]);
            $condominiums = $condoStmt->fetchAll() ?: [];

            $deletionService = new CondominiumDeletionService();
            $deletedCondominiums = [];

            // Delete each condominium and all related data
            foreach ($condominiums as $condominium) {
                try {
                    $deletionService->deleteCondominiumData($condominium['id'], false);
                    $deletedCondominiums[] = $condominium['name'];
                } catch (\Exception $e) {
                    error_log("Error deleting condominium {$condominium['id']}: " . $e->getMessage());
                    // Continue with other condominiums
                }
            }

            // Delete all subscriptions
            $db->prepare("DELETE FROM subscriptions WHERE user_id = :user_id")->execute([':user_id' => $userId]);

            // Delete user-related data
            $db->prepare("DELETE FROM notifications WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            $db->prepare("DELETE FROM user_email_preferences WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Delete messages sent by user (if any)
            // Note: Messages in condominiums owned by this user are already deleted when condominiums are deleted
            // But if user sent messages in other condominiums, delete them
            $db->prepare("DELETE FROM messages WHERE from_user_id = :user_id")->execute([':user_id' => $userId]);

            // Delete data where user is referenced without CASCADE/SET NULL
            // These might exist in condominiums where user is a member (not owner)
            // Occurrences where user is reported_by (outside owned condominiums)
            $db->prepare("DELETE FROM occurrences WHERE reported_by = :user_id")->execute([':user_id' => $userId]);
            
            // Expenses created by user (outside owned condominiums)
            $db->prepare("DELETE FROM expenses WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Contracts created by user (outside owned condominiums)
            $db->prepare("DELETE FROM contracts WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Standalone votes created by user (outside owned condominiums)
            $db->prepare("DELETE FROM standalone_votes WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Assembly vote topics created by user (outside owned condominiums)
            $db->prepare("DELETE FROM assembly_vote_topics WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Assemblies created by user (outside owned condominiums)
            $db->prepare("DELETE FROM assemblies WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Revenues created by user (outside owned condominiums)
            $db->prepare("DELETE FROM revenues WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Documents uploaded by user (outside owned condominiums)
            $documentsStmt = $db->prepare("SELECT file_path FROM documents WHERE uploaded_by = :user_id");
            $documentsStmt->execute([':user_id' => $userId]);
            $documents = $documentsStmt->fetchAll();
            foreach ($documents as $document) {
                if (!empty($document['file_path'])) {
                    $basePath = __DIR__ . '/../../storage';
                    $fullPath = $basePath . '/' . $document['file_path'];
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            $db->prepare("DELETE FROM documents WHERE uploaded_by = :user_id")->execute([':user_id' => $userId]);
            
            // Reservations by user (outside owned condominiums)
            $db->prepare("DELETE FROM reservations WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Payments by user (if any remain)
            $db->prepare("DELETE FROM payments WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Folders created by user (outside owned condominiums)
            $db->prepare("DELETE FROM folders WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Invitations created by user
            $db->prepare("DELETE FROM invitations WHERE created_by = :user_id")->execute([':user_id' => $userId]);

            // Delete user
            $db->prepare("DELETE FROM users WHERE id = :user_id")->execute([':user_id' => $userId]);

            // Log audit
            $this->logAudit([
                'action' => 'delete',
                'model' => 'user',
                'model_id' => $userId,
                'description' => "Admin trial apagado: {$user['name']} ({$user['email']}). Condomínios removidos: " . implode(', ', $deletedCondominiums)
            ]);

            $_SESSION['success'] = 'Admin trial e todos os condomínios associados foram removidos com sucesso.';
            
        } catch (\Exception $e) {
            error_log("Error deleting admin trial {$userId}: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao apagar admin trial: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/users');
        exit;
    }

    /**
     * Delete user without active plan
     */
    public function deleteUserWithoutActivePlan()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        // Verify CSRF token
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Token CSRF inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $currentUserId = AuthMiddleware::userId();
        $reason = trim($_POST['reason'] ?? '');

        // Security checks
        if ($userId === 0) {
            $_SESSION['error'] = 'ID de utilizador inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        if ($userId === $currentUserId) {
            $_SESSION['error'] = 'Não pode apagar o seu próprio utilizador.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        if (empty($reason) || strlen($reason) < 10) {
            $_SESSION['error'] = 'Deve fornecer um motivo detalhado (mínimo 10 caracteres) para a remoção.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        global $db;

        // Get user
        $user = $this->userModel->findById($userId);
        if (!$user) {
            $_SESSION['error'] = 'Utilizador não encontrado.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        // Verify user is admin
        if ($user['role'] !== 'admin') {
            $_SESSION['error'] = 'Apenas admins sem planos ativos podem ser apagados através desta função.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        // Verify user has no active plan
        $activeStmt = $db->prepare("
            SELECT COUNT(*) as active_count
            FROM subscriptions
            WHERE user_id = :user_id AND status = 'active'
        ");
        $activeStmt->execute([':user_id' => $userId]);
        $activeCount = (int)$activeStmt->fetch()['active_count'];

        if ($activeCount > 0) {
            $_SESSION['error'] = 'Este utilizador possui planos ativos. Não é possível apagar.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        try {
            // Get all condominiums created by this admin
            $condoStmt = $db->prepare("SELECT id, name FROM condominiums WHERE user_id = :user_id");
            $condoStmt->execute([':user_id' => $userId]);
            $condominiums = $condoStmt->fetchAll() ?: [];

            $deletionService = new CondominiumDeletionService();
            $deletedCondominiums = [];

            // Delete each condominium and all related data
            foreach ($condominiums as $condominium) {
                try {
                    $deletionService->deleteCondominiumData($condominium['id'], false);
                    $deletedCondominiums[] = $condominium['name'];
                } catch (\Exception $e) {
                    error_log("Error deleting condominium {$condominium['id']}: " . $e->getMessage());
                    // Continue with other condominiums
                }
            }

            // Delete all subscriptions
            $db->prepare("DELETE FROM subscriptions WHERE user_id = :user_id")->execute([':user_id' => $userId]);

            // Delete user-related data
            $db->prepare("DELETE FROM notifications WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            $db->prepare("DELETE FROM user_email_preferences WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Delete messages sent by user (if any)
            // Note: Messages in condominiums owned by this user are already deleted when condominiums are deleted
            // But if user sent messages in other condominiums, delete them
            $db->prepare("DELETE FROM messages WHERE from_user_id = :user_id")->execute([':user_id' => $userId]);

            // Delete data where user is referenced without CASCADE/SET NULL
            // These might exist in condominiums where user is a member (not owner)
            // Occurrences where user is reported_by (outside owned condominiums)
            $db->prepare("DELETE FROM occurrences WHERE reported_by = :user_id")->execute([':user_id' => $userId]);
            
            // Expenses created by user (outside owned condominiums)
            $db->prepare("DELETE FROM expenses WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Contracts created by user (outside owned condominiums)
            $db->prepare("DELETE FROM contracts WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Standalone votes created by user (outside owned condominiums)
            $db->prepare("DELETE FROM standalone_votes WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Assembly vote topics created by user (outside owned condominiums)
            $db->prepare("DELETE FROM assembly_vote_topics WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Assemblies created by user (outside owned condominiums)
            $db->prepare("DELETE FROM assemblies WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Revenues created by user (outside owned condominiums)
            $db->prepare("DELETE FROM revenues WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Documents uploaded by user (outside owned condominiums)
            $documentsStmt = $db->prepare("SELECT file_path FROM documents WHERE uploaded_by = :user_id");
            $documentsStmt->execute([':user_id' => $userId]);
            $documents = $documentsStmt->fetchAll();
            foreach ($documents as $document) {
                if (!empty($document['file_path'])) {
                    $basePath = __DIR__ . '/../../storage';
                    $fullPath = $basePath . '/' . $document['file_path'];
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            $db->prepare("DELETE FROM documents WHERE uploaded_by = :user_id")->execute([':user_id' => $userId]);
            
            // Reservations by user (outside owned condominiums)
            $db->prepare("DELETE FROM reservations WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Payments by user (if any remain)
            $db->prepare("DELETE FROM payments WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Folders created by user (outside owned condominiums)
            $db->prepare("DELETE FROM folders WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Invitations created by user
            $db->prepare("DELETE FROM invitations WHERE created_by = :user_id")->execute([':user_id' => $userId]);

            // Delete user
            $db->prepare("DELETE FROM users WHERE id = :user_id")->execute([':user_id' => $userId]);

            // Log audit with reason
            $this->logAudit([
                'action' => 'delete',
                'model' => 'user',
                'model_id' => $userId,
                'description' => "Admin sem plano ativo apagado: {$user['name']} ({$user['email']}). Condomínios removidos: " . implode(', ', $deletedCondominiums) . ". Motivo: " . $reason
            ]);

            $_SESSION['success'] = 'Admin sem plano ativo e todos os condomínios associados foram removidos com sucesso.';
            
        } catch (\Exception $e) {
            error_log("Error deleting admin without active plan {$userId}: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao apagar admin sem plano ativo: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/users');
        exit;
    }

    /**
     * Delete user without condominium
     */
    public function deleteUserWithoutCondominium()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        // Verify CSRF token
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Token CSRF inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $currentUserId = AuthMiddleware::userId();

        // Security checks
        if ($userId === 0) {
            $_SESSION['error'] = 'ID de utilizador inválido.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        if ($userId === $currentUserId) {
            $_SESSION['error'] = 'Não pode apagar o seu próprio utilizador.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        global $db;

        // Get user
        $user = $this->userModel->findById($userId);
        if (!$user) {
            $_SESSION['error'] = 'Utilizador não encontrado.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        // Verify user is not super_admin
        if ($user['role'] === 'super_admin') {
            $_SESSION['error'] = 'Não é possível apagar super admins através desta função.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        // Verify user has no condominiums
        $condoAsAdminStmt = $db->prepare("SELECT COUNT(*) as count FROM condominiums WHERE user_id = :user_id");
        $condoAsAdminStmt->execute([':user_id' => $userId]);
        $condoAsAdminCount = (int)$condoAsAdminStmt->fetch()['count'];

        $condoAsMemberStmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM condominium_users 
            WHERE user_id = :user_id AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $condoAsMemberStmt->execute([':user_id' => $userId]);
        $condoAsMemberCount = (int)$condoAsMemberStmt->fetch()['count'];

        if ($condoAsAdminCount > 0 || $condoAsMemberCount > 0) {
            $_SESSION['error'] = 'Este utilizador tem condomínios associados. Use a função de apagar admin trial se aplicável.';
            header('Location: ' . BASE_URL . 'admin/users');
            exit;
        }

        try {
            // Delete all subscriptions
            $db->prepare("DELETE FROM subscriptions WHERE user_id = :user_id")->execute([':user_id' => $userId]);

            // Delete user-related data that has CASCADE or SET NULL
            $db->prepare("DELETE FROM notifications WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            $db->prepare("DELETE FROM user_email_preferences WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Delete messages sent by user (if any)
            $db->prepare("DELETE FROM messages WHERE from_user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Delete any remaining condominium_users associations (shouldn't exist, but just in case)
            $db->prepare("DELETE FROM condominium_users WHERE user_id = :user_id")->execute([':user_id' => $userId]);

            // Delete data where user is referenced without CASCADE/SET NULL
            // Occurrences where user is reported_by
            $db->prepare("DELETE FROM occurrences WHERE reported_by = :user_id")->execute([':user_id' => $userId]);
            
            // Expenses created by user
            $db->prepare("DELETE FROM expenses WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Contracts created by user
            $db->prepare("DELETE FROM contracts WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Standalone votes created by user
            $db->prepare("DELETE FROM standalone_votes WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Assembly vote topics created by user
            $db->prepare("DELETE FROM assembly_vote_topics WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Assemblies created by user
            $db->prepare("DELETE FROM assemblies WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Revenues created by user
            $db->prepare("DELETE FROM revenues WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Documents uploaded by user
            $documentsStmt = $db->prepare("SELECT file_path FROM documents WHERE uploaded_by = :user_id");
            $documentsStmt->execute([':user_id' => $userId]);
            $documents = $documentsStmt->fetchAll();
            foreach ($documents as $document) {
                if (!empty($document['file_path'])) {
                    $basePath = __DIR__ . '/../../storage';
                    $fullPath = $basePath . '/' . $document['file_path'];
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            $db->prepare("DELETE FROM documents WHERE uploaded_by = :user_id")->execute([':user_id' => $userId]);
            
            // Reservations by user
            $db->prepare("DELETE FROM reservations WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Payments by user (if any remain)
            $db->prepare("DELETE FROM payments WHERE user_id = :user_id")->execute([':user_id' => $userId]);
            
            // Folders created by user
            $db->prepare("DELETE FROM folders WHERE created_by = :user_id")->execute([':user_id' => $userId]);
            
            // Invitations created by user
            $db->prepare("DELETE FROM invitations WHERE created_by = :user_id")->execute([':user_id' => $userId]);

            // Delete user
            $db->prepare("DELETE FROM users WHERE id = :user_id")->execute([':user_id' => $userId]);

            // Log audit
            $this->logAudit([
                'action' => 'delete',
                'model' => 'user',
                'model_id' => $userId,
                'description' => "Utilizador sem condomínio apagado: {$user['name']} ({$user['email']})"
            ]);

            $_SESSION['success'] = 'Utilizador removido com sucesso.';
            
        } catch (\Exception $e) {
            error_log("Error deleting user without condominium {$userId}: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao apagar utilizador: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin/users');
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
