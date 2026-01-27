<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Condominium;
use App\Services\FileStorageService;

class DocumentController extends Controller
{
    protected $documentModel;
    protected $folderModel;
    protected $condominiumModel;
    protected $fileStorageService;

    public function __construct()
    {
        parent::__construct();
        $this->documentModel = new Document();
        $this->folderModel = new Folder();
        $this->condominiumModel = new Condominium();
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

        // Get filters from query string
        $folder = $_GET['folder'] ?? null;
        $documentType = $_GET['document_type'] ?? null;
        $visibility = $_GET['visibility'] ?? null;
        $searchQuery = trim($_GET['search'] ?? '');
        $sortBy = $_GET['sort_by'] ?? 'created_at';
        $sortOrder = $_GET['sort_order'] ?? 'DESC';
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;

        $filters = [];
        if ($folder) $filters['folder'] = $folder;
        if ($documentType) $filters['document_type'] = $documentType;
        if ($visibility) $filters['visibility'] = $visibility;
        if ($dateFrom) $filters['date_from'] = $dateFrom;
        if ($dateTo) $filters['date_to'] = $dateTo;
        if ($sortBy) $filters['sort_by'] = $sortBy;
        if ($sortOrder) $filters['sort_order'] = $sortOrder;

        // Search or filter documents
        if (!empty($searchQuery)) {
            // When searching, show all results regardless of folder structure
            $documents = $this->documentModel->search($condominiumId, $searchQuery, $filters);
            $documentsWithoutFolder = [];
        } else {
            // If folder is selected, show only documents in that folder
            if ($folder) {
                $documents = $this->documentModel->getByCondominium($condominiumId, $filters);
                $documentsWithoutFolder = [];
            } else {
                // Show only documents without folder (root level)
                $filtersNoFolder = $filters;
                $filtersNoFolder['folder'] = null;
                $documents = $this->documentModel->getByCondominium($condominiumId, $filtersNoFolder);
                $documentsWithoutFolder = $documents;
            }
        }
        

        // Get folders - if folder is selected, get subfolders; otherwise get root folders
        $currentFolderId = null;
        if ($folder) {
            // Find folder by path
            $currentFolder = $this->folderModel->findByPath($condominiumId, $folder);
            if ($currentFolder) {
                $currentFolderId = $currentFolder['id'];
                $folders = $this->folderModel->getByCondominium($condominiumId, $currentFolderId);
            } else {
                $folders = [];
            }
        } else {
            // Get root folders only (no subfolders)
            $folders = $this->folderModel->getByCondominium($condominiumId, null);
        }
        // Get all folders for dropdowns
        $allFolders = $this->folderModel->getAllByCondominium($condominiumId);

        // Get unique document types for filter
        $documentTypes = $this->documentModel->getDocumentTypes($condominiumId);

        $this->loadPageTranslations('documents');
        
        $this->data += [
            'viewName' => 'pages/documents/index.html.twig',
            'page' => ['titulo' => 'Documentos'],
            'condominium' => $condominium,
            'documents' => $documents,
            'documents_without_folder' => $documentsWithoutFolder ?? [],
            'folders' => $folders,
            'all_folders' => $allFolders,
            'document_types' => $documentTypes,
            'current_folder' => $folder,
            'current_document_type' => $documentType,
            'current_visibility' => $visibility,
            'current_search' => $searchQuery,
            'current_sort_by' => $sortBy,
            'current_sort_order' => $sortOrder,
            'current_date_from' => $dateFrom,
            'current_date_to' => $dateTo,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
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

        // Get fractions for dropdown
        $fractionModel = new \App\Models\Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        $this->loadPageTranslations('documents');
        
        $this->data += [
            'viewName' => 'pages/documents/create.html.twig',
            'page' => ['titulo' => 'Upload Documento'],
            'condominium' => $condominium,
            'fractions' => $fractions,
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
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/create');
            exit;
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = 'Erro no upload do ficheiro.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/create');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $parentDocumentId = !empty($_POST['parent_document_id']) ? (int)$_POST['parent_document_id'] : null;

        try {
            // If uploading as new version, get parent document metadata
            $parentDocument = null;
            if ($parentDocumentId) {
                $parentDocument = $this->documentModel->findById($parentDocumentId);
                if (!$parentDocument || $parentDocument['condominium_id'] != $condominiumId) {
                    $_SESSION['error'] = 'Documento pai não encontrado.';
                    header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/create');
                    exit;
                }
            }

            // Upload file
            $fileData = $this->fileStorageService->upload(
                $_FILES['file'],
                $condominiumId,
                $_POST['folder'] ?? ($parentDocument['folder'] ?? null)
            );

            // Create document record
            $documentId = $this->documentModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : ($parentDocument['fraction_id'] ?? null),
                'folder' => Security::sanitize($_POST['folder'] ?? ($parentDocument['folder'] ?? '')),
                'title' => Security::sanitize($_POST['title'] ?? ($parentDocument['title'] ?? $_FILES['file']['name'])),
                'description' => Security::sanitize($_POST['description'] ?? ($parentDocument['description'] ?? '')),
                'file_path' => $fileData['file_path'],
                'file_name' => $fileData['file_name'],
                'file_size' => $fileData['file_size'],
                'mime_type' => $fileData['mime_type'],
                'visibility' => Security::sanitize($_POST['visibility'] ?? ($parentDocument['visibility'] ?? 'condominos')),
                'document_type' => Security::sanitize($_POST['document_type'] ?? ($parentDocument['document_type'] ?? '')),
                'parent_document_id' => $parentDocumentId,
                'uploaded_by' => $userId
            ]);

            $message = $parentDocumentId ? 'Nova versão do documento carregada com sucesso!' : 'Documento carregado com sucesso!';
            $_SESSION['success'] = $message;
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao carregar documento: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/create');
            exit;
        }
    }

    public function uploadVersion(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $document = $this->documentModel->findById($id);
        if (!$document || $document['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Documento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        // Get versions
        $versions = $this->documentModel->getVersions($id);

        $this->loadPageTranslations('documents');
        
        $this->data += [
            'viewName' => 'pages/documents/upload-version.html.twig',
            'page' => ['titulo' => 'Upload Nova Versão'],
            'condominium' => $condominium,
            'document' => $document,
            'versions' => $versions,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function versions(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $document = $this->documentModel->findById($id);
        if (!$document || $document['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Documento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        $versions = $this->documentModel->getVersions($id);

        $this->loadPageTranslations('documents');
        
        $this->data += [
            'viewName' => 'pages/documents/versions.html.twig',
            'page' => ['titulo' => 'Histórico de Versões'],
            'condominium' => $condominium,
            'document' => $document,
            'versions' => $versions,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function download(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $document = $this->documentModel->findById($id);
        
        if (!$document || $document['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Documento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        $filePath = $this->fileStorageService->getFilePath($document['file_path']);
        
        if (!file_exists($filePath)) {
            $_SESSION['error'] = 'Ficheiro não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        // Determine correct MIME type
        $mimeType = $document['mime_type'] ?? 'application/octet-stream';
        $extension = pathinfo($document['file_name'] ?? $document['file_path'], PATHINFO_EXTENSION);
        
        // Override MIME type based on file extension if needed
        if ($extension === 'pdf' && $mimeType !== 'application/pdf') {
            $mimeType = 'application/pdf';
        } elseif ($extension === 'html' && strpos($mimeType, 'html') === false) {
            $mimeType = 'text/html';
        }

        // For PDFs, use inline display; for others, use attachment
        $disposition = ($mimeType === 'application/pdf') ? 'inline' : 'attachment';
        
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: ' . $disposition . '; filename="' . htmlspecialchars($document['file_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        readfile($filePath);
        exit;
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

        $document = $this->documentModel->findById($id);
        if (!$document || $document['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Documento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        // Get fractions for dropdown
        $fractionModel = new \App\Models\Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        // Get existing folders
        $folders = $this->documentModel->getFolders($condominiumId);

        $this->loadPageTranslations('documents');
        
        $this->data += [
            'viewName' => 'pages/documents/edit.html.twig',
            'page' => ['titulo' => 'Editar Documento'],
            'condominium' => $condominium,
            'document' => $document,
            'fractions' => $fractions,
            'folders' => $folders,
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
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/' . $id . '/edit');
            exit;
        }

        $document = $this->documentModel->findById($id);
        if (!$document || $document['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Documento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        try {
            $this->documentModel->update($id, [
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'folder' => Security::sanitize($_POST['folder'] ?? ''),
                'document_type' => Security::sanitize($_POST['document_type'] ?? ''),
                'visibility' => Security::sanitize($_POST['visibility'] ?? 'condominos'),
                'fraction_id' => !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null
            ]);

            $_SESSION['success'] = 'Documento atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar documento: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/' . $id . '/edit');
            exit;
        }
    }

    public function view(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $document = $this->documentModel->findById($id);
        if (!$document || $document['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Documento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        // Check visibility permissions
        $user = AuthMiddleware::user();
        $userRole = $user['role'] ?? 'condomino';
        
        if ($document['visibility'] === 'admin' && $userRole !== 'admin' && $userRole !== 'super_admin') {
            $_SESSION['error'] = 'Não tem permissão para visualizar este documento.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        $filePath = $this->fileStorageService->getFilePath($document['file_path']);
        
        if (!file_exists($filePath)) {
            $_SESSION['error'] = 'Ficheiro não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        // Get versions if exists
        $versions = $this->documentModel->getVersions($id);

        $this->loadPageTranslations('documents');
        
        $this->data += [
            'viewName' => 'pages/documents/view.html.twig',
            'page' => ['titulo' => $document['title']],
            'condominium' => $condominium,
            'document' => $document,
            'versions' => $versions,
            'file_url' => BASE_URL . 'condominiums/' . $condominiumId . '/documents/' . $id . '/download',
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function manageFolders(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        // Get current folder from query string
        $currentFolderPath = $_GET['folder'] ?? null;
        $currentFolderId = null;
        
        // Get folders - if folder is selected, get subfolders; otherwise get root folders
        if ($currentFolderPath) {
            $currentFolder = $this->folderModel->findByPath($condominiumId, $currentFolderPath);
            if ($currentFolder) {
                $currentFolderId = $currentFolder['id'];
                $folders = $this->folderModel->getByCondominium($condominiumId, $currentFolderId);
            } else {
                $folders = [];
            }
        } else {
            $folders = $this->folderModel->getByCondominium($condominiumId, null);
        }
        $allFolders = $this->folderModel->getAllByCondominium($condominiumId);

        $this->loadPageTranslations('documents');
        
        $this->data += [
            'viewName' => 'pages/documents/manage-folders.html.twig',
            'page' => ['titulo' => 'Gerir Pastas'],
            'condominium' => $condominium,
            'folders' => $folders,
            'all_folders' => $allFolders,
            'current_folder' => $currentFolderPath,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function createFolder(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        $folderName = trim(Security::sanitize($_POST['folder_name'] ?? ''));
        $parentFolder = trim(Security::sanitize($_POST['parent_folder'] ?? ''));
        
        if (empty($folderName)) {
            $_SESSION['error'] = 'Nome da pasta é obrigatório.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        // Validate folder name (no special characters except /)
        if (preg_match('/[<>:"|?*\\\\]/', $folderName)) {
            $_SESSION['error'] = 'Nome da pasta contém caracteres inválidos.';
            $redirectUrl = !empty($_POST['from_index']) ? BASE_URL . 'condominiums/' . $condominiumId . '/documents' : BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders';
            header('Location: ' . $redirectUrl);
            exit;
        }

        // Get parent folder ID if specified
        $parentFolderId = null;
        $fullFolderPath = $folderName;
        
        if (!empty($parentFolder)) {
            // Find parent folder by path
            $parentFolderObj = $this->folderModel->findByPath($condominiumId, $parentFolder);
            if ($parentFolderObj) {
                $parentFolderId = $parentFolderObj['id'];
                $fullFolderPath = $parentFolder . '/' . $folderName;
            } else {
                // Parent folder doesn't exist, create it first
                $_SESSION['error'] = 'Pasta pai não encontrada.';
                $redirectUrl = !empty($_POST['from_index']) ? BASE_URL . 'condominiums/' . $condominiumId . '/documents' : BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders';
                header('Location: ' . $redirectUrl);
                exit;
            }
        }

        // Check if folder already exists
        $existingFolder = $this->folderModel->findByPath($condominiumId, $fullFolderPath);
        if ($existingFolder) {
            $_SESSION['error'] = 'Uma pasta com este nome já existe nesta localização.';
            $redirectUrl = !empty($_POST['from_index']) ? BASE_URL . 'condominiums/' . $condominiumId . '/documents' : BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders';
            header('Location: ' . $redirectUrl);
            exit;
        }

        // Create folder in folders table
        $userId = AuthMiddleware::userId();
        try {
            $folderId = $this->folderModel->create([
                'condominium_id' => $condominiumId,
                'name' => $folderName,
                'parent_folder_id' => $parentFolderId,
                'path' => $fullFolderPath,
                'created_by' => $userId
            ]);
            
            $_SESSION['success'] = 'Pasta criada com sucesso! Agora pode fazer upload de documentos para esta pasta.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar pasta: ' . $e->getMessage();
        }
        
        // Redirect based on where the request came from
        if (!empty($_POST['from_index'])) {
            // If created from index page, redirect back to index (optionally with folder)
            $redirectUrl = BASE_URL . 'condominiums/' . $condominiumId . '/documents';
            if (!empty($parentFolder)) {
                $redirectUrl .= '?folder=' . urlencode($parentFolder);
            }
            header('Location: ' . $redirectUrl);
        } else {
            $redirectUrl = BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders';
            if (!empty($parentFolder)) {
                $redirectUrl .= '?folder=' . urlencode($parentFolder);
            }
            header('Location: ' . $redirectUrl);
        }
        exit;
    }

    public function renameFolder(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        $oldFolderPath = trim(Security::sanitize($_POST['old_folder_name'] ?? ''));
        $newFolderName = trim(Security::sanitize($_POST['new_folder_name'] ?? ''));
        
        if (empty($oldFolderPath) || empty($newFolderName)) {
            $_SESSION['error'] = 'Nome da pasta é obrigatório.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        // Find folder by path
        $folder = $this->folderModel->findByPath($condominiumId, $oldFolderPath);
        if (!$folder) {
            $_SESSION['error'] = 'Pasta não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        // Get parent folder path
        $oldParts = explode('/', $oldFolderPath);
        $parentPath = count($oldParts) > 1 ? implode('/', array_slice($oldParts, 0, -1)) : '';
        
        // Build new full path
        $newFullPath = $newFolderName;
        if (!empty($parentPath)) {
            $newFullPath = $parentPath . '/' . $newFolderName;
        }

        if ($oldFolderPath === $newFullPath) {
            $_SESSION['error'] = 'O novo nome deve ser diferente do nome atual.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        // Validate folder name (no special characters except /)
        if (preg_match('/[<>:"|?*\\\\]/', $newFolderName)) {
            $_SESSION['error'] = 'Nome da pasta contém caracteres inválidos.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        // Check if new folder name already exists in the same parent folder
        $existingFolder = $this->folderModel->findByPath($condominiumId, $newFullPath);
        if ($existingFolder) {
            $_SESSION['error'] = 'Uma pasta com este nome já existe nesta localização.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        // Rename folder using Folder model
        if ($this->folderModel->rename($folder['id'], $newFolderName, $newFullPath)) {
            $_SESSION['success'] = 'Pasta renomeada com sucesso!';
            
            // Redirect back to same folder if we were inside one
            $redirectUrl = BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders';
            if (!empty($parentPath)) {
                $redirectUrl .= '?folder=' . urlencode($parentPath);
            }
            header('Location: ' . $redirectUrl);
        } else {
            $_SESSION['error'] = 'Erro ao renomear pasta.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
        }
        exit;
    }

    public function deleteFolder(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        $folderPath = trim(Security::sanitize($_POST['folder_name'] ?? ''));
        
        if (empty($folderPath)) {
            $_SESSION['error'] = 'Nome da pasta é obrigatório.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        // Find folder by path
        $folder = $this->folderModel->findByPath($condominiumId, $folderPath);
        if (!$folder) {
            $_SESSION['error'] = 'Pasta não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders');
            exit;
        }

        // Get parent folder path for redirect
        $parts = explode('/', $folderPath);
        $parentPath = count($parts) > 1 ? implode('/', array_slice($parts, 0, -1)) : null;

        // Delete folder using Folder model (moves documents to root)
        if ($this->folderModel->delete($folder['id'])) {
            $_SESSION['success'] = 'Pasta eliminada com sucesso! Os documentos foram movidos para a pasta raiz.';
        } else {
            $_SESSION['error'] = 'Erro ao eliminar pasta.';
        }

        $redirectUrl = BASE_URL . 'condominiums/' . $condominiumId . '/documents/manage-folders';
        if ($parentPath) {
            $redirectUrl .= '?folder=' . urlencode($parentPath);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    public function delete(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        }

        if ($this->documentModel->delete($id)) {
            $_SESSION['success'] = 'Documento removido com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao remover documento.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
        exit;
    }

    public function moveDocument(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Método não permitido', 405);
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $this->jsonError('Token de segurança inválido', 403);
        }

        $document = $this->documentModel->findById($id);
        if (!$document || $document['condominium_id'] != $condominiumId) {
            $this->jsonError('Documento não encontrado', 404);
        }

        $targetFolder = trim($_POST['target_folder'] ?? '');
        // Empty string means move to root (null)
        $targetFolder = $targetFolder === '' ? null : $targetFolder;

        if ($this->documentModel->moveToFolder($id, $targetFolder)) {
            $this->jsonSuccess(['message' => 'Documento movido com sucesso']);
        } else {
            $this->jsonError('Erro ao mover documento', 500);
        }
    }
}

