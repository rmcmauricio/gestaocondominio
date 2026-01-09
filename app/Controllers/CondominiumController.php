<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Condominium;
use App\Models\Subscription;
use App\Services\SubscriptionService;

class CondominiumController extends Controller
{
    protected $condominiumModel;
    protected $subscriptionModel;
    protected $subscriptionService;

    public function __construct()
    {
        parent::__construct();
        $this->condominiumModel = new Condominium();
        $this->subscriptionModel = new Subscription();
        $this->subscriptionService = new SubscriptionService();
    }

    public function index()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireAnyRole(['admin', 'super_admin']);

        $userId = AuthMiddleware::userId();
        $condominiums = $this->condominiumModel->getByUserId($userId);

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/index.html.twig',
            'page' => ['titulo' => 'Condomínios'],
            'condominiums' => $condominiums
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function create()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireAnyRole(['admin', 'super_admin']);

        $userId = AuthMiddleware::userId();
        
        // Check subscription limits
        if (!$this->subscriptionModel->canCreateCondominium($userId)) {
            $_SESSION['error'] = 'Limite de condomínios atingido. Faça upgrade do seu plano.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/create.html.twig',
            'page' => ['titulo' => 'Criar Condomínio'],
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireAnyRole(['admin', 'super_admin']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/create');
            exit;
        }

        $userId = AuthMiddleware::userId();
        
        if (!$this->subscriptionModel->canCreateCondominium($userId)) {
            $_SESSION['error'] = 'Limite de condomínios atingido.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        try {
            $condominiumId = $this->condominiumModel->create([
                'user_id' => $userId,
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'address' => Security::sanitize($_POST['address'] ?? ''),
                'postal_code' => Security::sanitize($_POST['postal_code'] ?? ''),
                'city' => Security::sanitize($_POST['city'] ?? ''),
                'country' => Security::sanitize($_POST['country'] ?? 'Portugal'),
                'nif' => Security::sanitize($_POST['nif'] ?? ''),
                'iban' => Security::sanitize($_POST['iban'] ?? ''),
                'phone' => Security::sanitize($_POST['phone'] ?? ''),
                'email' => Security::sanitize($_POST['email'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? 'habitacional'),
                'total_fractions' => (int)($_POST['total_fractions'] ?? 0),
                'rules' => Security::sanitize($_POST['rules'] ?? '')
            ]);

            $_SESSION['success'] = 'Condomínio criado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar condomínio: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/create');
            exit;
        }
    }

    public function show(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        $condominium = $this->condominiumModel->getWithStats($id);
        
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/show.html.twig',
            'page' => ['titulo' => $condominium['name']],
            'condominium' => $condominium
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function edit(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        $condominium = $this->condominiumModel->findById($id);
        
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/edit.html.twig',
            'page' => ['titulo' => 'Editar Condomínio'],
            'condominium' => $condominium,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function update(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            exit;
        }

        try {
            $this->condominiumModel->update($id, [
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'address' => Security::sanitize($_POST['address'] ?? ''),
                'postal_code' => Security::sanitize($_POST['postal_code'] ?? ''),
                'city' => Security::sanitize($_POST['city'] ?? ''),
                'country' => Security::sanitize($_POST['country'] ?? 'Portugal'),
                'nif' => Security::sanitize($_POST['nif'] ?? ''),
                'iban' => Security::sanitize($_POST['iban'] ?? ''),
                'phone' => Security::sanitize($_POST['phone'] ?? ''),
                'email' => Security::sanitize($_POST['email'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? 'habitacional'),
                'total_fractions' => (int)($_POST['total_fractions'] ?? 0),
                'rules' => Security::sanitize($_POST['rules'] ?? '')
            ]);

            $_SESSION['success'] = 'Condomínio atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar condomínio: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            exit;
        }
    }

    public function delete(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        if ($this->condominiumModel->delete($id)) {
            $_SESSION['success'] = 'Condomínio removido com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao remover condomínio.';
        }

        header('Location: ' . BASE_URL . 'condominiums');
        exit;
    }
}





