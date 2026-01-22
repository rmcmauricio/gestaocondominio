<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\PlanExtraCondominiumsPricing;
use App\Models\Promotion;
use App\Services\SubscriptionService;
use App\Services\AuditService;
use App\Services\PaymentService;
use App\Services\InvoiceService;

class SubscriptionController extends Controller
{
    protected $planModel;
    protected $subscriptionModel;
    protected $extraCondominiumsPricingModel;
    protected $promotionModel;
    protected $subscriptionService;
    protected $auditService;
    protected $paymentService;
    protected $invoiceService;

    public function __construct()
    {
        parent::__construct();
        $this->planModel = new Plan();
        $this->subscriptionModel = new Subscription();
        $this->extraCondominiumsPricingModel = new PlanExtraCondominiumsPricing();
        $this->promotionModel = new Promotion();
        $this->subscriptionService = new SubscriptionService();
        $this->auditService = new AuditService();
        $this->paymentService = new PaymentService();
        $this->invoiceService = new InvoiceService();
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
        $pendingSubscription = $this->subscriptionModel->getPendingSubscription($userId);
        $plans = $this->planModel->getActivePlans();
        
        // Convert features to readable format
        $plans = $this->convertFeaturesToReadable($plans);

        // Get extra condominiums pricing for Business plan
        $businessPlan = null;
        $extraCondominiumsPricing = [];
        $planPromotions = [];
        
        foreach ($plans as $plan) {
            // Get visible promotions for each plan
            $visiblePromotion = $this->promotionModel->getVisibleForPlan($plan['id']);
            if ($visiblePromotion) {
                $planPromotions[$plan['id']] = $visiblePromotion;
            }
            
            if ($plan['slug'] === 'business') {
                $businessPlan = $plan;
                $extraCondominiumsPricing = $this->extraCondominiumsPricingModel->getByPlanId($plan['id']);
            }
        }

        // Calculate total price if subscription exists and is Business plan
        $totalPrice = null;
        $extraCondominiumsPrice = 0;
        $hasPendingExtraUpdate = false;
        
        if ($subscription && $subscription['plan_slug'] === 'business') {
            // Check if there's a pending invoice with extra update
            $invoiceModel = new \App\Models\Invoice();
            $pendingInvoice = $invoiceModel->getPendingBySubscriptionId($subscription['id']);
            
            $extraCondominiums = (int)($subscription['extra_condominiums'] ?? 0);
            $displayExtraCondominiums = $extraCondominiums;
            
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
                
                if ($metadata && isset($metadata['is_extra_update']) && $metadata['is_extra_update']) {
                    $displayExtraCondominiums = (int)($metadata['new_extra_condominiums'] ?? $extraCondominiums);
                    $hasPendingExtraUpdate = true;
                }
            }
            
            if ($displayExtraCondominiums > 0) {
                $pricePerCondominium = $this->extraCondominiumsPricingModel->getPriceForCondominiums(
                    $subscription['plan_id'], 
                    $displayExtraCondominiums
                );
                if ($pricePerCondominium !== null) {
                    $extraCondominiumsPrice = $pricePerCondominium * $displayExtraCondominiums;
                }
            }
            $totalPrice = $subscription['price_monthly'] + $extraCondominiumsPrice;
        }

        $this->loadPageTranslations('subscription');
        
        // Get session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        $info = $_SESSION['info'] ?? null;
        
        // Clear session messages after retrieving
        unset($_SESSION['error'], $_SESSION['success'], $_SESSION['info']);
        
        // Calculate total price for pending subscription if exists
        $pendingTotalPrice = null;
        $pendingExtraCondominiumsPrice = 0;
        
