<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Space;
use App\Models\Condominium;

class SpaceController extends Controller
{
    protected $spaceModel;
    protected $condominiumModel;

    public function __construct()
    {
        parent::__construct();
        $this->spaceModel = new Space();
        $this->condominiumModel = new Condominium();
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

        // Get all spaces (including inactive) for management
        $spaces = $this->spaceModel->getAllByCondominium($condominiumId);
        
        // Check if user is admin
        $user = AuthMiddleware::user();
        $isAdmin = ($user['role'] === 'admin' || $user['role'] === 'super_admin');
        
        // Get user's first fraction for pre-selection in reservation
        $userId = AuthMiddleware::userId();
        $userFirstFractionId = null;
        
        if (!$isAdmin) {
            $condominiumUserModel = new \App\Models\CondominiumUser();
            $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
            $userFractions = array_filter($userCondominiums, function($uc) use ($condominiumId) {
                return $uc['condominium_id'] == $condominiumId && !empty($uc['fraction_id']);
            });
            
            if (!empty($userFractions)) {
                $userFirstFractionId = reset($userFractions)['fraction_id'];
            }
        }

        $this->loadPageTranslations('spaces');
        
        $this->data += [
            'viewName' => 'pages/spaces/index.html.twig',
            'page' => ['titulo' => 'Espaços Comuns'],
            'condominium' => $condominium,
            'spaces' => $spaces,
            'is_admin' => $isAdmin,
            'user_first_fraction_id' => $userFirstFractionId,
            'csrf_token' => Security::generateCSRFToken(),
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
        RoleMiddleware::requireAdmin(); // Only admins can manage spaces

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('spaces');
        
        $this->data += [
            'viewName' => 'pages/spaces/create.html.twig',
            'page' => ['titulo' => 'Adicionar Espaço'],
            'condominium' => $condominium,
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
        RoleMiddleware::requireAdmin(); // Only admins can manage spaces

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces/create');
            exit;
        }

        try {
            $availableHours = [];
            if (isset($_POST['available_hours'])) {
                foreach ($_POST['available_hours'] as $day => $hours) {
                    if (!empty($hours['start']) && !empty($hours['end'])) {
                        $availableHours[$day] = [
                            'start' => $hours['start'],
                            'end' => $hours['end']
                        ];
                    }
                }
            }

            $spaceId = $this->spaceModel->create([
                'condominium_id' => $condominiumId,
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? ''),
                'capacity' => !empty($_POST['capacity']) ? (int)$_POST['capacity'] : null,
                'price_per_hour' => (float)($_POST['price_per_hour'] ?? 0),
                'price_per_day' => (float)($_POST['price_per_day'] ?? 0),
                'deposit_required' => (float)($_POST['deposit_required'] ?? 0),
                'requires_approval' => isset($_POST['requires_approval']),
                'rules' => Security::sanitize($_POST['rules'] ?? ''),
                'available_hours' => $availableHours
            ]);

            $_SESSION['success'] = 'Espaço adicionado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao adicionar espaço: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces/create');
            exit;
        }
    }

    public function edit(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin(); // Only admins can manage spaces

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $space = $this->spaceModel->findById($id);
        if (!$space || $space['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Espaço não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
            exit;
        }

        // Decode available_hours if exists
        if (!empty($space['available_hours'])) {
            $space['available_hours'] = json_decode($space['available_hours'], true);
        }

        $this->loadPageTranslations('spaces');
        
        $this->data += [
            'viewName' => 'pages/spaces/edit.html.twig',
            'page' => ['titulo' => 'Editar Espaço'],
            'condominium' => $condominium,
            'space' => $space,
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
        RoleMiddleware::requireAdmin(); // Only admins can manage spaces

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces/' . $id . '/edit');
            exit;
        }

        $space = $this->spaceModel->findById($id);
        if (!$space || $space['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Espaço não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
            exit;
        }

        try {
            $availableHours = [];
            if (isset($_POST['available_hours'])) {
                foreach ($_POST['available_hours'] as $day => $hours) {
                    if (!empty($hours['start']) && !empty($hours['end'])) {
                        $availableHours[$day] = [
                            'start' => $hours['start'],
                            'end' => $hours['end']
                        ];
                    }
                }
            }

            $this->spaceModel->update($id, [
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? ''),
                'capacity' => !empty($_POST['capacity']) ? (int)$_POST['capacity'] : null,
                'price_per_hour' => (float)($_POST['price_per_hour'] ?? 0),
                'price_per_day' => (float)($_POST['price_per_day'] ?? 0),
                'deposit_required' => (float)($_POST['deposit_required'] ?? 0),
                'requires_approval' => isset($_POST['requires_approval']),
                'rules' => Security::sanitize($_POST['rules'] ?? ''),
                'available_hours' => $availableHours,
                'is_active' => isset($_POST['is_active'])
            ]);

            $_SESSION['success'] = 'Espaço atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar espaço: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces/' . $id . '/edit');
            exit;
        }
    }

    public function delete(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin(); // Only admins can manage spaces

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
            exit;
        }

        $space = $this->spaceModel->findById($id);
        if (!$space || $space['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Espaço não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
            exit;
        }

        try {
            $this->spaceModel->delete($id);
            $_SESSION['success'] = 'Espaço eliminado com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao eliminar espaço: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/spaces');
        exit;
    }
}

