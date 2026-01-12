<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

class DashboardController extends Controller
{
    public function index()
    {
        AuthMiddleware::require();

        $user = AuthMiddleware::user();
        $role = $user['role'] ?? 'condomino';

        $this->loadPageTranslations('dashboard');
        
        if ($role === 'super_admin') {
            $this->admin();
            return;
        }

        if ($role === 'admin') {
            $this->adminDashboard();
            return;
        }

        $this->condominoDashboard();
    }

    public function admin()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/dashboard/super-admin.html.twig',
            'page' => ['titulo' => 'Painel Super Admin'],
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    protected function adminDashboard()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireAdmin();

        $userId = AuthMiddleware::userId();
        
        // Get user's condominiums
        $condominiumModel = new \App\Models\Condominium();
        $condominiums = $condominiumModel->getByUserId($userId);
        
        // Get simple statistics
        $stats = [
            'total_condominiums' => count($condominiums),
            'total_fractions' => 0
        ];

        global $db;
        if ($db && !empty($condominiums)) {
            $condominiumIds = array_column($condominiums, 'id');
            $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
            
            // Count total fractions across all condominiums
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM fractions WHERE condominium_id IN ($placeholders) AND is_active = TRUE");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_fractions'] = $result['count'] ?? 0;
        }

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/index.html.twig',
            'page' => ['titulo' => 'Dashboard'],
            'condominiums' => $condominiums,
            'stats' => $stats,
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }


    protected function condominoDashboard()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        
        // Get user's condominiums and fractions
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        
        // Get pending fees
        $feeModel = new \App\Models\Fee();
        $pendingFees = [];
        $totalPending = 0;
        
        foreach ($userCondominiums as $uc) {
            if ($uc['fraction_id']) {
                $fees = $feeModel->getPendingByFraction($uc['fraction_id']);
                $pendingFees = array_merge($pendingFees, $fees);
                $totalPending += $feeModel->getTotalPendingByFraction($uc['fraction_id']);
            }
        }

        // Get recent expenses (last 5)
        $expenseModel = new \App\Models\Expense();
        $recentExpenses = [];
        if (!empty($userCondominiums)) {
            $condominiumIds = array_unique(array_column($userCondominiums, 'condominium_id'));
            foreach ($condominiumIds as $condominiumId) {
                $expenses = $expenseModel->getByCondominium($condominiumId, ['limit' => 5]);
                $recentExpenses = array_merge($recentExpenses, $expenses);
            }
            usort($recentExpenses, function($a, $b) {
                return strtotime($b['expense_date']) - strtotime($a['expense_date']);
            });
            $recentExpenses = array_slice($recentExpenses, 0, 5);
        }

        // Get recent documents (last 5)
        $documentModel = new \App\Models\Document();
        $recentDocuments = [];
        if (!empty($userCondominiums)) {
            $condominiumIds = array_unique(array_column($userCondominiums, 'condominium_id'));
            foreach ($condominiumIds as $condominiumId) {
                // Get documents visible to condominos
                $documents = $documentModel->getByCondominium($condominiumId, [
                    'visibility' => 'condominos',
                    'limit' => 5,
                    'sort_by' => 'created_at',
                    'sort_order' => 'DESC'
                ]);
                $recentDocuments = array_merge($recentDocuments, $documents);
            }
            usort($recentDocuments, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            $recentDocuments = array_slice($recentDocuments, 0, 5);
        }

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/dashboard/condomino.html.twig',
            'page' => ['titulo' => 'Painel Condómino'],
            'user_condominiums' => $userCondominiums,
            'pending_fees' => array_slice($pendingFees, 0, 5),
            'total_pending' => $totalPending,
            'recent_expenses' => $recentExpenses,
            'recent_documents' => $recentDocuments,
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

