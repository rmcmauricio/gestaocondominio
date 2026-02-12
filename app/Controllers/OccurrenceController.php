<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Occurrence;
use App\Models\Condominium;
use App\Models\OccurrenceComment;
use App\Models\OccurrenceHistory;
use App\Services\NotificationService;
use App\Services\FileStorageService;

class OccurrenceController extends Controller
{
    protected $occurrenceModel;
    protected $condominiumModel;
    protected $commentModel;
    protected $historyModel;
    protected $notificationService;
    protected $fileStorageService;

    public function __construct()
    {
        parent::__construct();
        $this->occurrenceModel = new Occurrence();
        $this->condominiumModel = new Condominium();
        $this->commentModel = new OccurrenceComment();
        $this->historyModel = new OccurrenceHistory();
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

        // Get filters from query string
        $status = $_GET['status'] ?? null;
        $priority = $_GET['priority'] ?? null;
        $category = $_GET['category'] ?? null;
        $fractionId = $_GET['fraction_id'] ?? null;
        $assignedTo = $_GET['assigned_to'] ?? null;
        $searchQuery = trim($_GET['search'] ?? '');
        $sortBy = $_GET['sort_by'] ?? 'created_at';
        $sortOrder = $_GET['sort_order'] ?? 'DESC';
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;

        $filters = [];
        if ($status) $filters['status'] = $status;
        if ($priority) $filters['priority'] = $priority;
        if ($category) $filters['category'] = $category;
        if ($fractionId) $filters['fraction_id'] = (int)$fractionId;
        if ($assignedTo) $filters['assigned_to'] = (int)$assignedTo;
        if ($dateFrom) $filters['date_from'] = $dateFrom;
        if ($dateTo) $filters['date_to'] = $dateTo;
        if ($sortBy) $filters['sort_by'] = $sortBy;
        if ($sortOrder) $filters['sort_order'] = $sortOrder;

        // Search or filter occurrences
        if (!empty($searchQuery)) {
            $occurrences = $this->occurrenceModel->search($condominiumId, $searchQuery, $filters);
        } else {
            $occurrences = $this->occurrenceModel->getByCondominium($condominiumId, $filters);
        }

        // Get filter options
        $categories = $this->occurrenceModel->getCategories($condominiumId);
        
        // Get fractions for filter
        $fractionModel = new \App\Models\Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        // Get users for filter
        global $db;
        $users = [];
        if ($db) {
            $stmt = $db->prepare("
                SELECT DISTINCT u.id, u.name
                FROM users u
                INNER JOIN occurrences o ON o.reported_by = u.id OR o.assigned_to = u.id
                WHERE o.condominium_id = :condominium_id
                ORDER BY u.name ASC
            ");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $users = $stmt->fetchAll() ?: [];
        }

        $this->loadPageTranslations('occurrences');
        
        $this->data += [
            'viewName' => 'pages/occurrences/index.html.twig',
            'page' => ['titulo' => 'Ocorrências'],
            'condominium' => $condominium,
            'occurrences' => $occurrences,
            'categories' => $categories,
            'fractions' => $fractions,
            'users' => $users,
            'current_status' => $status,
            'current_priority' => $priority,
            'current_category' => $category,
            'current_fraction_id' => $fractionId,
            'current_assigned_to' => $assignedTo,
            'current_search' => $searchQuery,
            'current_sort_by' => $sortBy,
            'current_sort_order' => $sortOrder,
            'current_date_from' => $dateFrom,
            'current_date_to' => $dateTo,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        $this->renderMainTemplate();
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

        $this->renderMainTemplate();
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

        // Handle file uploads with rate limiting
        $attachments = [];
        if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            // Check rate limit for file uploads
            try {
                \App\Middleware\RateLimitMiddleware::require('file_upload');
            } catch (\Exception $e) {
                $_SESSION['error'] = $e->getMessage();
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/create');
                exit;
            }
            
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
                        $fileData = $this->fileStorageService->upload($file, $condominiumId, 'occurrences', 'attachments');
                        $attachments[] = $fileData['file_path'];
                        \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
                    } catch (\Exception $e) {
                        \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
                        error_log("File upload error: " . $e->getMessage());
                    }
                }
            }
        }

        try {
            // Get HTML description content from TinyMCE
            $descriptionContent = $_POST['description'] ?? '';
            // Sanitize HTML content - allows safe tags but removes scripts and dangerous attributes
            $descriptionContent = Security::sanitizeHtml($descriptionContent);
            
            $occurrenceId = $this->occurrenceModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null,
                'reported_by' => $userId,
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => $descriptionContent,
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
        
        // Security: Verify resource belongs to condominium
        if (!$occurrence) {
            $_SESSION['error'] = 'Ocorrência não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences');
            exit;
        }
        
        // Verify ownership using centralized helper
        try {
            RoleMiddleware::requireResourceBelongsToCondominium('occurrences', $id, $condominiumId);
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Ocorrência não encontrada ou sem permissão de acesso.';
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

        // Get comments
        $userId = AuthMiddleware::userId();
        $user = AuthMiddleware::user();
        $isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin']);
        $comments = $this->commentModel->getByOccurrence($id, $isAdmin);

        // Get history
        $history = $this->historyModel->getByOccurrence($id);

        // Parse attachments
        $attachments = [];
        if (!empty($occurrence['attachments'])) {
            $attachmentsData = json_decode($occurrence['attachments'], true);
            if (is_array($attachmentsData)) {
                foreach ($attachmentsData as $attachment) {
                    $attachments[] = [
                        'path' => $attachment,
                        'name' => basename($attachment),
                        'url' => BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id . '/attachments/' . urlencode($attachment)
                    ];
                }
            }
        }

        // Get condominium for sidebar
        $condominium = $this->condominiumModel->findById($condominiumId);

        $this->loadPageTranslations('occurrences');
        
        $this->data += [
            'viewName' => 'pages/occurrences/show.html.twig',
            'page' => ['titulo' => $occurrence['title']],
            'condominium' => $condominium,
            'occurrence' => $occurrence,
            'suppliers' => $suppliers,
            'comments' => $comments,
            'history' => $history,
            'attachments' => $attachments,
            'is_admin' => $isAdmin,
            'current_user_id' => $userId,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        $this->renderMainTemplate();
    }

    public function updateStatus(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        $userId = AuthMiddleware::userId();

        $occurrence = $this->occurrenceModel->findById($id);
        if (!$occurrence) {
            $_SESSION['error'] = 'Ocorrência não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
            exit;
        }

        $oldStatus = $occurrence['status'];

        if ($this->occurrenceModel->changeStatus($id, $status, $notes)) {
            // Log history
            $this->historyModel->logStatusChange($id, $userId, $oldStatus, $status, $notes);
            
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
        $userId = AuthMiddleware::userId();

        $occurrence = $this->occurrenceModel->findById($id);
        if (!$occurrence) {
            $_SESSION['error'] = 'Ocorrência não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
            exit;
        }

        $oldAssignedTo = $occurrence['assigned_to'];
        $oldSupplierId = $occurrence['supplier_id'];

        if ($this->occurrenceModel->assign($id, $assignedTo, $supplierId)) {
            // Log history
            if ($oldAssignedTo != $assignedTo) {
                $this->historyModel->logAssignment($id, $userId, 'assigned_to', $oldAssignedTo, $assignedTo);
            }
            if ($oldSupplierId != $supplierId) {
                $this->historyModel->logAssignment($id, $userId, 'supplier_id', $oldSupplierId, $supplierId);
            }
            
            $_SESSION['success'] = 'Ocorrência atribuída com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao atribuir ocorrência.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
        exit;
    }

    public function addComment(int $condominiumId, int $id)
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

        $userId = AuthMiddleware::userId();
        $comment = trim(Security::sanitize($_POST['comment'] ?? ''));
        $isInternal = !empty($_POST['is_internal']) && $_POST['is_internal'] === '1';

        if (empty($comment)) {
            $_SESSION['error'] = 'O comentário não pode estar vazio.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
            exit;
        }

        try {
            $commentId = $this->commentModel->create([
                'occurrence_id' => $id,
                'user_id' => $userId,
                'comment' => $comment,
                'is_internal' => $isInternal
            ]);

            // Log history
            $this->historyModel->logComment($id, $userId, 'Comentário adicionado');

            // Notify relevant users
            $occurrence = $this->occurrenceModel->findById($id);
            if ($occurrence) {
                $this->notificationService->notifyOccurrenceComment($id, $condominiumId, $userId);
            }

            $_SESSION['success'] = 'Comentário adicionado com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao adicionar comentário: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
        exit;
    }

    public function deleteComment(int $condominiumId, int $occurrenceId, int $commentId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $occurrenceId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $occurrenceId);
            exit;
        }

        $userId = AuthMiddleware::userId();
        $user = AuthMiddleware::user();
        $isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin']);

        $comment = $this->commentModel->findById($commentId);
        if (!$comment || $comment['occurrence_id'] != $occurrenceId) {
            $_SESSION['error'] = 'Comentário não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $occurrenceId);
            exit;
        }

        // Check permissions
        if ($comment['user_id'] != $userId && !$isAdmin) {
            $_SESSION['error'] = 'Não tem permissão para eliminar este comentário.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $occurrenceId);
            exit;
        }

        if ($this->commentModel->delete($commentId)) {
            $_SESSION['success'] = 'Comentário eliminado com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao eliminar comentário.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $occurrenceId);
        exit;
    }

    public function downloadAttachment(int $condominiumId, int $id, string $attachmentPath)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $occurrence = $this->occurrenceModel->findById($id);
        
        if (!$occurrence || $occurrence['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Ocorrência não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences');
            exit;
        }

        $attachments = json_decode($occurrence['attachments'] ?? '[]', true);
        if (!in_array($attachmentPath, $attachments)) {
            $_SESSION['error'] = 'Anexo não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
            exit;
        }

        $filePath = $this->fileStorageService->getFilePath($attachmentPath);
        
        if (!file_exists($filePath)) {
            $_SESSION['error'] = 'Ficheiro não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/occurrences/' . $id);
            exit;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($attachmentPath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    
    /**
     * Upload inline image for editor
     */
    public function uploadInlineImage(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Método não permitido', 400, 'INVALID_METHOD');
        }

        if (empty($_FILES['file'])) {
            $this->jsonError('Nenhum ficheiro enviado', 400, 'NO_FILE');
        }

        // Check rate limit for file uploads
        try {
            \App\Middleware\RateLimitMiddleware::require('file_upload');
        } catch (\Exception $e) {
            $this->jsonError($e, 429, 'RATE_LIMIT_EXCEEDED');
        }

        try {
            $uploadResult = $this->fileStorageService->upload($_FILES['file'], $condominiumId, 'occurrences', 'inline', 2097152);
            
            // Record successful upload
            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
            
            // Return URL for TinyMCE
            $fileUrl = $this->fileStorageService->getFileUrl($uploadResult['file_path']);
            
            $this->jsonSuccess(['location' => $fileUrl]);
        } catch (\Exception $e) {
            \App\Middleware\RateLimitMiddleware::recordAttempt('file_upload');
            $this->jsonError($e, 400, 'UPLOAD_ERROR');
        }
        exit;
    }
}

