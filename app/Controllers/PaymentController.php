<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\InvoiceService;

class PaymentController extends Controller
{
    protected $subscriptionModel;
    protected $planModel;
    protected $paymentModel;
    protected $paymentService;
    protected $invoiceService;

    public function __construct()
    {
        parent::__construct();
        $this->subscriptionModel = new Subscription();
        $this->planModel = new Plan();
        $this->paymentModel = new Payment();
        $this->paymentService = new PaymentService();
        $this->invoiceService = new InvoiceService();
    }

    /**
     * Show payment method selection page
     */
    public function create(int $subscriptionId)
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        
        if (!$subscription || $subscription['user_id'] != $userId) {
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

        // Calculate total amount including extra condominiums
        // Check if there's a pending invoice with extra condominiums update
        $invoiceModel = new \App\Models\Invoice();
        $pendingInvoice = $invoiceModel->getPendingBySubscriptionId($subscriptionId);
        
        // Check if pending invoice is for extra condominiums update
        $isExtraUpdateInvoice = false;
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
                $isExtraUpdateInvoice = true;
            }
        }
        
        // Allow payment for:
        // 1. Trial users (even if trial hasn't expired yet)
        // 2. Canceled subscriptions
        // 3. Active subscriptions that are expiring soon (within 7 days)
        // 4. Active subscriptions with pending invoice for extra condominiums update
        // 5. Pending subscriptions (plan change)
        
        $isActive = $subscription['status'] === 'active';
        $isTrial = $subscription['status'] === 'trial';
        $isCanceled = $subscription['status'] === 'canceled';
        $isPending = $subscription['status'] === 'pending';
        
        // Block payment only if subscription is active, not expiring soon, AND no pending extra update invoice, AND not pending
        if ($isActive && strtotime($subscription['current_period_end']) > strtotime('+7 days') && !$isExtraUpdateInvoice && !$isPending) {
            $_SESSION['info'] = 'A sua subscrição está ativa até ' . date('d/m/Y', strtotime($subscription['current_period_end'])) . '.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
        
        // Allow trial users to pay anytime (before or after trial expires)
        // Allow canceled subscriptions to pay
        // Allow active subscriptions expiring soon to pay
        // Allow active subscriptions with pending extra update invoice to pay
        // Allow pending subscriptions to pay (plan change)
        
        $amount = $plan['price_monthly'];
        $extraCondominiums = (int)($subscription['extra_condominiums'] ?? 0);
        $extraCondominiumsPrice = 0;
        $pricePerCondominium = null;
        $newExtraCondominiums = $extraCondominiums;
        
        // Check if invoice has metadata about new extras or plan change
        $metadata = null;
        $isPlanChange = false;
        if ($pendingInvoice) {
            if (isset($pendingInvoice['metadata']) && $pendingInvoice['metadata']) {
                $metadata = is_string($pendingInvoice['metadata']) ? json_decode($pendingInvoice['metadata'], true) : $pendingInvoice['metadata'];
            } elseif (isset($pendingInvoice['notes']) && $pendingInvoice['notes']) {
                $decoded = json_decode($pendingInvoice['notes'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $metadata = $decoded;
                }
            }
            
            if ($metadata) {
                if (isset($metadata['is_extra_update']) && $metadata['is_extra_update']) {
                    $newExtraCondominiums = (int)($metadata['new_extra_condominiums'] ?? $extraCondominiums);
                    $amount = $pendingInvoice['amount']; // Use invoice amount which already includes the new extras
                }
                if (isset($metadata['is_plan_change']) && $metadata['is_plan_change']) {
                    $isPlanChange = true;
                    $amount = $pendingInvoice['amount']; // Use invoice amount which already includes promotion discount
                }
            }
        }
        
        // For pending subscriptions, use invoice amount if available
        // Note: Pending subscriptions created via changePlan will have is_plan_change=true in metadata
        if ($isPending && $pendingInvoice && !$isPlanChange) {
            $amount = $pendingInvoice['amount'];
        }
        
        // If it's a pending subscription created via changePlan, mark as plan change
        if ($isPending && $pendingInvoice && isset($metadata['is_plan_change']) && $metadata['is_plan_change']) {
            $isPlanChange = true;
            $amount = $pendingInvoice['amount'];
        }
        
        // Initialize base amount calculation
        $originalBaseAmount = $plan['price_monthly'];
        $baseAmount = $originalBaseAmount;
        $hasPromotion = false;
        $promotionDiscount = 0;
        
        // Calculate extras price if Business plan
        if ($plan['slug'] === 'business' && $newExtraCondominiums > 0) {
            $extraCondominiumsPricingModel = new \App\Models\PlanExtraCondominiumsPricing();
            $pricePerCondominium = $extraCondominiumsPricingModel->getPriceForCondominiums(
                $plan['id'], 
                $newExtraCondominiums
            );
            if ($pricePerCondominium !== null) {
                $extraCondominiumsPrice = $pricePerCondominium * $newExtraCondominiums;
            }
        }
        
        // Calculate base amount based on scenario
        if (($isPlanChange && $pendingInvoice) || ($isPending && $pendingInvoice)) {
            // For plan changes or pending subscriptions, invoice amount already includes discounted base + extras
            // First, check if subscription has promotion to determine original price
            if (isset($subscription['promotion_id']) && $subscription['promotion_id'] && isset($subscription['original_price_monthly'])) {
                $originalBaseAmount = $subscription['original_price_monthly'];
            }
            
            // Calculate base amount from invoice (already discounted)
            if ($plan['slug'] === 'business' && $newExtraCondominiums > 0 && isset($extraCondominiumsPrice)) {
                $baseAmount = $amount - $extraCondominiumsPrice;
            } else {
                $baseAmount = $amount;
            }
            
            // Check for promotion in subscription
            if (isset($subscription['promotion_id']) && $subscription['promotion_id']) {
                $promotionModel = new \App\Models\Promotion();
                $promotion = $promotionModel->findById($subscription['promotion_id']);
                
                if ($promotion && $promotion['is_active']) {
                    // For pending subscriptions, promotion is considered active if it exists
                    // For active subscriptions, check if promotion hasn't expired
                    $now = date('Y-m-d H:i:s');
                    $promotionActive = false;
                    
                    if ($isPending) {
                        // Pending subscriptions: promotion is active if promotion exists and is_active
                        $promotionActive = true;
                    } elseif (isset($subscription['promotion_ends_at']) && $subscription['promotion_ends_at'] && $subscription['promotion_ends_at'] >= $now) {
                        // Active subscriptions: check expiration date
                        $promotionActive = true;
                    }
                    
                    if ($promotionActive && isset($subscription['original_price_monthly']) && $subscription['original_price_monthly']) {
                        $originalBaseAmount = $subscription['original_price_monthly'];
                        $hasPromotion = true;
                        
                        // Always recalculate discount from promotion to ensure accuracy
                        $priceToDiscount = $subscription['original_price_monthly'];
                        if ($promotion['discount_type'] === 'percentage') {
                            $promotionDiscount = ($priceToDiscount * $promotion['discount_value']) / 100;
                            $calculatedBaseAmount = max(0, $priceToDiscount - $promotionDiscount);
                        } else {
                            $promotionDiscount = $promotion['discount_value'];
                            $calculatedBaseAmount = max(0, $priceToDiscount - $promotionDiscount);
                        }
                        
                        // Use calculated base amount if it matches invoice amount (within tolerance)
                        // Otherwise, use baseAmount from invoice and calculate discount difference
                        if ($plan['slug'] === 'business' && $newExtraCondominiums > 0 && isset($extraCondominiumsPrice)) {
                            $expectedTotal = $calculatedBaseAmount + $extraCondominiumsPrice;
                            // If invoice amount matches expected total, use calculated values
                            if (abs($amount - $expectedTotal) < 0.01) {
                                $baseAmount = $calculatedBaseAmount;
                            }
                            // Otherwise, keep baseAmount from invoice and use calculated discount
                        } else {
                            // If invoice amount matches calculated base amount, use calculated values
                            if (abs($amount - $calculatedBaseAmount) < 0.01) {
                                $baseAmount = $calculatedBaseAmount;
                            }
                            // Otherwise, keep baseAmount from invoice and use calculated discount
                        }
                    }
                }
            }
        } else {
            // For regular subscriptions, check for active promotion
            if (isset($subscription['promotion_id']) && $subscription['promotion_id']) {
                $now = date('Y-m-d H:i:s');
                if (isset($subscription['promotion_ends_at']) && $subscription['promotion_ends_at'] && $subscription['promotion_ends_at'] >= $now) {
                    $promotionModel = new \App\Models\Promotion();
                    $promotion = $promotionModel->findById($subscription['promotion_id']);
                    
                    if ($promotion && $promotion['is_active']) {
                        $hasPromotion = true;
                        $priceToDiscount = $subscription['original_price_monthly'] ?? $originalBaseAmount;
                        
                        if ($promotion['discount_type'] === 'percentage') {
                            $promotionDiscount = ($priceToDiscount * $promotion['discount_value']) / 100;
                            $baseAmount = max(0, $priceToDiscount - $promotionDiscount);
                        } else {
                            $promotionDiscount = $promotion['discount_value'];
                            $baseAmount = max(0, $priceToDiscount - $promotionDiscount);
                        }
                        
                        if (isset($subscription['original_price_monthly']) && $subscription['original_price_monthly']) {
                            $originalBaseAmount = $subscription['original_price_monthly'];
                        }
                    }
                }
            }
            
            // For Business plan with extras, adjust amount
            if ($plan['slug'] === 'business' && $newExtraCondominiums > 0 && isset($extraCondominiumsPrice)) {
                if (!isset($metadata['is_extra_update']) || !$metadata['is_extra_update']) {
                    // Only add extras to amount if not already set from invoice
                    $amount = $baseAmount + $extraCondominiumsPrice;
                } else {
                    // For extra updates, calculate base amount from total
                    $baseAmount = $amount - $extraCondominiumsPrice;
                }
            }
        }
        
        $paymentMethods = $this->paymentService->getAvailablePaymentMethods();

        $this->loadPageTranslations('payments');
        
        $this->data += [
            'viewName' => 'pages/payments/create.html.twig',
            'page' => ['titulo' => 'Efetuar Pagamento'],
            'subscription' => $subscription,
            'plan' => $plan,
            'amount' => $amount,
            'base_amount' => $baseAmount,
            'original_base_amount' => $originalBaseAmount,
            'has_promotion' => $hasPromotion,
            'promotion_discount' => $promotionDiscount,
            'extra_condominiums' => $newExtraCondominiums,
            'extra_condominiums_price' => $extraCondominiumsPrice,
            'price_per_condominium' => $pricePerCondominium,
            'is_extra_update' => isset($metadata['is_extra_update']) && $metadata['is_extra_update'],
            'is_plan_change' => $isPlanChange,
            'payment_methods' => $paymentMethods,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Process payment method selection
     */
    public function process(int $subscriptionId)
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/create');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        
        if (!$subscription || $subscription['user_id'] != $userId) {
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

        $paymentMethod = Security::sanitize($_POST['payment_method'] ?? '');
        
        // Check if there's a pending invoice with extra condominiums update
        $invoiceModel = new \App\Models\Invoice();
        $pendingInvoice = $invoiceModel->getPendingBySubscriptionId($subscriptionId);
        
        // Calculate total amount including extra condominiums
        $amount = $plan['price_monthly'];
        $extraCondominiums = (int)($subscription['extra_condominiums'] ?? 0);
        $newExtraCondominiums = $extraCondominiums;
        
        // Check if invoice has metadata about new extras
        if ($pendingInvoice) {
            // Try to get metadata from notes field if metadata column doesn't exist
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
                $newExtraCondominiums = (int)($metadata['new_extra_condominiums'] ?? $extraCondominiums);
                $amount = $pendingInvoice['amount']; // Use invoice amount which already includes the new extras
            }
        }
        
        $extraCondominiumsPrice = 0;
        $pricePerCondominium = null;
        
        if ($plan['slug'] === 'business' && $newExtraCondominiums > 0) {
            $extraCondominiumsPricingModel = new \App\Models\PlanExtraCondominiumsPricing();
            $pricePerCondominium = $extraCondominiumsPricingModel->getPriceForCondominiums(
                $plan['id'], 
                $newExtraCondominiums
            );
            if ($pricePerCondominium !== null) {
                $extraCondominiumsPrice = $pricePerCondominium * $newExtraCondominiums;
                // Only add to amount if not already set from invoice
                if (!$metadata || !isset($metadata['is_extra_update']) || !$metadata['is_extra_update']) {
                    $amount += $extraCondominiumsPrice;
                } else {
                    // Calculate from invoice amount (invoice already has the correct total)
                    $extraCondominiumsPrice = $amount - $plan['price_monthly'];
                }
            }
        }
        
        // Use existing invoice if available, otherwise create new one
        if ($pendingInvoice) {
            $invoiceId = $pendingInvoice['id'];
        } else {
            $invoiceId = $this->invoiceService->createInvoice($subscriptionId, $amount);
        }
        
        // Pass invoice ID to view for reference
        $this->data['invoice_id'] = $invoiceId;

        try {
            switch ($paymentMethod) {
                case 'multibanco':
                    $result = $this->paymentService->generateMultibancoReference($amount, $subscriptionId, $invoiceId);
                    $_SESSION['payment_data'] = $result;
                    header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/multibanco');
                    exit;

                case 'mbway':
                    $phone = Security::sanitize($_POST['phone'] ?? '');
                    if (empty($phone)) {
                        $_SESSION['error'] = 'Por favor, indique o número de telemóvel.';
                        header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/create');
                        exit;
                    }
                    $result = $this->paymentService->generateMBWayPayment($amount, $phone, $subscriptionId, $invoiceId);
                    $_SESSION['payment_data'] = $result;
                    header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/mbway');
                    exit;

                case 'sepa':
                    $bankData = [
                        'iban' => Security::sanitize($_POST['iban'] ?? ''),
                        'account_holder' => Security::sanitize($_POST['account_holder'] ?? ''),
                        'bic' => Security::sanitize($_POST['bic'] ?? '')
                    ];
                    $result = $this->paymentService->generateSEPAMandate($amount, $bankData, $subscriptionId, $invoiceId);
                    $_SESSION['payment_data'] = $result;
                    header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/sepa');
                    exit;

                case 'direct_debit':
                    $bankData = [
                        'iban' => Security::sanitize($_POST['iban'] ?? ''),
                        'account_holder' => Security::sanitize($_POST['account_holder'] ?? ''),
                        'bic' => Security::sanitize($_POST['bic'] ?? '')
                    ];
                    if (empty($bankData['iban']) || empty($bankData['account_holder'])) {
                        $_SESSION['error'] = 'Por favor, preencha todos os dados bancários.';
                        header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/create');
                        exit;
                    }
                    $result = $this->paymentService->generateDirectDebitPayment($amount, $bankData, $subscriptionId, $invoiceId);
                    $_SESSION['payment_data'] = $result;
                    header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/direct-debit');
                    exit;

                default:
                    $_SESSION['error'] = 'Método de pagamento inválido.';
                    header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/create');
                    exit;
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao processar pagamento: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/create');
            exit;
        }
    }

    /**
     * Show Multibanco payment details
     */
    public function showMultibanco(int $subscriptionId)
    {
        AuthMiddleware::require();

        $paymentData = $_SESSION['payment_data'] ?? null;
        if (!$paymentData) {
            $_SESSION['error'] = 'Dados de pagamento não encontrados.';
            header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/create');
            exit;
        }

        $subscription = $this->subscriptionModel->findById($subscriptionId);
        $plan = $subscription ? $this->planModel->findById($subscription['plan_id']) : null;

        $this->loadPageTranslations('payments');
        
        $this->data += [
            'viewName' => 'pages/payments/multibanco.html.twig',
            'page' => ['titulo' => 'Pagamento Multibanco'],
            'subscription' => $subscription,
            'plan' => $plan,
            'payment_data' => $paymentData,
            'csrf_token' => Security::generateCSRFToken()
        ];

        unset($_SESSION['payment_data']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show MBWay payment details
     */
    public function showMBWay(int $subscriptionId)
    {
        AuthMiddleware::require();

        $paymentData = $_SESSION['payment_data'] ?? null;
        if (!$paymentData) {
            $_SESSION['error'] = 'Dados de pagamento não encontrados.';
            header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/create');
            exit;
        }

        $subscription = $this->subscriptionModel->findById($subscriptionId);
        $plan = $subscription ? $this->planModel->findById($subscription['plan_id']) : null;

        $this->loadPageTranslations('payments');
        
        $this->data += [
            'viewName' => 'pages/payments/mbway.html.twig',
            'page' => ['titulo' => 'Pagamento MBWay'],
            'subscription' => $subscription,
            'plan' => $plan,
            'payment_data' => $paymentData,
            'csrf_token' => Security::generateCSRFToken()
        ];

        unset($_SESSION['payment_data']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show SEPA payment details
     */
    public function showSEPA(int $subscriptionId)
    {
        AuthMiddleware::require();

        $paymentData = $_SESSION['payment_data'] ?? null;
        if (!$paymentData) {
            $_SESSION['error'] = 'Dados de pagamento não encontrados.';
            header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/create');
            exit;
        }

        $subscription = $this->subscriptionModel->findById($subscriptionId);
        $plan = $subscription ? $this->planModel->findById($subscription['plan_id']) : null;

        $this->loadPageTranslations('payments');
        
        $this->data += [
            'viewName' => 'pages/payments/sepa.html.twig',
            'page' => ['titulo' => 'Débito Direto SEPA'],
            'subscription' => $subscription,
            'plan' => $plan,
            'payment_data' => $paymentData,
            'csrf_token' => Security::generateCSRFToken()
        ];

        unset($_SESSION['payment_data']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show Direct Debit payment details
     */
    public function directDebit(int $subscriptionId)
    {
        AuthMiddleware::require();

        $paymentData = $_SESSION['payment_data'] ?? null;
        if (!$paymentData) {
            $_SESSION['error'] = 'Dados de pagamento não encontrados.';
            header('Location: ' . BASE_URL . 'payments/' . $subscriptionId . '/create');
            exit;
        }

        $subscription = $this->subscriptionModel->findById($subscriptionId);
        $plan = $subscription ? $this->planModel->findById($subscription['plan_id']) : null;

        $this->loadPageTranslations('payments');
        
        $this->data += [
            'viewName' => 'pages/payments/direct-debit.html.twig',
            'page' => ['titulo' => 'Débito Direto IfthenPay'],
            'subscription' => $subscription,
            'plan' => $plan,
            'payment_data' => $paymentData,
            'csrf_token' => Security::generateCSRFToken()
        ];

        unset($_SESSION['payment_data']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * List user payments
     */
    public function index()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $payments = $this->paymentModel->getByUserId($userId);

        // Check for pending invoices related to extra condominiums updates
        // that don't have payments associated yet
        $pendingInvoicesForExtras = [];
        $subscription = $this->subscriptionModel->getActiveSubscription($userId);
        
        if ($subscription) {
            $invoiceModel = new \App\Models\Invoice();
            $pendingInvoice = $invoiceModel->getPendingBySubscriptionId($subscription['id']);
            
            if ($pendingInvoice) {
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
                
                if ($metadata && isset($metadata['is_extra_update']) && $metadata['is_extra_update']) {
                    // Check if there are any payments for this invoice
                    global $db;
                    $paymentCheck = $db->prepare("SELECT COUNT(*) as count FROM payments WHERE invoice_id = :invoice_id");
                    $paymentCheck->execute([':invoice_id' => $pendingInvoice['id']]);
                    $paymentResult = $paymentCheck->fetch();
                    
                    // Only show cancel option if no payments exist yet
                    if ($paymentResult && $paymentResult['count'] == 0) {
                        $pendingInvoicesForExtras[$pendingInvoice['id']] = [
                            'invoice_id' => $pendingInvoice['id'],
                            'invoice_number' => $pendingInvoice['invoice_number'],
                            'amount' => $pendingInvoice['amount'],
                            'new_extra_condominiums' => $metadata['new_extra_condominiums'] ?? 0
                        ];
                    }
                }
            }
        }

        $this->loadPageTranslations('payments');
        
        $this->data += [
            'viewName' => 'pages/payments/index.html.twig',
            'page' => ['titulo' => 'Meus Pagamentos'],
            'payments' => $payments,
            'pending_invoices_for_extras' => $pendingInvoicesForExtras,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

