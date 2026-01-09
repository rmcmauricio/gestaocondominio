<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Document;
use App\Models\Condominium;
use App\Services\FileStorageService;

class DocumentController extends Controller
{
    protected $documentModel;
    protected $condominiumModel;
    protected $fileStorageService;

    public function __construct()
    {
        parent::__construct();
        $this->documentModel = new Document();
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

        $folder = $_GET['folder'] ?? null;
        $documents = $this->documentModel->getByCondominium($condominiumId, ['folder' => $folder]);
        $folders = $this->documentModel->getFolders($condominiumId);

        $this->loadPageTranslations('documents');
        
        $this->data += [
            'viewName' => 'pages/documents/index.html.twig',
            'page' => ['titulo' => 'Documentos'],
            'condominium' => $condominium,
            'documents' => $documents,
            'folders' => $folders,
            'current_folder' => $folder,
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

        try {
            // Upload file
            $fileData = $this->fileStorageService->upload(
                $_FILES['file'],
                $condominiumId,
                $_POST['folder'] ?? null
            );

            // Create document record
            $documentId = $this->documentModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null,
                'folder' => Security::sanitize($_POST['folder'] ?? ''),
                'title' => Security::sanitize($_POST['title'] ?? $_FILES['file']['name']),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'file_path' => $fileData['file_path'],
                'file_name' => $fileData['file_name'],
                'file_size' => $fileData['file_size'],
                'mime_type' => $fileData['mime_type'],
                'visibility' => Security::sanitize($_POST['visibility'] ?? 'condominos'),
                'document_type' => Security::sanitize($_POST['document_type'] ?? ''),
                'uploaded_by' => $userId
            ]);

            $_SESSION['success'] = 'Documento carregado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao carregar documento: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/documents/create');
            exit;
        }
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

        header('Content-Type: ' . $document['mime_type']);
        header('Content-Disposition: attachment; filename="' . $document['file_name'] . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    public function delete(int $condominiumId, int $id)
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
}

