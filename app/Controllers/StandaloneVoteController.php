<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\StandaloneVote;
use App\Models\VoteOption;
use App\Models\Condominium;
use App\Services\NotificationService;
use App\Services\AuditService;

class StandaloneVoteController extends Controller
{
    protected $voteModel;
    protected $optionModel;
    protected $notificationService;
    protected $auditService;

    public function __construct()
    {
        parent::__construct();
        $this->voteModel = new StandaloneVote();
        $this->optionModel = new VoteOption();
        $this->notificationService = new NotificationService();
        $this->auditService = new AuditService();
    }

    public function index(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominiumModel = new Condominium();
        $condominium = $condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $status = $_GET['status'] ?? null;
        $filters = [];
        if ($status) {
            $filters['status'] = $status;
        }
        $votes = $this->voteModel->getByCondominium($condominiumId, $filters);

        $this->loadPageTranslations('votes');
        
        $userId = AuthMiddleware::userId();
        $this->data += [
            'viewName' => 'pages/votes/index.html.twig',
            'page' => ['titulo' => 'Votações'],
            'condominium' => $condominium,
            'votes' => $votes,
            'current_status' => $status,
            'is_admin' => RoleMiddleware::isAdminInCondominium($userId, $condominiumId),
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

        $condominiumModel = new Condominium();
        $condominium = $condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $options = $this->optionModel->getByCondominium($condominiumId);

        $this->loadPageTranslations('votes');
        
        $this->data += [
            'viewName' => 'pages/votes/create.html.twig',
            'page' => ['titulo' => 'Criar Votação'],
            'condominium' => $condominium,
            'vote_options' => $options,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
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
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        $userId = AuthMiddleware::userId();

        // Get and validate allowed options
        $allowedOptions = [];
        if (isset($_POST['allowed_options']) && is_array($_POST['allowed_options'])) {
            foreach ($_POST['allowed_options'] as $optionId) {
                $optionId = (int)$optionId;
                if ($optionId > 0) {
                    // Verify option belongs to condominium
                    $option = $this->optionModel->findById($optionId);
                    if ($option && $option['condominium_id'] == $condominiumId && $option['is_active']) {
                        $allowedOptions[] = $optionId;
                    }
                }
            }
        }

        if (empty($allowedOptions)) {
            $_SESSION['error'] = 'Selecione pelo menos uma opção de resposta permitida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/create');
            exit;
        }

        try {
            $voteId = $this->voteModel->create([
                'condominium_id' => $condominiumId,
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => Security::sanitizeNullable($_POST['description'] ?? null),
                'allowed_options' => $allowedOptions,
                'status' => 'draft',
                'created_by' => $userId
            ]);

            // Log audit
            if ($voteId) {
                $this->auditService->log([
                    'action' => 'standalone_vote_created',
                    'model' => 'standalone_vote',
                    'model_id' => $voteId,
                    'description' => "Votação standalone criada no condomínio ID {$condominiumId}. Título: " . Security::sanitize($_POST['title'] ?? '')
                ]);
            }

            $_SESSION['success'] = 'Votação criada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar votação: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/create');
            exit;
        }
    }

