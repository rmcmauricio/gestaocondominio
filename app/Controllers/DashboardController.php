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
        
        // Get statistics
        $stats = [
            'total_condominiums' => count($condominiums),
            'total_fractions' => 0,
            'overdue_fees' => 0,
            'open_occurrences' => 0
        ];

        global $db;
        if ($db && !empty($condominiums)) {
            $condominiumIds = array_column($condominiums, 'id');
            $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
            
            // Count fractions
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM fractions WHERE condominium_id IN ($placeholders) AND is_active = TRUE");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_fractions'] = $result['count'] ?? 0;
            
            // Count overdue fees
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM fees 
                WHERE condominium_id IN ($placeholders) 
                AND status = 'pending' 
                AND due_date < CURDATE()
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['overdue_fees'] = $result['count'] ?? 0;
            
            // Count open occurrences
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM occurrences 
                WHERE condominium_id IN ($placeholders) 
                AND status IN ('open', 'in_analysis', 'assigned')
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['open_occurrences'] = $result['count'] ?? 0;
        }

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/dashboard/admin.html.twig',
            'page' => ['titulo' => 'Painel Administrador'],
            'stats' => $stats,
            'condominiums' => $condominiums,
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

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/dashboard/condomino.html.twig',
            'page' => ['titulo' => 'Painel Condómino'],
            'user_condominiums' => $userCondominiums,
            'pending_fees' => array_slice($pendingFees, 0, 5),
            'total_pending' => $totalPending,
            'recent_expenses' => $recentExpenses,
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

