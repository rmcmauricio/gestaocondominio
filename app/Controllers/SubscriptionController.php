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
use App\Models\SubscriptionCondominium;
use App\Models\Condominium;
use App\Services\SubscriptionService;
use App\Services\AuditService;
use App\Services\PaymentService;
use App\Services\InvoiceService;
use App\Services\LicenseService;
use App\Services\PricingService;

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
    protected $licenseService;
    protected $pricingService;

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
        $this->licenseService = new LicenseService();
        $this->pricingService = new PricingService();
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

        // Block demo user from accessing subscription page
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A conta demo não pode acessar a página de subscrição.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);
        $pendingSubscription = $this->subscriptionModel->getPendingSubscription($userId);
        $plans = $this->planModel->getActivePlans();
        
        // Get plan details with new fields
        $subscriptionPlan = null;
        if ($subscription) {
            $planDetails = $this->planModel->findById($subscription['plan_id']);
            if ($planDetails) {
                $subscription['plan_type'] = $planDetails['plan_type'] ?? null;
                $subscription['allow_multiple_condos'] = $planDetails['allow_multiple_condos'] ?? false;
                $subscriptionPlan = $planDetails;
            }
        }
        
        // Convert features to readable format
        $plans = $this->convertFeaturesToReadable($plans);

        // Get extra condominiums pricing for Business plan
        $businessPlan = null;
        $extraCondominiumsPricing = [];
        $planPromotions = [];
        $planPricingTiers = []; // Store pricing tiers for license-based plans
        
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
            
            // Get pricing tiers for license-based plans (including condominio type)
            if (!empty($plan['plan_type'])) {
                $pricingTierModel = new \App\Models\PlanPricingTier();
                $tiers = $pricingTierModel->getByPlanId($plan['id'], true);
                if (!empty($tiers)) {
                    $planPricingTiers[$plan['id']] = $tiers;
                }
            }
        }

        // Calculate total price if subscription exists and is Business plan
        $totalPrice = null;
        $extraCondominiumsPrice = 0;
        $hasPendingExtraUpdate = false;
        
        // Check for pending invoices (for both Business plan extra condominiums and license-based plans extra licenses)
        $invoiceModel = new \App\Models\Invoice();
        $hasPendingExtraUpdate = false;
        $hasPendingLicenseAddition = false;
        $pendingLicenseAddition = null;
        
        if ($subscription) {
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
                
                if ($metadata) {
                    // Check for Business plan extra condominiums update
                    if (isset($metadata['is_extra_update']) && $metadata['is_extra_update']) {
                        $hasPendingExtraUpdate = true;
                    }
                    
                    // Check for license addition
                    if (isset($metadata['is_license_addition']) && $metadata['is_license_addition']) {
                        $hasPendingLicenseAddition = true;
                        $pendingLicenseAddition = [
                            'additional_licenses' => (int)($metadata['additional_licenses'] ?? 0),
                            'invoice_id' => $pendingInvoice['id'],
                            'amount' => $pendingInvoice['amount']
                        ];
                    }
                }
            }
        }
        
        if ($subscription && $subscription['plan_slug'] === 'business') {
            $extraCondominiums = (int)($subscription['extra_condominiums'] ?? 0);
            $displayExtraCondominiums = $extraCondominiums;
            
            if ($hasPendingExtraUpdate && $pendingInvoice) {
                $metadata = null;
                if (isset($pendingInvoice['metadata']) && $pendingInvoice['metadata']) {
                    $metadata = is_string($pendingInvoice['metadata']) ? json_decode($pendingInvoice['metadata'], true) : $pendingInvoice['metadata'];
                } elseif (isset($pendingInvoice['notes']) && $pendingInvoice['notes']) {
                    $decoded = json_decode($pendingInvoice['notes'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $metadata = $decoded;
                    }
                }
                
                if ($metadata && isset($metadata['new_extra_condominiums'])) {
                    $displayExtraCondominiums = (int)($metadata['new_extra_condominiums']);
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
        $pendingExtraLicenses = 0;
        $pendingExtraLicensesPrice = 0;
        
        if ($pendingSubscription) {
            $pendingPlan = $this->planModel->findById($pendingSubscription['plan_id']);
            $pendingPlanType = $pendingPlan['plan_type'] ?? null;
            
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
                // Business plan: extra condominiums
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
            } elseif ($pendingPlanType) {
                // License-based plan (including condominio): calculate price based on total licenses (included + extras)
                $licenseMin = (int)($pendingPlan['license_min'] ?? 0);
                $pendingExtraLicenses = (int)($pendingSubscription['extra_licenses'] ?? 0);
                $totalLicenses = $licenseMin + $pendingExtraLicenses;
                
                if ($totalLicenses > $licenseMin) {
                    // Calculate price for total licenses using tiered pricing
                    $pricingBreakdown = $this->pricingService->getPriceBreakdown(
                        $pendingSubscription['plan_id'],
                        $totalLicenses,
                        $pendingPlan['pricing_mode'] ?? 'flat'
                    );
                    $pendingTotalPrice = $pricingBreakdown['total'];
                    $pendingExtraLicensesPrice = $pendingTotalPrice - $discountedBasePrice;
                } else {
                    $pendingTotalPrice = $discountedBasePrice;
                }
            } else {
                $pendingTotalPrice = $discountedBasePrice;
            }
        }

        // Get license and condominium information for active subscription
        $associatedCondominiums = [];
        $usedLicenses = 0;
        $licenseLimit = null;
        $pricingPreview = null;
        
        // Get user's condominiums for attach modal
        $condominiumModel = new Condominium();
        $userCondominiums = $condominiumModel->getByUserId($userId);
        
        if ($subscription) {
            $subscriptionCondominiumModel = new SubscriptionCondominium();
            $associatedCondominiums = $subscriptionCondominiumModel->getActiveBySubscription($subscription['id']);
            
            // Get plan details for subscription
            $subscriptionPlan = $this->planModel->findById($subscription['plan_id']);
            
            // For Base plan, also check direct condominium_id
            if ($subscription['condominium_id']) {
                $condo = $condominiumModel->findById($subscription['condominium_id']);
                if ($condo) {
                    // Check if not already in list
                    $found = false;
                    foreach ($associatedCondominiums as $ac) {
                        if ($ac['condominium_id'] == $condo['id']) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $associatedCondominiums[] = [
                            'condominium_id' => $condo['id'],
                            'condominium_name' => $condo['name'],
                            'condominium_address' => $condo['address']
                        ];
                    }
                }
            }
            
            // Calculate used licenses dynamically (actual active fractions, not cached value)
            // This ensures we always show the real count, not the cached value
            $usedLicenses = $this->subscriptionModel->calculateUsedLicenses($subscription['id']);
            $licenseLimit = $subscription['license_limit'] ?? null;
            
            // Update cache with correct value for future use
            if ($usedLicenses != ($subscription['used_licenses'] ?? 0)) {
                $this->subscriptionModel->updateUsedLicenses($subscription['id'], $usedLicenses);
            }
            
            // Get pricing preview
            try {
                $pricingPreview = $this->subscriptionService->getCurrentCharge($subscription['id']);
            } catch (\Exception $e) {
                // Ignore errors in preview
            }
        }

        $this->data += [
            'viewName' => 'pages/subscription/index.html.twig',
            'page' => [
                'titulo' => 'Subscrição',
                'description' => 'Manage your subscription'
            ],
            'subscription' => $subscription,
            'subscription_plan' => $subscriptionPlan,
            'pending_subscription' => $pendingSubscription,
            'plans' => $plans,
            'business_plan' => $businessPlan,
            'extra_condominiums_pricing' => $extraCondominiumsPricing,
            'plan_promotions' => $planPromotions,
            'plan_pricing_tiers' => $planPricingTiers,
            'total_price' => $totalPrice,
            'extra_condominiums_price' => $extraCondominiumsPrice,
            'pending_total_price' => $pendingTotalPrice,
            'pending_extra_condominiums_price' => $pendingExtraCondominiumsPrice,
            'pending_extra_licenses' => $pendingExtraLicenses,
            'pending_extra_licenses_price' => $pendingExtraLicensesPrice,
            'has_pending_extra_update' => $hasPendingExtraUpdate,
            'has_pending_license_addition' => $hasPendingLicenseAddition,
            'pending_license_addition' => $pendingLicenseAddition,
            'display_extra_condominiums' => isset($displayExtraCondominiums) ? $displayExtraCondominiums : ($subscription['extra_condominiums'] ?? 0),
            'associated_condominiums' => $associatedCondominiums,
            'used_licenses' => $usedLicenses,
            'license_limit' => $licenseLimit,
            'pricing_preview' => $pricingPreview,
            'subscription_plan' => $subscriptionPlan ?? null,
            'user' => AuthMiddleware::user(),
            'error' => $error,
            'success' => $success,
            'info' => $info,
            'csrf_token' => Security::generateCSRFToken()
        ];
        
        // Add user condominiums to user object for modal
        if (isset($this->data['user'])) {
            $this->data['user']['condominiums'] = $userCondominiums;
        }

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function choosePlan()
    {
        // Block demo user from accessing plan selection
        if (AuthMiddleware::handle()) {
            if ($this->isDemoUser()) {
                $_SESSION['error'] = 'A conta demo não pode acessar a página de subscrição.';
                header('Location: ' . BASE_URL . 'dashboard');
                exit;
            }
        }
        
        // Allow both guests and logged in users
        $plans = $this->planModel->getActivePlans();
        
        // Convert features to readable format
        $plans = $this->convertFeaturesToReadable($plans);

        // Get extra condominiums pricing for Business plan
        $businessPlan = null;
        $extraCondominiumsPricing = [];
        $planPromotions = [];
        $planPricingTiers = []; // Store pricing tiers for license-based plans
        
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
            
            // Get pricing tiers for license-based plans (including condominio type)
            if (!empty($plan['plan_type'])) {
                $pricingTierModel = new \App\Models\PlanPricingTier();
                $tiers = $pricingTierModel->getByPlanId($plan['id'], true);
                if (!empty($tiers)) {
                    $planPricingTiers[$plan['id']] = $tiers;
                }
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
            'plan_pricing_tiers' => $planPricingTiers,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Check if current user is demo user
     */
    protected function isDemoUser(): bool
    {
        $userId = AuthMiddleware::userId();
        return \App\Middleware\DemoProtectionMiddleware::isDemoUser($userId);
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
     * Cancel pending license addition (cancel pending invoice)
     */
    public function cancelPendingLicenseAddition()
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
        if (!$plan || empty($plan['plan_type'])) {
            $_SESSION['error'] = 'Frações extras só estão disponíveis em planos baseados em licenças.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        try {
            // Find pending invoice with license addition
            $invoiceModel = new \App\Models\Invoice();
            $pendingInvoice = $invoiceModel->getPendingBySubscriptionId($subscription['id']);

            if (!$pendingInvoice) {
                $_SESSION['info'] = 'Não há pagamentos pendentes para cancelar.';
                header('Location: ' . BASE_URL . 'subscription');
                exit;
            }

            // Check if invoice has metadata about license addition
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
                $_SESSION['error'] = 'Esta invoice não está relacionada com adição de frações extras.';
                header('Location: ' . BASE_URL . 'subscription');
                exit;
            }

            // Check if there are any completed payments associated with this invoice
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
                SET status = 'canceled' 
                WHERE invoice_id = :invoice_id 
                AND status IN ('pending', 'failed')
            ");
            $cancelPaymentsStmt->execute([':invoice_id' => $pendingInvoice['id']]);

            // Cancel the invoice
            $cancelStmt = $db->prepare("
                UPDATE invoices 
                SET status = 'canceled', 
                    updated_at = NOW() 
                WHERE id = :id AND status = 'pending'
            ");
            $cancelStmt->execute([':id' => $pendingInvoice['id']]);

            // Log the cancellation
            $this->auditService->logSubscription([
                'subscription_id' => $subscription['id'],
                'user_id' => $userId,
                'action' => 'license_addition_canceled',
                'description' => "Cancelamento de adição de frações extras. Invoice #{$pendingInvoice['invoice_number']} cancelada."
            ]);

            $_SESSION['success'] = 'Pagamento pendente cancelado com sucesso.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        } catch (\Exception $e) {
            error_log("Error canceling pending license addition: " . $e->getMessage());
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
     * Update extra licenses for pending subscription
     */
    public function updatePendingLicenses()
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
        if (!$plan || empty($plan['plan_type'])) {
            $_SESSION['error'] = 'Frações extras só estão disponíveis em planos baseados em licenças.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $newExtraLicenses = max(0, intval($_POST['extra_licenses'] ?? 0));
        $currentExtraLicenses = (int)($pendingSubscription['extra_licenses'] ?? 0);
        $licenseMin = (int)($plan['license_min'] ?? 0);
        $totalLicenses = $licenseMin + $newExtraLicenses;

        // If no change, just redirect
        if ($newExtraLicenses === $currentExtraLicenses) {
            $_SESSION['info'] = 'Não houve alterações nas frações extras.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Calculate base price with promotion discount (if promotion is active)
        $basePrice = $pendingSubscription['price_monthly'];
        $discountedBasePrice = $basePrice;
        
        if (isset($pendingSubscription['promotion_id']) && $pendingSubscription['promotion_id']) {
            $promotionModel = new \App\Models\Promotion();
            $promo = $promotionModel->findById($pendingSubscription['promotion_id']);
            if ($promo && $promo['is_active']) {
                $originalPrice = $pendingSubscription['original_price_monthly'] ?? $basePrice;
                if ($promo['discount_type'] === 'percentage') {
                    $discount = ($originalPrice * $promo['discount_value']) / 100;
                    $discountedBasePrice = max(0, $originalPrice - $discount);
                } else {
                    $discountedBasePrice = max(0, $originalPrice - $promo['discount_value']);
                }
            }
        }

        // Calculate total price using tiered pricing for total licenses
        $pricingBreakdown = $this->pricingService->getPriceBreakdown(
            $pendingSubscription['plan_id'],
            $totalLicenses,
            $plan['pricing_mode'] ?? 'flat'
        );
        $totalAmount = $pricingBreakdown['total'];

        // Update pending subscription
        $this->subscriptionModel->update($pendingSubscription['id'], [
            'extra_licenses' => $newExtraLicenses
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
            'action' => 'pending_licenses_updated',
            'description' => "Frações extras atualizadas na subscrição pendente: {$currentExtraLicenses} → {$newExtraLicenses} (Total: {$totalLicenses})"
        ]);

        $_SESSION['success'] = 'Frações extras atualizadas com sucesso!';
        header('Location: ' . BASE_URL . 'subscription');
        exit;
    }

    /**
     * Add extra licenses to active subscription
     */
    public function addActiveLicenses()
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
            $_SESSION['error'] = 'Não há subscrição ativa.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $plan = $this->planModel->findById($subscription['plan_id']);
        if (!$plan || empty($plan['plan_type'])) {
            $_SESSION['error'] = 'Frações extras só estão disponíveis em planos baseados em licenças.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $additionalLicenses = max(0, intval($_POST['extra_licenses'] ?? 0));
        if ($additionalLicenses <= 0) {
            $_SESSION['error'] = 'Deve adicionar pelo menos 1 fração extra.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $licenseMin = (int)($plan['license_min'] ?? 0);
        $currentExtraLicenses = (int)($subscription['extra_licenses'] ?? 0);
        $currentLicenseLimit = (int)($subscription['license_limit'] ?? $licenseMin);
        $newExtraLicenses = $currentExtraLicenses + $additionalLicenses;
        
        // Calculate new total licenses: license_min + newExtraLicenses
        // This ensures consistency: license_limit = license_min + extra_licenses
        $totalLicenses = $licenseMin + $newExtraLicenses;
        
        // Current total licenses (for price calculation)
        // Use license_min + currentExtraLicenses to ensure consistency
        $currentTotalLicenses = $licenseMin + $currentExtraLicenses;

        // Validate license availability
        // Pass true for isAddingExtras to skip minimum check (allow any number of extras up to limit)
        $validation = $this->licenseService->validateLicenseAvailability($subscription['id'], $additionalLicenses, true);
        if (!$validation['available']) {
            $_SESSION['error'] = $validation['reason'];
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Calculate price for additional licenses only
        // For flat pricing: charge additional licenses at the CURRENT tier price, not the new tier price
        // This ensures users pay for extras at the price they're currently paying
        
        // Get current tier to determine price per license for additional licenses
        $currentTier = $this->planModel->getTierForLicenses($subscription['plan_id'], $currentTotalLicenses);
        if (!$currentTier) {
            $_SESSION['error'] = 'Erro ao encontrar tier de preço atual.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
        
        $pricePerLicense = (float)$currentTier['price_per_license'];
        
        // Calculate additional amount: additional licenses * current tier price
        $additionalAmount = $additionalLicenses * $pricePerLicense;

        if ($additionalAmount <= 0) {
            $_SESSION['error'] = 'Erro ao calcular o preço adicional.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Create invoice for additional licenses FIRST (before updating subscription)
        // Don't update subscription until payment is confirmed
        global $db;
        $db->beginTransaction();
        
        try {
            // Create invoice for additional licenses
            $this->invoiceService->createInvoice($subscription['id'], $additionalAmount, [
                'is_license_addition' => true,
                'additional_licenses' => $additionalLicenses,
                'old_extra_licenses' => $currentExtraLicenses,
                'new_extra_licenses' => $newExtraLicenses,
                'old_total_licenses' => $currentTotalLicenses,
                'new_total_licenses' => $totalLicenses
            ]);

            $db->commit();

            // Log the addition
            $this->auditService->logSubscription([
                'subscription_id' => $subscription['id'],
                'user_id' => $userId,
                'action' => 'active_licenses_added',
                'description' => "Pedido de adição de frações extras à subscrição ativa: +{$additionalLicenses} frações (Total: {$currentTotalLicenses} → {$totalLicenses}). Pagamento necessário para ativar."
            ]);

            $_SESSION['success'] = "{$additionalLicenses} fração(ões) extra(s) adicionada(s) com sucesso! Efetue o pagamento para ativar.";
            header('Location: ' . BASE_URL . 'payments/' . $subscription['id'] . '/create');
            exit;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error adding active licenses: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao adicionar frações extras: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Método não permitido', 405, 'INVALID_METHOD');
        }

        $planId = (int)($_POST['plan_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        
        if (!$planId || !$code) {
            $this->jsonError('Dados inválidos', 400, 'INVALID_DATA');
        }

        $plan = $this->planModel->findById($planId);
        if (!$plan) {
            $this->jsonError('Plano não encontrado', 404, 'PLAN_NOT_FOUND');
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

    /**
     * Show attach condominium page
     */
    public function attachCondominiumView()
    {
        AuthMiddleware::require();

        // Block demo user
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A conta demo não pode acessar esta página.';
            header('Location: ' . BASE_URL . 'dashboard');
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
        if (!$plan) {
            $_SESSION['error'] = 'Plano não encontrado.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Get available condominiums (not already associated)
        $condominiumModel = new Condominium();
        $userCondominiums = $condominiumModel->getByUserId($userId);
        
        $subscriptionCondominiumModel = new SubscriptionCondominium();
        $associatedCondominiums = $subscriptionCondominiumModel->getActiveBySubscription($subscription['id']);
        $associatedIds = array_column($associatedCondominiums, 'condominium_id');
        
        // Also check Base plan direct association
        if ($subscription['condominium_id']) {
            $associatedIds[] = $subscription['condominium_id'];
        }

        $availableCondominiums = [];
        foreach ($userCondominiums as $condo) {
            if (!in_array($condo['id'], $associatedIds)) {
                $fractionModel = new Fraction();
                $condo['active_fractions_count'] = $fractionModel->getActiveCountByCondominium($condo['id']);
                $availableCondominiums[] = $condo;
            }
        }

        $usedLicenses = $subscription['used_licenses'] ?? 0;
        $licenseLimit = $subscription['license_limit'] ?? null;

        $this->loadPageTranslations('subscription');

        $this->data += [
            'viewName' => 'pages/subscription/attach-condominium.html.twig',
            'page' => [
                'titulo' => 'Associar Condomínio',
                'description' => 'Attach condominium to subscription'
            ],
            'subscription' => $subscription,
            'plan' => $plan,
            'available_condominiums' => $availableCondominiums,
            'used_licenses' => $usedLicenses,
            'license_limit' => $licenseLimit,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Attach condominium to subscription
     */
    public function attachCondominium()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Block demo user
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A conta demo não pode acessar esta página.';
            header('Location: ' . BASE_URL . 'dashboard');
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

        $condominiumId = (int)($_POST['condominium_id'] ?? 0);
        if (!$condominiumId) {
            $_SESSION['error'] = 'Condomínio não especificado.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        try {
            $this->subscriptionService->attachCondominium($subscription['id'], $condominiumId, $userId);
            $_SESSION['success'] = 'Condomínio associado com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao associar condomínio: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'subscription');
        exit;
    }

    /**
     * Detach condominium from subscription
     */
    public function detachCondominium()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Block demo user
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A conta demo não pode acessar esta página.';
            header('Location: ' . BASE_URL . 'dashboard');
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

        $condominiumId = (int)($_POST['condominium_id'] ?? 0);
        if (!$condominiumId) {
            $_SESSION['error'] = 'Condomínio não especificado.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $reason = trim($_POST['reason'] ?? '');

        try {
            $this->subscriptionService->detachCondominium($subscription['id'], $condominiumId, $userId, $reason);
            $_SESSION['success'] = 'Condomínio desassociado com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao desassociar condomínio: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'subscription');
        exit;
    }

    /**
     * Get pricing preview (AJAX)
     */
    public function pricingPreview()
    {
        AuthMiddleware::require();

        // Block demo user
        if ($this->isDemoUser()) {
            header('Content-Type: application/json');
            $this->jsonError('A conta demo não pode acessar esta página', 403, 'DEMO_USER_BLOCKED');
        }

        header('Content-Type: application/json');

        $userId = AuthMiddleware::userId();
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);

        if (!$subscription) {
            $this->jsonError('Subscrição não encontrada', 404, 'SUBSCRIPTION_NOT_FOUND');
        }

        $projectedUnits = isset($_GET['projected_units']) ? (int)$_GET['projected_units'] : null;

        try {
            $preview = $this->subscriptionService->getSubscriptionPricingPreview($subscription['id'], $projectedUnits);
            $this->jsonSuccess($preview);
        } catch (\Exception $e) {
            $this->jsonError($e, 500, 'PRICING_PREVIEW_ERROR');
        }
        exit;
    }

    /**
     * Recalculate licenses manually
     */
    public function recalculateLicenses()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        // Block demo user
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A conta demo não pode acessar esta página.';
            header('Location: ' . BASE_URL . 'dashboard');
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

        try {
            $count = $this->subscriptionService->recalculateUsedLicenses($subscription['id']);
            $_SESSION['success'] = "Licenças recalculadas: {$count} licenças ativas.";
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao recalcular licenças: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'subscription');
        exit;
    }
}

