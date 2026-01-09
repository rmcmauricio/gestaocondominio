<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Occurrence;
use App\Models\Condominium;
use App\Services\NotificationService;
use App\Services\FileStorageService;

class OccurrenceController extends Controller
{
    protected $occurrenceModel;
    protected $condominiumModel;
    protected $notificationService;
    protected $fileStorageService;

    public function __construct()
    {
        parent::__construct();
        $this->occurrenceModel = new Occurrence();
        $this->condominiumModel = new Condominium();
        $this->notificationService = new NotificationService();
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

        $status = $_GET['status'] ?? null;
        $occurrences = $this->occurrenceModel->getByCondominium($condominiumId, ['status' => $status]);

        $this->loadPageTranslations('occurrences');
        
        $this->data += [
            'viewName' => 'pages/occurrences/index.html.twig',
            'page' => ['titulo' => 'Ocorrências'],
            'condominium' => $condominium,
            'occurrences' => $occurrences,
            'current_status' => $status,
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

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        // Get user's fractions
        $userId = AuthMiddleware::userId();
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        $userFractions = array_filter($userCondominiums, function($uc) use ($condominiumId) {
            return $uc['condominium_id'] == $condominiumId && $uc['fraction_id'];
        });

        $this->loadPageTranslations('occurrences');
        
        $this->data += [
            'viewName' => 'pages/occurrences/create.html.twig',
            'page' => ['titulo' => 'Reportar Ocorrência'],
            'condominium' => $condominium,
            'user_fractions' => $userFractions,
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
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/create');
            exit;
        }

        $userId = AuthMiddleware::userId();

        // Handle file uploads
        $attachments = [];
        if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    try {
                        $file = [
                            'name' => $name,
                            'type' => $_FILES['attachments']['type'][$key],
                            'tmp_name' => $_FILES['attachments']['tmp_name'][$key],
                            'error' => $_FILES['attachments']['error'][$key],
                            'size' => $_FILES['attachments']['size'][$key]
                        ];
                        $fileData = $this->fileStorageService->upload($file, $condominiumId, 'occurrences');
                        $attachments[] = $fileData['file_path'];
                    } catch (\Exception $e) {
                        error_log("File upload error: " . $e->getMessage());
                    }
                }
            }
        }

        try {
            $occurrenceId = $this->occurrenceModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null,
                'reported_by' => $userId,
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'category' => Security::sanitize($_POST['category'] ?? ''),
                'priority' => Security::sanitize($_POST['priority'] ?? 'medium'),
                'status' => 'open',
                'location' => Security::sanitize($_POST['location'] ?? ''),
                'attachments' => $attachments
            ]);

            // Notify admins
            $this->notificationService->notifyOccurrenceCreated($occurrenceId, $condominiumId);

            $_SESSION['success'] = 'Ocorrência reportada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $occurrenceId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao reportar ocorrência: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/create');
            exit;
        }
    }

    public function show(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $occurrence = $this->occurrenceModel->findById($id);
        
        if (!$occurrence || $occurrence['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Ocorrência não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences');
            exit;
        }

        // Get suppliers for assignment
        global $db;
        $suppliers = [];
        if ($db) {
            $stmt = $db->prepare("SELECT id, name FROM suppliers WHERE condominium_id = :condominium_id AND is_active = TRUE");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $suppliers = $stmt->fetchAll() ?: [];
        }

        $this->loadPageTranslations('occurrences');
        
        $this->data += [
            'viewName' => 'pages/occurrences/show.html.twig',
            'page' => ['titulo' => $occurrence['title']],
            'occurrence' => $occurrence,
            'suppliers' => $suppliers,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function updateStatus(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
            exit;
        }

        $status = Security::sanitize($_POST['status'] ?? '');
        $notes = Security::sanitize($_POST['notes'] ?? '');

        if ($this->occurrenceModel->changeStatus($id, $status, $notes)) {
            $_SESSION['success'] = 'Estado da ocorrência atualizado!';
        } else {
            $_SESSION['error'] = 'Erro ao atualizar estado.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
        exit;
    }

    public function assign(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
            exit;
        }

        $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $supplierId = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;

        if ($this->occurrenceModel->assign($id, $assignedTo, $supplierId)) {
            $_SESSION['success'] = 'Ocorrência atribuída com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao atribuir ocorrência.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
        exit;
    }
}

