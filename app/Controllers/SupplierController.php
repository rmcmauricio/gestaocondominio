<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Supplier;
use App\Models\Contract;
use App\Models\Condominium;

class SupplierController extends Controller
{
    protected $supplierModel;
    protected $contractModel;
    protected $condominiumModel;

    public function __construct()
    {
        parent::__construct();
        $this->supplierModel = new Supplier();
        $this->contractModel = new Contract();
        $this->condominiumModel = new Condominium();
    }

    public function index(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $suppliers = $this->supplierModel->getByCondominium($condominiumId);

        $this->loadPageTranslations('suppliers');
        
        $isAdmin = RoleMiddleware::isAdmin();
        
        $this->data += [
            'viewName' => 'pages/suppliers/index.html.twig',
            'page' => ['titulo' => 'Fornecedores'],
            'condominium' => $condominium,
            'suppliers' => $suppliers,
            'is_admin' => $isAdmin,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function create(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('suppliers');
        
        $this->data += [
            'viewName' => 'pages/suppliers/create.html.twig',
            'page' => ['titulo' => 'Adicionar Fornecedor'],
            'condominium' => $condominium,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/create');
            exit;
        }

        try {
            $supplierId = $this->supplierModel->create([
                'condominium_id' => $condominiumId,
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'nif' => Security::sanitize($_POST['nif'] ?? ''),
                'address' => Security::sanitize($_POST['address'] ?? ''),
                'phone' => Security::sanitize($_POST['phone'] ?? ''),
                'email' => Security::sanitize($_POST['email'] ?? ''),
                'website' => Security::sanitize($_POST['website'] ?? ''),
                'area' => Security::sanitize($_POST['area'] ?? ''),
                'notes' => Security::sanitize($_POST['notes'] ?? '')
            ]);

            $_SESSION['success'] = 'Fornecedor adicionado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao adicionar fornecedor: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/create');
            exit;
        }
    }

    public function edit(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $supplier = $this->supplierModel->findById($id);
        if (!$supplier || $supplier['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fornecedor não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
            exit;
        }

        $this->loadPageTranslations('suppliers');
        
        $this->data += [
            'viewName' => 'pages/suppliers/edit.html.twig',
            'page' => ['titulo' => 'Editar Fornecedor'],
            'condominium' => $condominium,
            'supplier' => $supplier,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function update(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/' . $id . '/edit');
            exit;
        }

        $supplier = $this->supplierModel->findById($id);
        if (!$supplier || $supplier['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fornecedor não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
            exit;
        }

        try {
            $this->supplierModel->update($id, [
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'nif' => Security::sanitize($_POST['nif'] ?? ''),
                'address' => Security::sanitize($_POST['address'] ?? ''),
                'phone' => Security::sanitize($_POST['phone'] ?? ''),
                'email' => Security::sanitize($_POST['email'] ?? ''),
                'website' => Security::sanitize($_POST['website'] ?? ''),
                'area' => Security::sanitize($_POST['area'] ?? ''),
                'notes' => Security::sanitize($_POST['notes'] ?? '')
            ]);

            $_SESSION['success'] = 'Fornecedor atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar fornecedor: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/' . $id . '/edit');
            exit;
        }
    }

    public function delete(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
            exit;
        }

        $supplier = $this->supplierModel->findById($id);
        if (!$supplier || $supplier['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fornecedor não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
            exit;
        }

        try {
            $this->supplierModel->delete($id);
            $_SESSION['success'] = 'Fornecedor removido com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao remover fornecedor: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers');
        exit;
    }

    public function contracts(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $contracts = $this->contractModel->getByCondominium($condominiumId);
        $expiringSoon = $this->contractModel->getExpiringSoon($condominiumId, 30);

        $this->loadPageTranslations('suppliers');
        
        $isAdmin = RoleMiddleware::isAdmin();
        
        $this->data += [
            'viewName' => 'pages/suppliers/contracts.html.twig',
            'page' => ['titulo' => 'Contratos'],
            'condominium' => $condominium,
            'contracts' => $contracts,
            'expiring_soon' => $expiringSoon,
            'is_admin' => $isAdmin,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function createContract(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $suppliers = $this->supplierModel->getByCondominium($condominiumId);

        $this->loadPageTranslations('suppliers');
        
        $this->data += [
            'viewName' => 'pages/suppliers/create-contract.html.twig',
            'page' => ['titulo' => 'Criar Contrato'],
            'condominium' => $condominium,
            'suppliers' => $suppliers,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function storeContract(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts/create');
            exit;
        }

        $userId = AuthMiddleware::userId();

        try {
            $contractId = $this->contractModel->create([
                'condominium_id' => $condominiumId,
                'supplier_id' => (int)$_POST['supplier_id'],
                'contract_number' => Security::sanitize($_POST['contract_number'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'amount' => (float)$_POST['amount'],
                'start_date' => $_POST['start_date'],
                'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                'renewal_alert_days' => (int)($_POST['renewal_alert_days'] ?? 30),
                'auto_renew' => isset($_POST['auto_renew']),
                'notes' => Security::sanitize($_POST['notes'] ?? ''),
                'created_by' => $userId
            ]);

            $_SESSION['success'] = 'Contrato criado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar contrato: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts/create');
            exit;
        }
    }
}

