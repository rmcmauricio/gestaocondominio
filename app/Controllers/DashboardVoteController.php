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
            $this->jsonError('Método não permitido', 405, 'INVALID_METHOD');
        }

        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!\App\Core\Security::verifyCSRFToken($csrfToken)) {
            $this->jsonError('Token de segurança inválido', 403, 'INVALID_CSRF');
        }

        $vote = $this->voteModel->findById($voteId);
        if (!$vote || $vote['condominium_id'] != $condominiumId) {
            $this->jsonError('Votação não encontrada', 404, 'VOTE_NOT_FOUND');
        }

        if ($vote['status'] !== 'open') {
            $this->jsonError('Esta votação não está aberta para votação', 400, 'VOTE_NOT_OPEN');
        }

        $userId = AuthMiddleware::userId();
        $voteOptionId = (int)($_POST['vote_option_id'] ?? 0);

        if ($voteOptionId <= 0) {
            $this->jsonError('Opção de voto inválida', 400, 'INVALID_VOTE_OPTION');
        }

        // Verify option belongs to condominium
        $option = $this->optionModel->findById($voteOptionId);
        if (!$option || $option['condominium_id'] != $condominiumId || !$option['is_active']) {
            $this->jsonError('Opção de voto inválida', 400, 'INVALID_VOTE_OPTION');
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
            $this->jsonError('Não tem uma fração associada neste condomínio', 403, 'NO_FRACTION');
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

            $this->jsonSuccess([
                'results' => $results,
                'user_vote' => $userVote
            ], 'Voto registado com sucesso!');
        } catch (\Exception $e) {
            $this->jsonError($e, 500, 'VOTE_ERROR');
        }
    }
}
