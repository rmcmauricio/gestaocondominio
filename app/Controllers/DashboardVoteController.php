<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\StandaloneVote;
use App\Models\StandaloneVoteResponse;
use App\Models\VoteOption;
use App\Models\CondominiumUser;

class DashboardVoteController extends Controller
{
    protected $voteModel;
    protected $responseModel;
    protected $optionModel;

    public function __construct()
    {
        parent::__construct();
        $this->voteModel = new StandaloneVote();
        $this->responseModel = new StandaloneVoteResponse();
        $this->optionModel = new VoteOption();
    }

    public function vote(int $condominiumId, int $voteId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Método não permitido']);
            exit;
        }

        header('Content-Type: application/json');

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!\App\Core\Security::verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Token de segurança inválido']);
            exit;
        }

        $vote = $this->voteModel->findById($voteId);
        if (!$vote || $vote['condominium_id'] != $condominiumId) {
            http_response_code(404);
            echo json_encode(['error' => 'Votação não encontrada']);
            exit;
        }

        if ($vote['status'] !== 'open') {
            http_response_code(400);
            echo json_encode(['error' => 'Esta votação não está aberta para votação']);
            exit;
        }

        $userId = AuthMiddleware::userId();
        $voteOptionId = (int)($_POST['vote_option_id'] ?? 0);

        if ($voteOptionId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Opção de voto inválida']);
            exit;
        }

        // Verify option belongs to condominium
        $option = $this->optionModel->findById($voteOptionId);
        if (!$option || $option['condominium_id'] != $condominiumId || !$option['is_active']) {
            http_response_code(400);
            echo json_encode(['error' => 'Opção de voto inválida']);
            exit;
        }

        // Get user's fraction for this condominium
        $condominiumUserModel = new CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        $userFraction = null;
        foreach ($userCondominiums as $uc) {
            if ($uc['condominium_id'] == $condominiumId && $uc['fraction_id']) {
                $userFraction = $uc['fraction_id'];
                break;
            }
        }

        if (!$userFraction) {
            http_response_code(403);
            echo json_encode(['error' => 'Não tem uma fração associada neste condomínio']);
            exit;
        }

        try {
            $this->responseModel->createOrUpdate([
                'standalone_vote_id' => $voteId,
                'fraction_id' => $userFraction,
                'user_id' => $userId,
                'vote_option_id' => $voteOptionId,
                'notes' => Security::sanitizeNullable($_POST['notes'] ?? null)
            ]);

            // Get updated results
            $results = $this->voteModel->getResults($voteId);
            $userVote = $this->responseModel->getByFraction($voteId, $userFraction);

            echo json_encode([
                'success' => true,
                'message' => 'Voto registado com sucesso!',
                'results' => $results,
                'user_vote' => $userVote
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao registar voto: ' . $e->getMessage()]);
        }
    }
}
