<?php

namespace App\Controllers\Mobile;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Models\CondominiumUser;

/**
 * Reservas mobile: redireciona para a página completa com from_mobile=1
 * para a mesma vista (pages/reservations/index.html.twig) ser renderizada com o template mobile.
 */
class ReservationController extends Controller
{
    public function index(): void
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $user = AuthMiddleware::user();
        if (($user['role'] ?? '') === 'super_admin') {
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        $condominiumUserModel = new CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        $condominiumIds = [];
        foreach ($userCondominiums as $uc) {
            $condominiumIds[$uc['condominium_id']] = true;
        }
        $condominiumIds = array_keys($condominiumIds);
        if (empty($condominiumIds)) {
            header('Location: ' . BASE_URL . 'm/dashboard');
            exit;
        }

        $condominiumId = isset($_GET['condominium_id']) && $_GET['condominium_id'] !== ''
            ? (int) $_GET['condominium_id']
            : (int) $condominiumIds[0];
        if (!in_array($condominiumId, $condominiumIds, true)) {
            $condominiumId = (int) $condominiumIds[0];
        }

        $params = array_merge($_GET, ['from_mobile' => '1']);
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/reservations?' . http_build_query($params));
        exit;
    }
}