        if ($pendingSubscription) {
            // Calculate base price with promotion discount (if promotion is active)
            $basePrice = $pendingSubscription['price_monthly']; // Price from plan
            $discountedBasePrice = $basePrice;
            
            // If there's a promotion, calculate discounted price
            if (isset($pendingSubscription['promotion_id']) && $pendingSubscription['promotion_id']) {
                $promo = $planPromotions[$pendingSubscription['plan_id']] ?? null;
                if ($promo) {
                    $originalPrice = $pendingSubscription['original_price_monthly'] ?? $basePrice;
                    if ($promo['discount_type'] === 'percentage') {
                        $discount = ($originalPrice * $promo['discount_value']) / 100;
                        $discountedBasePrice = max(0, $originalPrice - $discount);
                    } else {
                        $discountedBasePrice = max(0, $originalPrice - $promo['discount_value']);
                    }
                }
            }
            
            if ($pendingSubscription['plan_slug'] === 'business') {
                $pendingExtraCondominiums = (int)($pendingSubscription['extra_condominiums'] ?? 0);
                if ($pendingExtraCondominiums > 0) {
                    $pricePerCondominium = $this->extraCondominiumsPricingModel->getPriceForCondominiums(
                        $pendingSubscription['plan_id'], 
                        $pendingExtraCondominiums
                    );
                    if ($pricePerCondominium !== null) {
                        // Extras are NOT discounted
                        $pendingExtraCondominiumsPrice = $pricePerCondominium * $pendingExtraCondominiums;
                    }
                }
                // Total = discounted base price + full price extras
                $pendingTotalPrice = $discountedBasePrice + $pendingExtraCondominiumsPrice;
            } else {
                $pendingTotalPrice = $discountedBasePrice;
            }
        }

