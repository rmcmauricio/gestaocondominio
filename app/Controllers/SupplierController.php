<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Supplier;
use App\Models\Contract;
use App\Models\Condominium;
use App\Models\Document;
use App\Services\FileStorageService;

class SupplierController extends Controller
{
    protected $supplierModel;
    protected $contractModel;
    protected $condominiumModel;
    protected $documentModel;
    protected $fileStorageService;

    public function __construct()
    {
        parent::__construct();
        $this->supplierModel = new Supplier();
        $this->contractModel = new Contract();
        $this->condominiumModel = new Condominium();
        $this->documentModel = new Document();
        $this->fileStorageService = new FileStorageService();
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
        
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        $this->data += [
            'viewName' => 'pages/suppliers/index.html.twig',
            'page' => ['titulo' => 'Fornecedores'],
            'condominium' => $condominium,
            'suppliers' => $suppliers,
            'is_admin' => $isAdmin,
            'csrf_token' => Security::generateCSRFToken(),
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        $this->data += [
            'viewName' => 'pages/suppliers/contracts.html.twig',
            'page' => ['titulo' => 'Contratos'],
            'condominium' => $condominium,
            'contracts' => $contracts,
            'expiring_soon' => $expiringSoon,
            'is_admin' => $isAdmin,
            'csrf_token' => Security::generateCSRFToken(),
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        $documentId = null;

        // Process document upload if provided
        if (isset($_FILES['contract_document']) && $_FILES['contract_document']['error'] === UPLOAD_ERR_OK) {
            try {
                // Upload file
                $fileData = $this->fileStorageService->upload(
                    $_FILES['contract_document'],
                    $condominiumId,
                    'documents',
                    'contracts'
                );

                // Get supplier name for document title
                $supplierId = (int)$_POST['supplier_id'];
                $supplier = $this->supplierModel->findById($supplierId);
                $supplierName = $supplier ? $supplier['name'] : 'Fornecedor';
                $contractNumber = Security::sanitize($_POST['contract_number'] ?? '');
                $documentTitle = 'Contrato - ' . $supplierName . ($contractNumber ? ' (' . $contractNumber . ')' : '');

                // Extract folder path from file_path to match logical structure
                // file_path format: condominiums/{id}/documents/contracts/{year}/{month}/filename
                // We want folder to be: Contratos/{year}/{month}
                $filePathParts = explode('/', $fileData['file_path']);
                $logicalFolder = 'Contratos';
                // Find contracts in path and extract year/month if present
                $contractsIndex = array_search('contracts', $filePathParts);
                if ($contractsIndex !== false && isset($filePathParts[$contractsIndex + 1]) && isset($filePathParts[$contractsIndex + 2])) {
                    $year = $filePathParts[$contractsIndex + 1];
                    $month = $filePathParts[$contractsIndex + 2];
                    // Validate year/month format (4 digits for year, 2 digits for month)
                    if (preg_match('/^\d{4}$/', $year) && preg_match('/^\d{2}$/', $month)) {
                        $logicalFolder = 'Contratos/' . $year . '/' . $month;
                    }
                }

                // Create document record
                $documentId = $this->documentModel->create([
                    'condominium_id' => $condominiumId,
                    'folder' => $logicalFolder,
                    'title' => $documentTitle,
                    'description' => 'Comprovativo do contrato com ' . $supplierName,
                    'file_path' => $fileData['file_path'],
                    'file_name' => $fileData['file_name'],
                    'file_size' => $fileData['file_size'],
                    'mime_type' => $fileData['mime_type'],
                    'visibility' => 'admin',
                    'document_type' => 'Contrato',
                    'uploaded_by' => $userId
                ]);
            } catch (\Exception $e) {
                $_SESSION['error'] = 'Erro ao fazer upload do documento: ' . $e->getMessage();
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts/create');
                exit;
            }
        }

        try {
            $amount = !empty($_POST['amount']) ? (float)$_POST['amount'] : null;
            $amountType = !empty($_POST['amount_type']) ? Security::sanitize($_POST['amount_type']) : null;
            
            // If amount is provided but type is not, default to annual
            if ($amount !== null && empty($amountType)) {
                $amountType = 'annual';
            }
            
            $contractData = [
                'condominium_id' => $condominiumId,
                'supplier_id' => (int)$_POST['supplier_id'],
                'contract_number' => Security::sanitize($_POST['contract_number'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'amount' => $amount,
                'amount_type' => $amountType,
                'start_date' => $_POST['start_date'],
                'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                'renewal_alert_days' => (int)($_POST['renewal_alert_days'] ?? 30),
                'auto_renew' => isset($_POST['auto_renew']),
                'notes' => Security::sanitize($_POST['notes'] ?? ''),
                'created_by' => $userId
            ];

            if ($documentId) {
                $contractData['document_id'] = $documentId;
            }

            $contractId = $this->contractModel->create($contractData);

            $_SESSION['success'] = 'Contrato criado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        } catch (\Exception $e) {
            // If contract creation fails but document was uploaded, try to clean up
            if ($documentId) {
                try {
                    $this->documentModel->delete($documentId);
                } catch (\Exception $deleteException) {
                    // Ignore cleanup errors
                }
            }
            $_SESSION['error'] = 'Erro ao criar contrato: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts/create');
            exit;
        }
    }

    public function editContract(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $contract = $this->contractModel->findById($id);
        if (!$contract || $contract['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Contrato não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        }

        $suppliers = $this->supplierModel->getByCondominium($condominiumId);

        $this->loadPageTranslations('suppliers');
        
        $this->data += [
            'viewName' => 'pages/suppliers/edit-contract.html.twig',
            'page' => ['titulo' => 'Editar Contrato'],
            'condominium' => $condominium,
            'contract' => $contract,
            'suppliers' => $suppliers,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function updateContract(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts/' . $id . '/edit');
            exit;
        }

        $contract = $this->contractModel->findById($id);
        if (!$contract || $contract['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Contrato não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $documentId = $contract['document_id'] ?? null;

        // Process document upload if provided
        if (isset($_FILES['contract_document']) && $_FILES['contract_document']['error'] === UPLOAD_ERR_OK) {
            try {
                // Upload file
                $fileData = $this->fileStorageService->upload(
                    $_FILES['contract_document'],
                    $condominiumId,
                    'documents',
                    'contracts'
                );

                // Get supplier name for document title
                $supplierId = (int)$_POST['supplier_id'];
                $supplier = $this->supplierModel->findById($supplierId);
                $supplierName = $supplier ? $supplier['name'] : 'Fornecedor';
                $contractNumber = Security::sanitize($_POST['contract_number'] ?? '');
                $documentTitle = 'Contrato - ' . $supplierName . ($contractNumber ? ' (' . $contractNumber . ')' : '');

                // Extract folder path from file_path to match logical structure
                // file_path format: condominiums/{id}/documents/contracts/{year}/{month}/filename
                // We want folder to be: Contratos/{year}/{month}
                $filePathParts = explode('/', $fileData['file_path']);
                $logicalFolder = 'Contratos';
                // Find contracts in path and extract year/month if present
                $contractsIndex = array_search('contracts', $filePathParts);
                if ($contractsIndex !== false && isset($filePathParts[$contractsIndex + 1]) && isset($filePathParts[$contractsIndex + 2])) {
                    $year = $filePathParts[$contractsIndex + 1];
                    $month = $filePathParts[$contractsIndex + 2];
                    // Validate year/month format (4 digits for year, 2 digits for month)
                    if (preg_match('/^\d{4}$/', $year) && preg_match('/^\d{2}$/', $month)) {
                        $logicalFolder = 'Contratos/' . $year . '/' . $month;
                    }
                }

                // If there's an existing document, delete it first
                if ($documentId) {
                    try {
                        $this->documentModel->delete($documentId);
                    } catch (\Exception $e) {
                        // Log error but continue
                        error_log("Error deleting old document: " . $e->getMessage());
                    }
                }

                // Create new document record
                $documentId = $this->documentModel->create([
                    'condominium_id' => $condominiumId,
                    'folder' => $logicalFolder,
                    'title' => $documentTitle,
                    'description' => 'Comprovativo do contrato com ' . $supplierName,
                    'file_path' => $fileData['file_path'],
                    'file_name' => $fileData['file_name'],
                    'file_size' => $fileData['file_size'],
                    'mime_type' => $fileData['mime_type'],
                    'visibility' => 'admin',
                    'document_type' => 'Contrato',
                    'uploaded_by' => $userId
                ]);
            } catch (\Exception $e) {
                $_SESSION['error'] = 'Erro ao fazer upload do documento: ' . $e->getMessage();
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts/' . $id . '/edit');
                exit;
            }
        }

        try {
            $amount = !empty($_POST['amount']) ? (float)$_POST['amount'] : null;
            $amountType = !empty($_POST['amount_type']) ? Security::sanitize($_POST['amount_type']) : null;
            
            // If amount is provided but type is not, default to annual
            if ($amount !== null && empty($amountType)) {
                $amountType = 'annual';
            }

            $contractData = [
                'supplier_id' => (int)$_POST['supplier_id'],
                'contract_number' => Security::sanitize($_POST['contract_number'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'amount' => $amount,
                'amount_type' => $amountType,
                'start_date' => $_POST['start_date'],
                'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                'renewal_alert_days' => (int)($_POST['renewal_alert_days'] ?? 30),
                'auto_renew' => isset($_POST['auto_renew']) ? 1 : 0,
                'notes' => Security::sanitize($_POST['notes'] ?? '')
            ];

            if ($documentId) {
                $contractData['document_id'] = $documentId;
            }

            $this->contractModel->update($id, $contractData);

            $_SESSION['success'] = 'Contrato atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar contrato: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts/' . $id . '/edit');
            exit;
        }
    }

    public function deleteContract(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        }

        $contract = $this->contractModel->findById($id);
        if (!$contract || $contract['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Contrato não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
            exit;
        }

        try {
            // Delete associated document if exists
            if (!empty($contract['document_id'])) {
                try {
                    $this->documentModel->delete($contract['document_id']);
                } catch (\Exception $e) {
                    // Log error but continue with contract deletion
                    error_log("Error deleting associated document: " . $e->getMessage());
                }
            }

            // Delete contract (audit is logged in the model)
            $this->contractModel->delete($id);

            $_SESSION['success'] = 'Contrato removido com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao remover contrato: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/suppliers/contracts');
        exit;
    }
}

