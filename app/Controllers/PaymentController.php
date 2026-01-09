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

        // Allow payment for:
        // 1. Trial users (even if trial hasn't expired yet)
        // 2. Canceled subscriptions
        // 3. Active subscriptions that are expiring soon (within 7 days)
        
        $isActive = $subscription['status'] === 'active';
        $isTrial = $subscription['status'] === 'trial';
        $isCanceled = $subscription['status'] === 'canceled';
        
        // Block payment only if subscription is active and not expiring soon
        if ($isActive && strtotime($subscription['current_period_end']) > strtotime('+7 days')) {
            $_SESSION['info'] = 'A sua subscrição está ativa até ' . date('d/m/Y', strtotime($subscription['current_period_end'])) . '.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }
        
        // Allow trial users to pay anytime (before or after trial expires)
        // Allow canceled subscriptions to pay
        // Allow active subscriptions expiring soon to pay

        $amount = $plan['price_monthly'];
        $paymentMethods = $this->paymentService->getAvailablePaymentMethods();

        $this->loadPageTranslations('payments');
        
        $this->data += [
            'viewName' => 'pages/payments/create.html.twig',
            'page' => ['titulo' => 'Efetuar Pagamento'],
            'subscription' => $subscription,
            'plan' => $plan,
            'amount' => $amount,
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
        $amount = $plan['price_monthly'];

        // Create invoice
        $invoiceId = $this->invoiceService->createInvoice($subscriptionId, $amount);

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
     * List user payments
     */
    public function index()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $payments = $this->paymentModel->getByUserId($userId);

        $this->loadPageTranslations('payments');
        
        $this->data += [
            'viewName' => 'pages/payments/index.html.twig',
            'page' => ['titulo' => 'Meus Pagamentos'],
            'payments' => $payments,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

