<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Vote;
use App\Models\Assembly;
use App\Models\VoteTopic;
use App\Models\Fraction;
use App\Models\AssemblyAgendaPoint;
use App\Services\AuditService;

class VoteController extends Controller
{
    protected $voteModel;
    protected $assemblyModel;
    protected $topicModel;
    protected $fractionModel;
    protected $agendaPointModel;
    protected $auditService;

    public function __construct()
    {
        parent::__construct();
        $this->voteModel = new Vote();
        $this->assemblyModel = new Assembly();
        $this->topicModel = new VoteTopic();
        $this->fractionModel = new Fraction();
        $this->agendaPointModel = new AssemblyAgendaPoint();
        $this->auditService = new AuditService();
    }

    public function createTopic(int $condominiumId, int $assemblyId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $assembly = $this->assemblyModel->findById($assemblyId);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        // Get condominium for sidebar
        $condominiumModel = new \App\Models\Condominium();
        $condominium = $condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        // Get vote options for condominium
        $voteOptionModel = new \App\Models\VoteOption();
        $voteOptions = $voteOptionModel->getByCondominium($condominiumId);
        $defaultOptions = array_map(function($opt) {
            return $opt['option_label'];
        }, $voteOptions);

        $pointId = isset($_GET['point_id']) ? (int) $_GET['point_id'] : 0;
        $return = $_GET['return'] ?? '';
        $returnToEdit = ($return === 'edit');
        $returnToShow = ($return === 'show' || $pointId > 0);
        $pointTitle = '';
        if ($pointId > 0) {
            $pt = $this->agendaPointModel->findById($pointId);
            $pointTitle = ($pt && (int)($pt['assembly_id'] ?? 0) === (int)$assemblyId) ? ($pt['title'] ?? '') : '';
        }
        $backUrl = $returnToShow
            ? BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId
            : ($returnToEdit
                ? BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId . '/edit'
                : BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);

        $this->loadPageTranslations('votes');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/votes/create-topic.html.twig',
            'page' => ['titulo' => 'Criar Tópico de Votação'],
            'condominium' => $condominium,
            'assembly' => $assembly,
            'vote_options' => $voteOptions,
            'default_options' => $defaultOptions,
            'csrf_token' => Security::generateCSRFToken(),
            'return_to_edit' => $returnToEdit,
            'return_to_show' => $returnToShow,
            'point_id' => $pointId,
            'point_title' => $pointTitle,
            'back_url' => $backUrl,
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function storeTopic(int $condominiumId, int $assemblyId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $userId = AuthMiddleware::userId();

        try {
            $options = [];
            if (isset($_POST['options']) && is_array($_POST['options'])) {
                foreach ($_POST['options'] as $option) {
                    if (!empty(trim($option))) {
                        $options[] = trim($option);
                    }
                }
            }

            // Default options from condominium vote_options if none provided
            if (empty($options)) {
                $voteOptionModel = new \App\Models\VoteOption();
                $voteOptions = $voteOptionModel->getByCondominium($condominiumId);
                if (!empty($voteOptions)) {
                    $options = array_map(function($opt) {
                        return $opt['option_label'];
                    }, $voteOptions);
                } else {
                    // Fallback to old defaults
                    $options = ['A favor', 'Contra', 'Abstenção'];
                }
            }

            // Validate options against condominium vote_options
            $voteOptionModel = new \App\Models\VoteOption();
            $validOptions = $voteOptionModel->getByCondominium($condominiumId);
            $validOptionLabels = array_map(function($opt) {
                return $opt['option_label'];
            }, $validOptions);
            
            // Check if all provided options are valid
            foreach ($options as $option) {
                if (!in_array($option, $validOptionLabels)) {
                    $_SESSION['error'] = 'Opção de voto inválida: ' . $option . '. Use apenas as opções configuradas para este condomínio.';
                    $ret = $_POST['return'] ?? '';
                    $pid = (int) ($_POST['point_id'] ?? 0);
                    $suffix = ($ret === 'edit') ? '?return=edit' : (($ret === 'show' || $pid > 0) ? '?return=show&point_id=' . $pid : '');
                    header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId . '/votes/create-topic' . $suffix);
                    exit;
                }
            }

            // Get max order_index
            $topics = $this->topicModel->getByAssembly($assemblyId);
            $maxOrder = 0;
            foreach ($topics as $topic) {
                $maxOrder = max($maxOrder, $topic['order_index'] ?? 0);
            }

            $newTopicId = $this->topicModel->create([
                'assembly_id' => $assemblyId,
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'options' => $options,
                'order_index' => $maxOrder + 1,
                'created_by' => $userId
            ]);

            // Log audit
            if ($newTopicId) {
                $this->auditService->log([
                    'action' => 'vote_topic_created',
                    'model' => 'vote_topic',
                    'model_id' => $newTopicId,
                    'description' => "Tópico de votação criado na assembleia ID {$assemblyId}. Título: " . Security::sanitize($_POST['title'] ?? '')
                ]);
            }

            $pointId = isset($_POST['point_id']) ? (int) $_POST['point_id'] : 0;
            if ($pointId > 0) {
                $point = $this->agendaPointModel->findById($pointId);
                if ($point && (int) $point['assembly_id'] === (int) $assemblyId) {
                    $this->agendaPointModel->addVoteTopicToPoint($pointId, $newTopicId, (int) $assemblyId);
                    $_SESSION['success'] = 'Tópico de votação criado e associado ao ponto.';
                } else {
                    $_SESSION['success'] = 'Tópico de votação criado com sucesso!';
                }
            } else {
                $_SESSION['success'] = 'Tópico de votação criado com sucesso!';
            }

            $ret = $_POST['return'] ?? '';
            $redirect = BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId;
            if ($ret === 'edit') {
                $redirect = BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId . '/edit';
            }
            header('Location: ' . $redirect);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar tópico: ' . $e->getMessage();
            $ret = $_POST['return'] ?? '';
            $pid = (int) ($_POST['point_id'] ?? 0);
            $suffix = ($ret === 'edit') ? '?return=edit' : (($ret === 'show' || $pid > 0) ? '?return=show&point_id=' . $pid : '');
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId . '/votes/create-topic' . $suffix);
            exit;
        }
    }

    public function vote(int $condominiumId, int $assemblyId, int $topicId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $userId = AuthMiddleware::userId();
        $fractionId = (int)$_POST['fraction_id'];
        $voteOption = Security::sanitize($_POST['vote_option'] ?? '');

        // Verify topic exists
        $topic = $this->topicModel->findById($topicId);
        if (!$topic || $topic['assembly_id'] != $assemblyId) {
            $_SESSION['error'] = 'Tópico de votação não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        // Verify fraction belongs to user
        $fraction = $this->fractionModel->findById($fractionId);
        if (!$fraction) {
            $_SESSION['error'] = 'Fração não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        // Check if fraction is present in assembly
        $attendeeModel = new \App\Models\AssemblyAttendee();
        if (!$attendeeModel->isPresent($assemblyId, $fractionId)) {
            $_SESSION['error'] = 'Esta fração não está presente na assembleia. Apenas frações presentes podem votar.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        // Note: Removed check for existing vote - now allows updating votes

        // Verify vote option is valid
        if (!in_array($voteOption, $topic['options'])) {
            $_SESSION['error'] = 'Opção de voto inválida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        try {
            // Use upsert to create or update vote
            $this->voteModel->upsert([
                'assembly_id' => $assemblyId,
                'topic_id' => $topicId,
                'fraction_id' => $fractionId,
                'user_id' => $userId,
                'vote_option' => $voteOption,
                'notes' => Security::sanitizeNullable($_POST['notes'] ?? null)
            ]);

            // Log audit
            $this->auditService->log([
                'action' => 'vote_cast',
                'model' => 'vote',
                'model_id' => $topicId,
                'description' => "Voto registado na fração ID {$fractionId} para o tópico '{$topic['title']}' na assembleia ID {$assemblyId}. Opção: {$voteOption}"
            ]);

            $_SESSION['success'] = 'Voto registado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao registar voto: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }
    }

    public function voteBulk(int $condominiumId, int $assemblyId, int $topicId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        // Verify topic exists
        $topic = $this->topicModel->findById($topicId);
        if (!$topic || $topic['assembly_id'] != $assemblyId) {
            $_SESSION['error'] = 'Tópico de votação não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $userId = AuthMiddleware::userId();
        $attendeeModel = new \App\Models\AssemblyAttendee();
        $presentFractionIds = $attendeeModel->getPresentFractions($assemblyId);

        // Process bulk votes
        if (isset($_POST['bulk_votes']) && is_array($_POST['bulk_votes'])) {
            $votesProcessed = 0;
            $errors = [];

            foreach ($_POST['bulk_votes'] as $fractionId => $voteData) {
                $fractionId = (int)$fractionId;
                
                // Skip if no vote option selected
                if (empty($voteData['vote_option']) || $voteData['vote_option'] === '') {
                    continue;
                }

                // Verify fraction is present
                if (!in_array($fractionId, $presentFractionIds)) {
                    $errors[] = "Fração {$fractionId} não está presente";
                    continue;
                }

                // Verify fraction belongs to user
                $fraction = $this->fractionModel->findById($fractionId);
                if (!$fraction) {
                    $errors[] = "Fração {$fractionId} não encontrada";
                    continue;
                }

                $voteOption = Security::sanitize($voteData['vote_option']);
                
                // Verify vote option is valid
                if (!in_array($voteOption, $topic['options'])) {
                    $errors[] = "Opção de voto inválida para fração {$fractionId}";
                    continue;
                }

                try {
                    $this->voteModel->upsert([
                        'assembly_id' => $assemblyId,
                        'topic_id' => $topicId,
                        'fraction_id' => $fractionId,
                        'user_id' => $userId,
                        'vote_option' => $voteOption,
                        'notes' => Security::sanitizeNullable($voteData['notes'] ?? null)
                    ]);
                    $votesProcessed++;
                } catch (\Exception $e) {
                    $errors[] = "Erro ao processar voto da fração {$fractionId}: " . $e->getMessage();
                }
            }

            if ($votesProcessed > 0) {
                // Log audit
                $this->auditService->log([
                    'action' => 'vote_bulk_cast',
                    'model' => 'vote',
                    'model_id' => $topicId,
                    'description' => "Votos em massa registados para o tópico '{$topic['title']}' na assembleia ID {$assemblyId}. Total de votos: {$votesProcessed}"
                ]);
                
                $_SESSION['success'] = "Votos registados com sucesso! ({$votesProcessed} frações)";
            }
            if (!empty($errors)) {
                $_SESSION['error'] = implode('; ', $errors);
            }
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
        exit;
    }

    public function results(int $condominiumId, int $assemblyId, int $topicId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $assembly = $this->assemblyModel->findById($assemblyId);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        $topic = $this->topicModel->findById($topicId);
        if (!$topic || $topic['assembly_id'] != $assemblyId) {
            $_SESSION['error'] = 'Tópico não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $results = $this->voteModel->calculateResults($topicId);
        $votes = $this->voteModel->getByTopic($topicId);

        // Get condominium for sidebar
        $condominiumModel = new \App\Models\Condominium();
        $condominium = $condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('votes');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/votes/results.html.twig',
            'page' => ['titulo' => 'Resultados da Votação'],
            'condominium' => $condominium,
            'assembly' => $assembly,
            'topic' => $topic,
            'results' => $results,
            'votes' => $votes,
            'condominium_id' => $condominiumId,
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function editTopic(int $condominiumId, int $assemblyId, int $topicId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $assembly = $this->assemblyModel->findById($assemblyId);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        $topic = $this->topicModel->findById($topicId);
        if (!$topic || $topic['assembly_id'] != $assemblyId) {
            $_SESSION['error'] = 'Tópico não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        // Get condominium for sidebar
        $condominiumModel = new \App\Models\Condominium();
        $condominium = $condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('votes');
        
        $this->data += [
            'viewName' => 'pages/votes/edit-topic.html.twig',
            'page' => ['titulo' => 'Editar Tópico de Votação'],
            'condominium' => $condominium,
            'assembly' => $assembly,
            'topic' => $topic,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function updateTopic(int $condominiumId, int $assemblyId, int $topicId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $topic = $this->topicModel->findById($topicId);
        if (!$topic || $topic['assembly_id'] != $assemblyId) {
            $_SESSION['error'] = 'Tópico não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        // Check if voting has started
        if ($topic['voting_started_at']) {
            $_SESSION['error'] = 'Não é possível editar um tópico após o início da votação.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        try {
            $options = [];
            if (isset($_POST['options']) && is_array($_POST['options'])) {
                foreach ($_POST['options'] as $option) {
                    if (!empty(trim($option))) {
                        $options[] = trim($option);
                    }
                }
            }

            if (empty($options)) {
                $options = ['Sim', 'Não', 'Abstenção'];
            }

            $this->topicModel->update($topicId, [
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'options' => $options,
                'order_index' => (int)($_POST['order_index'] ?? $topic['order_index'] ?? 0)
            ]);

            // Log audit
            $this->auditService->log([
                'action' => 'vote_topic_updated',
                'model' => 'vote_topic',
                'model_id' => $topicId,
                'description' => "Tópico de votação atualizado na assembleia ID {$assemblyId}. Título: " . Security::sanitize($_POST['title'] ?? '')
            ]);

            $_SESSION['success'] = 'Tópico atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar tópico: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }
    }

    public function deleteTopic(int $condominiumId, int $assemblyId, int $topicId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $topic = $this->topicModel->findById($topicId);
        if (!$topic || $topic['assembly_id'] != $assemblyId) {
            $_SESSION['error'] = 'Tópico não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        // Check if voting has started
        if ($topic['voting_started_at']) {
            $_SESSION['error'] = 'Não é possível eliminar um tópico após o início da votação.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        if ($this->topicModel->delete($topicId)) {
            // Log audit
            $this->auditService->log([
                'action' => 'vote_topic_deleted',
                'model' => 'vote_topic',
                'model_id' => $topicId,
                'description' => "Tópico de votação eliminado da assembleia ID {$assemblyId}. Título: {$topic['title']}"
            ]);
            
            $_SESSION['success'] = 'Tópico eliminado com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao eliminar tópico.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
        exit;
    }

    public function startVoting(int $condominiumId, int $assemblyId, int $topicId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        if ($this->topicModel->startVoting($topicId)) {
            // Log audit
            $topic = $this->topicModel->findById($topicId);
            $this->auditService->log([
                'action' => 'vote_started',
                'model' => 'vote_topic',
                'model_id' => $topicId,
                'description' => "Votação iniciada para o tópico '{$topic['title']}' na assembleia ID {$assemblyId}"
            ]);
            
            $_SESSION['success'] = 'Votação iniciada!';
        } else {
            $_SESSION['error'] = 'Erro ao iniciar votação.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
        exit;
    }

    public function endVoting(int $condominiumId, int $assemblyId, int $topicId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        }

        if ($this->topicModel->endVoting($topicId)) {
            // Log audit
            $topic = $this->topicModel->findById($topicId);
            $this->auditService->log([
                'action' => 'vote_ended',
                'model' => 'vote_topic',
                'model_id' => $topicId,
                'description' => "Votação encerrada para o tópico '{$topic['title']}' na assembleia ID {$assemblyId}"
            ]);
            
            $_SESSION['success'] = 'Votação encerrada!';
        } else {
            $_SESSION['error'] = 'Erro ao encerrar votação.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
        exit;
    }
}





