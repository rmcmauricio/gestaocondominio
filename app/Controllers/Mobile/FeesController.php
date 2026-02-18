<?php

namespace App\Controllers\Mobile;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Models\CondominiumUser;

/**
 * Quotas mobile: redirects to the full fees page with from_mobile=1
 * so the same view (pages/finances/fees.html.twig) is rendered with the mobile template.
 */
class FeesController extends Controller
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
            if (!empty($uc['fraction_id'])) {
                $condominiumIds[$uc['condominium_id']] = true;
            }
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

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees?from_mobile=1');
        exit;
    }
}