    public function show(int $condominiumId, int $voteId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominiumModel = new Condominium();
        $condominium = $condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $vote = $this->voteModel->findById($voteId);
        if (!$vote || $vote['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Votação não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        // Get allowed options for this vote
        $allowedOptionIds = $vote['allowed_options'] ?? [];
        $allOptions = $this->optionModel->getByCondominium($condominiumId);
        
        // Filter to only allowed options
        $options = [];
        if (!empty($allowedOptionIds)) {
            foreach ($allOptions as $option) {
                if (in_array($option['id'], $allowedOptionIds)) {
                    $options[] = $option;
                }
            }
        } else {
            // Backward compatibility: if no allowed options specified, use all
            $options = $allOptions;
        }
        
        $results = $this->voteModel->getResults($voteId);

        // Get user's fraction for this condominium
        $userId = AuthMiddleware::userId();
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        $userFraction = null;
        foreach ($userCondominiums as $uc) {
            if ($uc['condominium_id'] == $condominiumId && $uc['fraction_id']) {
                $userFraction = $uc['fraction_id'];
                break;
            }
        }

        // Get user's vote if exists
        $userVote = null;
        if ($userFraction) {
            $responseModel = new \App\Models\StandaloneVoteResponse();
            $userVote = $responseModel->getByFraction($voteId, $userFraction);
        }

        $this->loadPageTranslations('votes');
        
        $this->data += [
            'viewName' => 'pages/votes/show.html.twig',
            'page' => ['titulo' => $vote['title']],
            'condominium' => $condominium,
            'vote' => $vote,
            'options' => $options,
            'results' => $results,
            'user_fraction' => $userFraction,
            'user_vote' => $userVote,
            'is_admin' => RoleMiddleware::isAdminInCondominium($userId, $condominiumId),
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function edit(int $condominiumId, int $voteId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $condominiumModel = new Condominium();
        $condominium = $condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $vote = $this->voteModel->findById($voteId);
        if (!$vote || $vote['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Votação não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        if ($vote['status'] !== 'draft') {
            $_SESSION['error'] = 'Apenas votações em rascunho podem ser editadas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        $this->loadPageTranslations('votes');
        
        $this->data += [
            'viewName' => 'pages/votes/edit.html.twig',
            'page' => ['titulo' => 'Editar Votação'],
            'condominium' => $condominium,
            'vote' => $vote,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function update(int $condominiumId, int $voteId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        $vote = $this->voteModel->findById($voteId);
        if (!$vote || $vote['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Votação não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        if ($vote['status'] !== 'draft') {
            $_SESSION['error'] = 'Apenas votações em rascunho podem ser editadas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        // Get and validate allowed options
        $allowedOptions = [];
        if (isset($_POST['allowed_options']) && is_array($_POST['allowed_options'])) {
            foreach ($_POST['allowed_options'] as $optionId) {
                $optionId = (int)$optionId;
                if ($optionId > 0) {
                    // Verify option belongs to condominium
                    $option = $this->optionModel->findById($optionId);
                    if ($option && $option['condominium_id'] == $condominiumId && $option['is_active']) {
                        $allowedOptions[] = $optionId;
                    }
                }
            }
        }

        if (empty($allowedOptions)) {
            $_SESSION['error'] = 'Selecione pelo menos uma opção de resposta permitida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId . '/edit');
            exit;
        }

        try {
            $this->voteModel->update($voteId, [
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => Security::sanitizeNullable($_POST['description'] ?? null),
                'allowed_options' => $allowedOptions
            ]);

            // Log audit
            $this->auditService->log([
                'action' => 'standalone_vote_updated',
                'model' => 'standalone_vote',
                'model_id' => $voteId,
                'description' => "Votação standalone atualizada no condomínio ID {$condominiumId}. Título: " . Security::sanitize($_POST['title'] ?? '')
            ]);

            $_SESSION['success'] = 'Votação atualizada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar votação: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId . '/edit');
            exit;
        }
    }

    public function start(int $condominiumId, int $voteId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        $vote = $this->voteModel->findById($voteId);
        if (!$vote || $vote['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Votação não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        try {
            $this->voteModel->startVoting($voteId);
            
            // Notify all users in the condominium
            $this->notificationService->notifyVoteOpened($voteId, $condominiumId, $vote['title']);
            
            $_SESSION['success'] = 'Votação aberta com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao abrir votação: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
        exit;
    }

    public function close(int $condominiumId, int $voteId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        $vote = $this->voteModel->findById($voteId);
        if (!$vote || $vote['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Votação não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        try {
            $this->voteModel->closeVoting($voteId);
            
            // Log audit
            $this->auditService->log([
                'action' => 'standalone_vote_closed',
                'model' => 'standalone_vote',
                'model_id' => $voteId,
                'description' => "Votação standalone encerrada no condomínio ID {$condominiumId}. Título: {$vote['title']}"
            ]);
            
            $_SESSION['success'] = 'Votação encerrada com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao encerrar votação: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
        exit;
    }

    public function delete(int $condominiumId, int $voteId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['_method']) || $_POST['_method'] !== 'DELETE')) {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        $vote = $this->voteModel->findById($voteId);
        if (!$vote || $vote['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Votação não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        if ($vote['status'] !== 'draft') {
            $_SESSION['error'] = 'Apenas votações em rascunho podem ser eliminadas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        try {
            // Log audit before deletion
            $this->auditService->log([
                'action' => 'standalone_vote_deleted',
                'model' => 'standalone_vote',
                'model_id' => $voteId,
                'description' => "Votação standalone eliminada do condomínio ID {$condominiumId}. Título: {$vote['title']}"
            ]);
            
            global $db;
            $stmt = $db->prepare("DELETE FROM standalone_votes WHERE id = :id");
            $stmt->execute([':id' => $voteId]);
            $_SESSION['success'] = 'Votação eliminada com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao eliminar votação: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
        exit;
    }

    public function vote(int $condominiumId, int $voteId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405, 'INVALID_METHOD');
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403, 'INVALID_CSRF');
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        $vote = $this->voteModel->findById($voteId);
        if (!$vote || $vote['condominium_id'] != $condominiumId) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Votação não encontrada', 404, 'VOTE_NOT_FOUND');
            }
            $_SESSION['error'] = 'Votação não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes');
            exit;
        }

        if ($vote['status'] !== 'open') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Esta votação não está aberta para votação', 400, 'VOTE_NOT_OPEN');
            }
            $_SESSION['error'] = 'Esta votação não está aberta para votação.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        $userId = AuthMiddleware::userId();
        $voteOptionId = (int)($_POST['vote_option_id'] ?? 0);

        if ($voteOptionId <= 0) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Opção de voto inválida', 400, 'INVALID_VOTE_OPTION');
            }
            $_SESSION['error'] = 'Opção de voto inválida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        // Verify option belongs to condominium and is allowed for this vote
        $option = $this->optionModel->findById($voteOptionId);
        if (!$option || $option['condominium_id'] != $condominiumId || !$option['is_active']) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Opção de voto inválida', 400, 'INVALID_VOTE_OPTION');
            }
            $_SESSION['error'] = 'Opção de voto inválida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        // Verify option is in allowed options for this vote
        $allowedOptionIds = $vote['allowed_options'] ?? [];
        if (!empty($allowedOptionIds) && !in_array($voteOptionId, $allowedOptionIds)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Esta opção não está permitida nesta votação', 400, 'OPTION_NOT_ALLOWED');
            }
            $_SESSION['error'] = 'Esta opção não está permitida nesta votação.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        // Get user's fraction for this condominium
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        $userFraction = null;
        foreach ($userCondominiums as $uc) {
            if ($uc['condominium_id'] == $condominiumId && $uc['fraction_id']) {
                $userFraction = $uc['fraction_id'];
                break;
            }
        }

        if (!$userFraction) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Não tem uma fração associada neste condomínio', 403, 'NO_FRACTION');
            }
            $_SESSION['error'] = 'Não tem uma fração associada neste condomínio.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
            exit;
        }

        try {
            $responseModel = new \App\Models\StandaloneVoteResponse();
            $responseModel->createOrUpdate([
                'standalone_vote_id' => $voteId,
                'fraction_id' => $userFraction,
                'user_id' => $userId,
                'vote_option_id' => $voteOptionId,
                'notes' => Security::sanitizeNullable($_POST['notes'] ?? null)
            ]);

            // Log audit
            $this->auditService->log([
                'action' => 'standalone_vote_cast',
                'model' => 'standalone_vote',
                'model_id' => $voteId,
                'description' => "Voto registado na fração ID {$userFraction} para a votação standalone '{$vote['title']}' no condomínio ID {$condominiumId}. Opção: {$option['option_label']}"
            ]);

            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                $results = $this->voteModel->getResults($voteId);
                $userVote = $responseModel->getByFraction($voteId, $userFraction);
                echo json_encode([
                    'success' => true,
                    'message' => 'Voto registado com sucesso!',
                    'results' => $results,
                    'user_vote' => $userVote
                ]);
                exit;
            }

            $_SESSION['success'] = 'Voto registado com sucesso!';
        } catch (\Exception $e) {
            if ($this->isAjaxRequest()) {
                $this->jsonError($e, 500, 'VOTE_ERROR');
            }
            $_SESSION['error'] = 'Erro ao registar voto. Por favor, tente novamente.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/votes/' . $voteId);
        exit;
    }

    /**
     * Check if request is AJAX
     */
    private function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
