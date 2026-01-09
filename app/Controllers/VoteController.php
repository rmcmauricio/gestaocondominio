<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Vote;
use App\Models\Assembly;

class VoteController extends Controller
{
    protected $voteModel;
    protected $assemblyModel;

    public function __construct()
    {
        parent::__construct();
        $this->voteModel = new Vote();
        $this->assemblyModel = new Assembly();
    }

    public function createTopic(int $condominiumId, int $assemblyId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $assembly = $this->assemblyModel->findById($assemblyId);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        $this->loadPageTranslations('votes');
        
        $this->data += [
            'viewName' => 'pages/votes/create-topic.html.twig',
            'page' => ['titulo' => 'Criar Tópico de Votação'],
            'assembly' => $assembly,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function storeTopic(int $condominiumId, int $assemblyId)
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

        global $db;
        $userId = AuthMiddleware::userId();

        try {
            $stmt = $db->prepare("
                INSERT INTO assembly_vote_topics (
                    assembly_id, title, description, options, created_by
                )
                VALUES (
                    :assembly_id, :title, :description, :options, :created_by
                )
            ");

            $options = [];
            if (isset($_POST['options']) && is_array($_POST['options'])) {
                foreach ($_POST['options'] as $option) {
                    if (!empty(trim($option))) {
                        $options[] = trim($option);
                    }
                }
            }

            $stmt->execute([
                ':assembly_id' => $assemblyId,
                ':title' => Security::sanitize($_POST['title'] ?? ''),
                ':description' => Security::sanitize($_POST['description'] ?? ''),
                ':options' => json_encode($options),
                ':created_by' => $userId
            ]);

            $_SESSION['success'] = 'Tópico de votação criado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar tópico: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
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

        try {
            $this->voteModel->create([
                'assembly_id' => $assemblyId,
                'topic_id' => $topicId,
                'fraction_id' => $fractionId,
                'user_id' => $userId,
                'vote_option' => $voteOption,
                'notes' => Security::sanitize($_POST['notes'] ?? '')
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

    public function results(int $condominiumId, int $assemblyId, int $topicId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $results = $this->voteModel->calculateResults($topicId);

        $this->loadPageTranslations('votes');
        
        $this->data += [
            'viewName' => 'pages/votes/results.html.twig',
            'page' => ['titulo' => 'Resultados da Votação'],
            'results' => $results
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}





