<?php

namespace App\Controllers\Mobile;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Services\MobileDetect;

class DashboardController extends Controller
{
    /**
     * Redirect /m to /m/dashboard
     */
    public function redirectToDashboard(): void
    {
        header('Location: ' . BASE_URL . 'm/dashboard');
        exit;
    }

    /**
     * Mobile minisite dashboard (condomino only).
     * Usa o mesmo Twig que a versão completa (pages/dashboard/condomino.html.twig) com is_mobile=true.
     * Admin/super_admin são redirecionados para o dashboard completo.
     */
    public function index(): void
    {
        AuthMiddleware::require();

        $user = AuthMiddleware::user();
        $role = $user['role'] ?? 'condomino';
        $userId = AuthMiddleware::userId();
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);

        if ($role === 'super_admin') {
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
        $hasCondominoRole = false;
        foreach ($userCondominiums as $uc) {
            if (!empty($uc['fraction_id'])) {
                $hasCondominoRole = true;
                break;
            }
        }
        if (!$hasCondominoRole) {
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        $_SESSION['mobile_version'] = true;

        $dashboardController = new \App\Controllers\DashboardController();
        $this->data = array_merge($this->data, $dashboardController->getCondominoDashboardData());
        $this->data['viewName'] = 'pages/dashboard/condomino.html.twig';
        $this->data['page'] = ['titulo' => 'Início - Minisite'];
        $this->data['is_mobile'] = true;

        $this->loadPageTranslations('dashboard');
        $this->renderMobileTemplate();
    }

    /**
     * Set cookie to prefer full site and redirect to dashboard.
     */
    public function versaoCompleta(): void
    {
        AuthMiddleware::require();
        unset($_SESSION['mobile_version']);
        MobileDetect::setPreferFullSite();
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }
}
