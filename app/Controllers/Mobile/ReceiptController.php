<?php

namespace App\Controllers\Mobile;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Models\Receipt;
use App\Models\Condominium;
use App\Models\CondominiumUser;

class ReceiptController extends Controller
{
    /**
     * My receipts (condomino) - mobile view.
     */
    public function index(): void
    {
        AuthMiddleware::require();

        $user = AuthMiddleware::user();
        $role = $user['role'] ?? 'condomino';
        $userId = AuthMiddleware::userId();
        if ($role === 'super_admin') {
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
        $condominiumUserModel = new CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
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
        $condominiumId = isset($_GET['condominium_id']) && $_GET['condominium_id'] !== '' ? (int)$_GET['condominium_id'] : null;
        $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;

        $filters = ['receipt_type' => 'final'];
        if ($condominiumId) {
            $filters['condominium_id'] = $condominiumId;
        }
        if ($year) {
            $filters['year'] = $year;
        }

        $receiptModel = new Receipt();
        $receipts = $receiptModel->getByUser($userId, $filters);
        foreach ($receipts as &$r) {
            $r['period_display'] = \App\Models\Fee::formatPeriodForDisplay([
                'period_year' => $r['period_year'] ?? null,
                'period_month' => $r['period_month'] ?? null,
                'period_index' => $r['period_index'] ?? null,
                'period_type' => $r['period_type'] ?? 'monthly',
                'fee_type' => $r['fee_type'] ?? 'regular',
                'reference' => $r['fee_reference'] ?? $r['reference'] ?? '',
            ]);
        }
        unset($r);

        $condominiumUserModel = new CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        $condominiumModel = new Condominium();
        $condominiums = [];
        foreach ($userCondominiums as $uc) {
            if (!isset($condominiums[$uc['condominium_id']])) {
                $condo = $condominiumModel->findById($uc['condominium_id']);
                if ($condo) {
                    $condominiums[$uc['condominium_id']] = $condo;
                }
            }
        }

        $years = $receiptModel->getAvailableYearsByUser($userId);
        if (empty($years)) {
            $years = [date('Y')];
        }

        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/receipts/index.html.twig',
            'page' => ['titulo' => 'Os meus recibos'],
            'user' => $user,
            'receipts' => $receipts,
            'condominiums' => $condominiums,
            'selected_condominium_id' => $condominiumId,
            'selected_year' => $year ?? date('Y'),
            'available_years' => $years,
            'is_admin' => false,
            'receipts_base_url' => BASE_URL . 'm/receipts',
            'error' => $error,
            'success' => $success,
        ];

        $_SESSION['mobile_version'] = true;
        $this->renderMobileTemplate();
    }
}
