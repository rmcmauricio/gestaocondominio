<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\Condominium;

class ReservationController extends Controller
{
    protected $reservationModel;
    protected $spaceModel;
    protected $condominiumModel;

    public function __construct()
    {
        parent::__construct();
        $this->reservationModel = new Reservation();
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
        $spaceId = $_GET['space_id'] ?? null;
        
        $filters = [];
        if ($spaceId) {
            $filters['space_id'] = $spaceId;
        }

        // Get date range
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');
        
        $filters['start_date'] = $startDate;
        $filters['end_date'] = $endDate;

        $reservations = $this->reservationModel->getByCondominium($condominiumId, $filters);

        $this->loadPageTranslations('reservations');
        
        $this->data += [
            'viewName' => 'pages/reservations/index.html.twig',
            'page' => ['titulo' => 'Reservas'],
            'condominium' => $condominium,
            'spaces' => $spaces,
            'reservations' => $reservations,
            'selected_space' => $spaceId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'csrf_token' => Security::generateCSRFToken()
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

        $spaces = $this->spaceModel->getByCondominium($condominiumId);
        
        // Get user's fractions
        $userId = AuthMiddleware::userId();
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        $userFractions = array_filter($userCondominiums, function($uc) use ($condominiumId) {
            return $uc['condominium_id'] == $condominiumId && $uc['fraction_id'];
        });

        $this->loadPageTranslations('reservations');
        
        $this->data += [
            'viewName' => 'pages/reservations/create.html.twig',
            'page' => ['titulo' => 'Nova Reserva'],
            'condominium' => $condominium,
            'spaces' => $spaces,
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
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations/create');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $spaceId = (int)$_POST['space_id'];
        $fractionId = (int)$_POST['fraction_id'];
        $startDate = $_POST['start_date'] . ' ' . ($_POST['start_time'] ?? '00:00');
        $endDate = $_POST['end_date'] . ' ' . ($_POST['end_time'] ?? '23:59');

        // Check availability
        if (!$this->reservationModel->isSpaceAvailable($spaceId, $startDate, $endDate)) {
            $_SESSION['error'] = 'Espaço não disponível no período selecionado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations/create');
            exit;
        }

        // Get space to calculate price
        $space = $this->spaceModel->findById($spaceId);
        $price = 0;
        $deposit = $space['deposit_required'] ?? 0;

        // Calculate price based on duration
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $hours = $end->diff($start)->h + ($end->diff($start)->days * 24);
        
        if ($hours < 24 && $space['price_per_hour'] > 0) {
            $price = $hours * $space['price_per_hour'];
        } elseif ($space['price_per_day'] > 0) {
            $days = max(1, ceil($hours / 24));
            $price = $days * $space['price_per_day'];
        }

        // Auto-approve if space doesn't require approval
        $status = ($space['requires_approval'] ?? true) ? 'pending' : 'approved';

        try {
            $reservationId = $this->reservationModel->create([
                'condominium_id' => $condominiumId,
                'space_id' => $spaceId,
                'fraction_id' => $fractionId,
                'user_id' => $userId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
                'price' => $price,
                'deposit' => $deposit,
                'notes' => Security::sanitize($_POST['notes'] ?? '')
            ]);

            if ($status === 'approved') {
                $this->reservationModel->updateStatus($reservationId, 'approved', $userId);
            }

            $_SESSION['success'] = $status === 'approved' 
                ? 'Reserva aprovada automaticamente!' 
                : 'Reserva criada com sucesso! Aguarde aprovação.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar reserva: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations/create');
            exit;
        }
    }

    public function approve(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations');
            exit;
        }

        $userId = AuthMiddleware::userId();

        if ($this->reservationModel->updateStatus($id, 'approved', $userId)) {
            $_SESSION['success'] = 'Reserva aprovada com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao aprovar reserva.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations');
        exit;
    }

    public function reject(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations');
            exit;
        }

        if ($this->reservationModel->updateStatus($id, 'rejected')) {
            $_SESSION['success'] = 'Reserva rejeitada.';
        } else {
            $_SESSION['error'] = 'Erro ao rejeitar reserva.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations');
        exit;
    }
}

