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

        $spaces = $this->spaceModel->getByCondominium($condominiumId);

        $this->loadPageTranslations('spaces');
        
        $this->data += [
            'viewName' => 'pages/spaces/index.html.twig',
            'page' => ['titulo' => 'Espaços Comuns'],
            'condominium' => $condominium,
            'spaces' => $spaces,
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
}