        $this->data += [
            'viewName' => 'pages/subscription/index.html.twig',
            'page' => [
                'titulo' => 'Subscrição',
                'description' => 'Manage your subscription'
            ],
            'subscription' => $subscription,
            'pending_subscription' => $pendingSubscription,
            'plans' => $plans,
            'business_plan' => $businessPlan,
            'extra_condominiums_pricing' => $extraCondominiumsPricing,
            'plan_promotions' => $planPromotions,
            'total_price' => $totalPrice,
            'extra_condominiums_price' => $extraCondominiumsPrice,
            'pending_total_price' => $pendingTotalPrice,
            'pending_extra_condominiums_price' => $pendingExtraCondominiumsPrice,
            'has_pending_extra_update' => $hasPendingExtraUpdate,
            'display_extra_condominiums' => isset($displayExtraCondominiums) ? $displayExtraCondominiums : ($subscription['extra_condominiums'] ?? 0),
            'error' => $error,
            'success' => $success,
            'info' => $info,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function choosePlan()
    {
        // Block demo user from accessing plan selection
        if (AuthMiddleware::handle() && $this->isDemoUser()) {
            $_SESSION['error'] = 'A conta demo não pode alterar a subscrição.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
        
        // Allow both guests and logged in users
        $plans = $this->planModel->getActivePlans();
        
        // Convert features to readable format
        $plans = $this->convertFeaturesToReadable($plans);

        // Get extra condominiums pricing for Business plan
        $businessPlan = null;
        $extraCondominiumsPricing = [];
        $planPromotions = [];
        
        foreach ($plans as $plan) {
            // Get visible promotions for each plan
            $visiblePromotion = $this->promotionModel->getVisibleForPlan($plan['id']);
            if ($visiblePromotion) {
                $planPromotions[$plan['id']] = $visiblePromotion;
            }
            
            if ($plan['slug'] === 'business') {
                $businessPlan = $plan;
                $extraCondominiumsPricing = $this->extraCondominiumsPricingModel->getByPlanId($plan['id']);
            }
        }

        $this->loadPageTranslations('subscription');
        
        $this->data += [
            'viewName' => 'pages/subscription/choose-plan.html.twig',
            'page' => [
                'titulo' => 'Escolher Plano - MeuPrédio',
                'description' => 'Escolha o plano ideal para o seu condomínio'
            ],
            'plans' => $plans,
            'business_plan' => $businessPlan,
            'extra_condominiums_pricing' => $extraCondominiumsPricing,
            'plan_promotions' => $planPromotions,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Check if current user is demo user
     */
    protected function isDemoUser(): bool
    {
        $user = AuthMiddleware::user();
        return $user && isset($user['is_demo']) && $user['is_demo'] == true;
    }

    public function startTrial()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Block demo user from changing subscription
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A subscrição da conta demo não pode ser alterada.';
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
            header('Location: ' . BASE_URL . 'subscription/choose-plan');
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

        // Get extra condominiums if Business plan
        $extraCondominiums = 0;
        if ($plan['slug'] === 'business') {
            $extraCondominiums = max(0, intval($_POST['extra_condominiums'] ?? 0));
        }

        // Handle promotion code or visible promotion
        $promotionCode = trim($_POST['promotion_code'] ?? '');
        $promotionId = null;
        $originalPrice = $plan['price_monthly'];
        
        // Check for visible promotion first
        $visiblePromotion = $this->promotionModel->getVisibleForPlan($planId);
        
        if ($visiblePromotion) {
            // Apply visible promotion automatically
            $promotionId = $visiblePromotion['id'];
        } elseif ($promotionCode) {
            // Validate and apply promotion code
            $validation = $this->promotionModel->validateCode($promotionCode, $planId, $userId);
            
            if (!$validation['valid']) {
                $_SESSION['error'] = $validation['error'] ?? 'Código de promoção inválido.';
                header('Location: ' . BASE_URL . 'subscription/choose-plan');
                exit;
            }
            
            $promotionId = $validation['promotion']['id'];
        }

        try {
            $subscriptionId = $this->subscriptionService->startTrial($userId, $planId, 14, $extraCondominiums, $promotionId, $originalPrice);
            
            // Increment promotion usage count if promotion was applied
            if ($promotionId) {
                $this->promotionModel->incrementUsage($promotionId);
            }
            
            $_SESSION['success'] = 'Período experimental iniciado com sucesso!';
            if ($promotionId) {
                $_SESSION['success'] .= ' Promoção aplicada com sucesso!';
            }
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao iniciar período experimental: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'subscription/choose-plan');
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

        // Block demo user from changing subscription
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A subscrição da conta demo não pode ser alterada.';
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

        // Check for visible promotion for the new plan
        $promotionId = null;
        $visiblePromotion = $this->promotionModel->getVisibleForPlan($newPlanId);
        if ($visiblePromotion) {
            $promotionId = $visiblePromotion['id'];
        }

        try {
            $this->subscriptionService->upgrade($userId, $newPlanId, $promotionId);
            
            // Increment promotion usage count if promotion was applied
            if ($promotionId) {
                $this->promotionModel->incrementUsage($promotionId);
            }
            
            $_SESSION['success'] = 'Subscrição atualizada com sucesso!';
            if ($promotionId) {
                $_SESSION['success'] .= ' Promoção aplicada automaticamente.';
            }
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

        // Block demo user from changing subscription
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A subscrição da conta demo não pode ser cancelada.';
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

        $oldStatus = $subscription['status'];
        
        if ($this->subscriptionModel->cancel($subscription['id'])) {
            // Log subscription cancellation
            $this->auditService->logSubscription([
                'subscription_id' => $subscription['id'],
                'user_id' => $userId,
                'action' => 'subscription_canceled',
                'old_status' => $oldStatus,
                'new_status' => 'canceled',
                'description' => "Subscrição cancelada pelo utilizador"
            ]);
            
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

        // Block demo user from changing subscription
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A subscrição da conta demo não pode ser alterada.';
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

        $oldStatus = $subscription['status'];
        $oldPeriodStart = $subscription['current_period_start'] ?? null;
        $oldPeriodEnd = $subscription['current_period_end'] ?? null;
        $now = date('Y-m-d H:i:s');
        $periodEnd = date('Y-m-d H:i:s', strtotime("+1 month"));
        
        if ($this->subscriptionModel->update($subscription['id'], [
            'status' => 'active',
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'canceled_at' => null
        ])) {
            // Log subscription reactivation
            $this->auditService->logSubscription([
                'subscription_id' => $subscription['id'],
                'user_id' => $userId,
                'action' => 'subscription_reactivated',
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'old_period_start' => $oldPeriodStart,
                'new_period_start' => $now,
                'old_period_end' => $oldPeriodEnd,
                'new_period_end' => $periodEnd,
                'description' => "Subscrição reativada pelo utilizador. Novo período: {$now} até {$periodEnd}"
            ]);
            
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

        // Block demo user from changing subscription
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A subscrição da conta demo não pode ser alterada.';
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

        // Get extra condominiums if Business plan
        $extraCondominiums = 0;
        if ($plan['slug'] === 'business') {
            $extraCondominiums = max(0, intval($_POST['extra_condominiums'] ?? 0));
        }

        // Process promotion code or visible promotion
        $promotionId = null;
        $promotionCode = trim($_POST['promotion_code'] ?? '');
        
        // Priority: promotion code > visible promotion
        if ($promotionCode) {
            // Validate promotion code
            $validation = $this->promotionModel->validateCode($promotionCode, $newPlanId, $userId);
            
            if (!$validation['valid']) {
                $_SESSION['error'] = $validation['error'] ?? 'Código de promoção inválido.';
                header('Location: ' . BASE_URL . 'subscription');
                exit;
            }
            
            $promotionId = $validation['promotion']['id'];
        } else {
            // Check for visible promotion for the new plan
            $visiblePromotion = $this->promotionModel->getVisibleForPlan($newPlanId);
            if ($visiblePromotion) {
                $promotionId = $visiblePromotion['id'];
            }
        }

        try {
            $subscriptionId = $this->subscriptionService->changePlan($userId, $newPlanId, $promotionId, $extraCondominiums);
            
            // Increment promotion usage count if promotion was applied
            if ($promotionId) {
                $this->promotionModel->incrementUsage($promotionId);
            }
            
            $_SESSION['success'] = 'Alteração de plano solicitada! Efetue o pagamento para ativar o novo plano.';
            if ($promotionId) {
                if ($promotionCode) {
                    $_SESSION['success'] .= ' Código promocional aplicado com sucesso.';
                } else {
                    $_SESSION['success'] .= ' Promoção aplicada automaticamente.';
                }
            }
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao alterar plano: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
    }

    /**
     * Update extra condominiums for subscription
     * Creates a payment instead of updating directly - extras only become active after payment confirmation
     */
    public function updateExtras()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Block demo user
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A subscrição da conta demo não pode ser alterada.';
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

        $plan = $this->planModel->findById($subscription['plan_id']);
        if (!$plan || $plan['slug'] !== 'business') {
            $_SESSION['error'] = 'Condomínios extras só estão disponíveis no plano Business.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $newExtraCondominiums = max(0, intval($_POST['extra_condominiums'] ?? 0));
        $currentExtraCondominiums = (int)($subscription['extra_condominiums'] ?? 0);

        // If no change, just redirect
        if ($newExtraCondominiums === $currentExtraCondominiums) {
            $_SESSION['info'] = 'Não houve alterações nos condomínios extras.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Calculate the price difference
        $baseAmount = $plan['price_monthly'];
        $newTotalAmount = $baseAmount;
        $currentTotalAmount = $baseAmount;

        if ($newExtraCondominiums > 0) {
            $pricePerCondominium = $this->extraCondominiumsPricingModel->getPriceForCondominiums(
                $plan['id'], 
                $newExtraCondominiums
            );
            if ($pricePerCondominium !== null) {
                $newTotalAmount += $pricePerCondominium * $newExtraCondominiums;
            }
        }

        if ($currentExtraCondominiums > 0) {
            $currentPricePerCondominium = $this->extraCondominiumsPricingModel->getPriceForCondominiums(
                $plan['id'], 
                $currentExtraCondominiums
            );
            if ($currentPricePerCondominium !== null) {
                $currentTotalAmount += $currentPricePerCondominium * $currentExtraCondominiums;
            }
        }

        // Calculate amount to pay (difference or full amount if subscription is trial/expired)
        $amountToPay = $newTotalAmount;
        
        // If subscription is active and not expiring soon, calculate prorated difference
        if ($subscription['status'] === 'active' && 
            strtotime($subscription['current_period_end']) > strtotime('+7 days')) {
            // For now, charge the full new amount - can be improved with proration later
            $amountToPay = $newTotalAmount;
        }

        try {
            // Create invoice with metadata about the new extras
            $invoiceId = $this->invoiceService->createInvoice($subscription['id'], $amountToPay, [
                'new_extra_condominiums' => $newExtraCondominiums,
                'current_extra_condominiums' => $currentExtraCondominiums,
                'is_extra_update' => true
            ]);

            // Log the update request
            $this->auditService->logSubscription([
                'subscription_id' => $subscription['id'],
                'user_id' => $userId,
                'action' => 'extra_condominiums_update_requested',
                'description' => "Pedido de atualização de condomínios extras: {$currentExtraCondominiums} → {$newExtraCondominiums}. Pagamento necessário para ativar."
            ]);

            $_SESSION['success'] = 'Para ativar os condomínios extras, efetue o pagamento.';
            header('Location: ' . BASE_URL . 'payments/' . $subscription['id'] . '/create');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao processar pedido de condomínios extras: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
    }

    /**
     * Cancel pending extra condominiums update (cancel pending invoice)
     */
    public function cancelPendingExtras()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Block demo user
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A subscrição da conta demo não pode ser alterada.';
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

        $plan = $this->planModel->findById($subscription['plan_id']);
        if (!$plan || $plan['slug'] !== 'business') {
            $_SESSION['error'] = 'Condomínios extras só estão disponíveis no plano Business.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        try {
            // Find pending invoice with extra update
            $invoiceModel = new \App\Models\Invoice();
            $pendingInvoice = $invoiceModel->getPendingBySubscriptionId($subscription['id']);

            if (!$pendingInvoice) {
                $_SESSION['info'] = 'Não há pagamentos pendentes para cancelar.';
                header('Location: ' . BASE_URL . 'subscription');
                exit;
            }

            // Check if invoice has metadata about extra update
            $metadata = null;
            if (isset($pendingInvoice['metadata']) && $pendingInvoice['metadata']) {
                $metadata = is_string($pendingInvoice['metadata']) ? json_decode($pendingInvoice['metadata'], true) : $pendingInvoice['metadata'];
            } elseif (isset($pendingInvoice['notes']) && $pendingInvoice['notes']) {
                $decoded = json_decode($pendingInvoice['notes'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $metadata = $decoded;
                }
            }

            if (!$metadata || !isset($metadata['is_extra_update']) || !$metadata['is_extra_update']) {
                $_SESSION['error'] = 'Esta invoice não está relacionada com atualização de condomínios extras.';
                header('Location: ' . BASE_URL . 'subscription');
                exit;
            }

            // Check if there are any completed payments associated with this invoice
            // Only block cancellation if there are completed payments
            global $db;
            $paymentCheck = $db->prepare("
                SELECT COUNT(*) as count 
                FROM payments 
                WHERE invoice_id = :invoice_id 
                AND status = 'completed'
            ");
            $paymentCheck->execute([':invoice_id' => $pendingInvoice['id']]);
            $paymentResult = $paymentCheck->fetch();

            if ($paymentResult && $paymentResult['count'] > 0) {
                $_SESSION['error'] = 'Não é possível cancelar esta invoice porque já existem pagamentos completados associados.';
                header('Location: ' . BASE_URL . 'subscription');
                exit;
            }

            // Cancel any pending/failed payments associated with this invoice
            $cancelPaymentsStmt = $db->prepare("
                UPDATE payments 
                SET status = 'failed', 
                    updated_at = NOW()
                WHERE invoice_id = :invoice_id 
                AND status IN ('pending', 'processing')
            ");
            $cancelPaymentsStmt->execute([':invoice_id' => $pendingInvoice['id']]);

            // Cancel the invoice (mark as canceled)
            $cancelStmt = $db->prepare("
                UPDATE invoices 
                SET status = 'canceled', 
                    updated_at = NOW()
                WHERE id = :id AND status = 'pending'
            ");
            $cancelStmt->execute([':id' => $pendingInvoice['id']]);
            
            // Check if the update was successful
            if ($cancelStmt->rowCount() === 0) {
                $_SESSION['error'] = 'Não foi possível cancelar o pagamento. O pagamento pode já ter sido processado ou cancelado.';
                header('Location: ' . BASE_URL . 'subscription');
                exit;
            }

            // Log the cancellation
            $this->auditService->logSubscription([
                'subscription_id' => $subscription['id'],
                'user_id' => $userId,
                'action' => 'extra_condominiums_update_canceled',
                'description' => "Cancelamento de atualização de condomínios extras. Invoice #{$pendingInvoice['invoice_number']} cancelada."
            ]);

            $_SESSION['success'] = 'Pagamento pendente cancelado com sucesso.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao cancelar pagamento pendente: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
    }

    /**
     * Update extra condominiums for pending subscription
     */
    public function updatePendingExtras()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Block demo user
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A subscrição da conta demo não pode ser alterada.';
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
        $pendingSubscription = $this->subscriptionModel->getPendingSubscription($userId);

        if (!$pendingSubscription) {
            $_SESSION['error'] = 'Não há subscrição pendente.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $plan = $this->planModel->findById($pendingSubscription['plan_id']);
        if (!$plan || $plan['slug'] !== 'business') {
            $_SESSION['error'] = 'Condomínios extras só estão disponíveis no plano Business.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $newExtraCondominiums = max(0, intval($_POST['extra_condominiums'] ?? 0));
        $currentExtraCondominiums = (int)($pendingSubscription['extra_condominiums'] ?? 0);

        // If no change, just redirect
        if ($newExtraCondominiums === $currentExtraCondominiums) {
            $_SESSION['info'] = 'Não houve alterações nos condomínios extras.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Calculate the price
        // Base amount is already discounted if promotion was applied (stored in price_monthly)
        // Extras are NOT discounted - they use full price
        $baseAmount = $pendingSubscription['price_monthly']; // Already discounted if promotion applied
        $totalAmount = $baseAmount;

        // Calculate extras price (extras are NOT discounted - full price)
        if ($newExtraCondominiums > 0) {
            $pricePerCondominium = $this->extraCondominiumsPricingModel->getPriceForCondominiums(
                $plan['id'], 
                $newExtraCondominiums
            );
            if ($pricePerCondominium !== null) {
                // Add full price for extras (no discount applied)
                $totalAmount += $pricePerCondominium * $newExtraCondominiums;
            }
        }

        // Update pending subscription
        $this->subscriptionModel->update($pendingSubscription['id'], [
            'extra_condominiums' => $newExtraCondominiums
        ]);

        // Update or create invoice
        $invoiceModel = new \App\Models\Invoice();
        $pendingInvoice = $invoiceModel->getPendingBySubscriptionId($pendingSubscription['id']);
        
        if ($pendingInvoice) {
            // Update existing invoice
            global $db;
            $updateStmt = $db->prepare("
                UPDATE invoices 
                SET amount = :amount, 
                    total_amount = :total_amount,
                    updated_at = NOW()
                WHERE id = :id AND status = 'pending'
            ");
            $updateStmt->execute([
                ':amount' => $totalAmount,
                ':total_amount' => $totalAmount,
                ':id' => $pendingInvoice['id']
            ]);
        } else {
            // Create new invoice
            $this->invoiceService->createInvoice($pendingSubscription['id'], $totalAmount, [
                'is_plan_change' => true,
                'old_subscription_id' => null,
                'old_plan_id' => null
            ]);
        }

        // Log the update
        $this->auditService->logSubscription([
            'subscription_id' => $pendingSubscription['id'],
            'user_id' => $userId,
            'action' => 'pending_extras_updated',
            'description' => "Condomínios extras atualizados na subscrição pendente: {$currentExtraCondominiums} → {$newExtraCondominiums}"
        ]);

        $_SESSION['success'] = 'Condomínios extras atualizados com sucesso!';
        header('Location: ' . BASE_URL . 'subscription');
        exit;
    }

    /**
     * Cancel pending plan change
     */
    public function cancelPendingPlanChange()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Block demo user
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A subscrição da conta demo não pode ser alterada.';
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
        $pendingSubscription = $this->subscriptionModel->getPendingSubscription($userId);
        $activeSubscription = $this->subscriptionModel->getActiveSubscription($userId);
        $isPlanChange = $activeSubscription !== null;

        if (!$pendingSubscription) {
            $_SESSION['info'] = $isPlanChange ? 'Não há alteração de plano pendente para cancelar.' : 'Não há subscrição pendente para cancelar.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        try {
            global $db;
            
            // Cancel any pending invoices for this subscription
            $cancelInvoicesStmt = $db->prepare("
                UPDATE invoices 
                SET status = 'canceled', 
                    updated_at = NOW()
                WHERE subscription_id = :subscription_id 
                AND status = 'pending'
            ");
            $cancelInvoicesStmt->execute([':subscription_id' => $pendingSubscription['id']]);

            // Cancel any pending/failed payments associated with invoices
            $cancelPaymentsStmt = $db->prepare("
                UPDATE payments 
                SET status = 'failed', 
                    updated_at = NOW()
                WHERE invoice_id IN (
                    SELECT id FROM invoices 
                    WHERE subscription_id = :subscription_id 
                    AND status = 'canceled'
                )
                AND status IN ('pending', 'processing')
            ");
            $cancelPaymentsStmt->execute([':subscription_id' => $pendingSubscription['id']]);

            // Delete the pending subscription
            $deleteStmt = $db->prepare("DELETE FROM subscriptions WHERE id = :id AND status = 'pending'");
            $deleteStmt->execute([':id' => $pendingSubscription['id']]);

            if ($deleteStmt->rowCount() === 0) {
                $_SESSION['error'] = $isPlanChange ? 'Não foi possível cancelar a alteração de plano pendente.' : 'Não foi possível cancelar a subscrição pendente.';
                header('Location: ' . BASE_URL . 'subscription');
                exit;
            }

            // Log the cancellation
            $this->auditService->logSubscription([
                'subscription_id' => $pendingSubscription['id'],
                'user_id' => $userId,
                'action' => 'pending_plan_change_canceled',
                'old_status' => 'pending',
                'new_status' => 'deleted',
                'description' => ($isPlanChange ? "Alteração de plano pendente cancelada pelo utilizador" : "Subscrição pendente cancelada pelo utilizador") . ". Plano ID: {$pendingSubscription['plan_id']}"
            ]);

            $_SESSION['success'] = $isPlanChange ? 'Alteração de plano pendente cancelada com sucesso.' : 'Subscrição pendente cancelada com sucesso.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao cancelar alteração pendente: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
    }

    /**
     * Validate promotion code via AJAX
     */
    public function validatePromotionCode()
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['valid' => false, 'error' => 'Método não permitido']);
            exit;
        }

        $planId = (int)($_POST['plan_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        
        if (!$planId || !$code) {
            echo json_encode(['valid' => false, 'error' => 'Dados inválidos']);
            exit;
        }

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            echo json_encode(['valid' => false, 'error' => 'Plano não encontrado']);
            exit;
        }

        $userId = null;
        if (AuthMiddleware::handle()) {
            $userId = AuthMiddleware::userId();
        }

        $validation = $this->promotionModel->validateCode($code, $planId, $userId);
        
        if ($validation['valid']) {
            $promotion = $validation['promotion'];
            $basePrice = $plan['price_monthly'];
            
            // Calculate discounted price
            if ($promotion['discount_type'] === 'percentage') {
                $discount = ($basePrice * $promotion['discount_value']) / 100;
                $finalPrice = max(0, $basePrice - $discount);
            } else {
                $finalPrice = max(0, $basePrice - $promotion['discount_value']);
            }
            
            echo json_encode([
                'valid' => true,
                'promotion' => [
                    'id' => $promotion['id'],
                    'name' => $promotion['name'],
                    'discount_type' => $promotion['discount_type'],
                    'discount_value' => $promotion['discount_value'],
                    'original_price' => $basePrice,
                    'final_price' => $finalPrice,
                    'discount_amount' => $basePrice - $finalPrice
                ]
            ]);
        } else {
            echo json_encode($validation);
        }
        exit;
    }
}

