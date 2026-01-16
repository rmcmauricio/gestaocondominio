<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\VoteOption;
use App\Models\Condominium;

class VoteOptionController extends Controller
{
    protected $optionModel;

    public function __construct()
    {
        parent::__construct();
        $this->optionModel = new VoteOption();
    }

    public function index(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

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
            'viewName' => 'pages/vote-options/index.html.twig',
            'page' => ['titulo' => 'Opções de Resposta'],
            'condominium' => $condominium,
            'options' => $options,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
            exit;
        }

        $optionLabel = trim($_POST['option_label'] ?? '');
        if (empty($optionLabel)) {
            $_SESSION['error'] = 'O rótulo da opção é obrigatório.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
            exit;
        }

        try {
            // Get max order_index
            $options = $this->optionModel->getByCondominium($condominiumId);
            $maxOrder = 0;
            foreach ($options as $opt) {
                $maxOrder = max($maxOrder, $opt['order_index']);
            }

            $this->optionModel->create([
                'condominium_id' => $condominiumId,
                'option_label' => Security::sanitize($optionLabel),
                'order_index' => $maxOrder + 1,
                'is_default' => false,
                'is_active' => true
            ]);

            $_SESSION['success'] = 'Opção criada com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar opção: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
        exit;
    }

    public function update(int $condominiumId, int $optionId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
            exit;
        }

        $option = $this->optionModel->findById($optionId);
        if (!$option || $option['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Opção não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
            exit;
        }

        $updateData = [];
        if (isset($_POST['option_label'])) {
            $updateData['option_label'] = Security::sanitize(trim($_POST['option_label']));
        }
        if (isset($_POST['order_index'])) {
            $updateData['order_index'] = (int)$_POST['order_index'];
        }
        if (isset($_POST['is_active'])) {
            $updateData['is_active'] = $_POST['is_active'] === '1' || $_POST['is_active'] === 'true';
        }

        try {
            $this->optionModel->update($optionId, $updateData);
            $_SESSION['success'] = 'Opção atualizada com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar opção: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
        exit;
    }

    public function delete(int $condominiumId, int $optionId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['_method']) || $_POST['_method'] !== 'DELETE')) {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
            exit;
        }

        $option = $this->optionModel->findById($optionId);
        if (!$option || $option['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Opção não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
            exit;
        }

        try {
            $this->optionModel->delete($optionId);
            $_SESSION['success'] = 'Opção eliminada com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao eliminar opção: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/vote-options');
        exit;
    }
}
