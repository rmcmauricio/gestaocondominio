<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\PaymentMethodSettings;
use App\Core\Security;

class PaymentMethodsController extends Controller
{
    protected $paymentMethodSettings;

    public function __construct()
    {
        parent::__construct();
        $this->paymentMethodSettings = new PaymentMethodSettings();
    }

    /**
     * List all payment methods
     */
    public function index()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $methods = $this->paymentMethodSettings->getAll();
        
        // Decode config_data for each method
        foreach ($methods as &$method) {
            $method['config'] = json_decode($method['config_data'], true) ?: [];
            $method['enabled'] = (bool)$method['enabled'];
        }

        $this->loadPageTranslations('payments');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/admin/payment-methods/index.html.twig',
            'page' => ['titulo' => 'Gestão de Métodos de Pagamento'],
            'methods' => $methods,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user(),
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Toggle payment method enabled status
     */
    public function toggle()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin/payment-methods');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin/payment-methods');
            exit;
        }

        $methodKey = Security::sanitize($_POST['method_key'] ?? '');
        
        if (empty($methodKey)) {
            $_SESSION['error'] = 'Chave do método de pagamento não fornecida.';
            header('Location: ' . BASE_URL . 'admin/payment-methods');
            exit;
        }

        $success = $this->paymentMethodSettings->toggle($methodKey);
        
        if ($success) {
            $method = $this->paymentMethodSettings->findByMethodKey($methodKey);
            $status = (bool)$method['enabled'] ? 'ativado' : 'desativado';
            $_SESSION['success'] = "Método de pagamento {$methodKey} foi {$status} com sucesso.";
        } else {
            $_SESSION['error'] = 'Erro ao alterar status do método de pagamento.';
        }

        header('Location: ' . BASE_URL . 'admin/payment-methods');
        exit;
    }
}
