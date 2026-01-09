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
            'overdue_fees_amount' => 0,
            'open_occurrences' => 0,
            'total_revenue' => 0,
            'total_expenses' => 0,
            'pending_fees_amount' => 0,
            'paid_fees_amount' => 0
        ];

        $financialData = [
            'monthly_revenue' => [],
            'monthly_expenses' => [],
            'overdue_fractions' => []
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
            
            // Count overdue fees and calculate amount
            $stmt = $db->prepare("
                SELECT COUNT(*) as count,
                       COALESCE(SUM(f.amount - COALESCE((
                           SELECT SUM(fp.amount) 
                           FROM fee_payments fp 
                           WHERE fp.fee_id = f.id
                       ), 0)), 0) as total_amount
                FROM fees f
                WHERE f.condominium_id IN ($placeholders) 
                AND f.status = 'pending' 
                AND f.due_date < CURDATE()
                AND COALESCE(f.is_historical, 0) = 0
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['overdue_fees'] = $result['count'] ?? 0;
            $stats['overdue_fees_amount'] = (float)($result['total_amount'] ?? 0);
            
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
            
            // Get financial statistics for current year
            $currentYear = date('Y');
            $currentMonth = date('m');
            
            // Total revenue from fees (paid)
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(fp.amount), 0) as total
                FROM fee_payments fp
                INNER JOIN fees f ON f.id = fp.fee_id
                WHERE f.condominium_id IN ($placeholders)
                AND YEAR(fp.payment_date) = ?
            ");
            $stmt->execute(array_merge($condominiumIds, [$currentYear]));
            $result = $stmt->fetch();
            $stats['total_revenue'] = (float)($result['total'] ?? 0);
            
            // Total expenses for current year
            $expenseModel = new \App\Models\Expense();
            $totalExpenses = 0;
            foreach ($condominiumIds as $condominiumId) {
                $totalExpenses += $expenseModel->getTotalByPeriod(
                    $condominiumId,
                    "{$currentYear}-01-01",
                    "{$currentYear}-12-31"
                );
            }
            $stats['total_expenses'] = $totalExpenses;
            
            // Pending fees amount
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(f.amount - COALESCE((
                    SELECT SUM(fp.amount) 
                    FROM fee_payments fp 
                    WHERE fp.fee_id = f.id
                ), 0)), 0) as total
                FROM fees f
                WHERE f.condominium_id IN ($placeholders)
                AND f.status = 'pending'
                AND COALESCE(f.is_historical, 0) = 0
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['pending_fees_amount'] = (float)($result['total'] ?? 0);
            
            // Paid fees amount
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(fp.amount), 0) as total
                FROM fee_payments fp
                INNER JOIN fees f ON f.id = fp.fee_id
                WHERE f.condominium_id IN ($placeholders)
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['paid_fees_amount'] = (float)($result['total'] ?? 0);
            
            // Monthly revenue and expenses for last 6 months
            for ($i = 5; $i >= 0; $i--) {
                $month = date('m', strtotime("-{$i} months"));
                $year = date('Y', strtotime("-{$i} months"));
                $monthStart = "{$year}-{$month}-01";
                $monthEnd = date('Y-m-t', strtotime($monthStart));
                
                // Revenue for month
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(fp.amount), 0) as total
                    FROM fee_payments fp
                    INNER JOIN fees f ON f.id = fp.fee_id
                    WHERE f.condominium_id IN ($placeholders)
                    AND DATE(fp.payment_date) BETWEEN ? AND ?
                ");
                $stmt->execute(array_merge($condominiumIds, [$monthStart, $monthEnd]));
                $result = $stmt->fetch();
                $financialData['monthly_revenue'][] = [
                    'month' => date('M Y', strtotime($monthStart)),
                    'amount' => (float)($result['total'] ?? 0)
                ];
                
                // Expenses for month
                $monthExpenses = 0;
                foreach ($condominiumIds as $condominiumId) {
                    $monthExpenses += $expenseModel->getTotalByPeriod($condominiumId, $monthStart, $monthEnd);
                }
                $financialData['monthly_expenses'][] = [
                    'month' => date('M Y', strtotime($monthStart)),
                    'amount' => $monthExpenses
                ];
            }
            
            // Get top overdue fractions
            $stmt = $db->prepare("
                SELECT fr.identifier as fraction_identifier, c.name as condominium_name,
                       SUM(f.amount - COALESCE((
                           SELECT SUM(fp.amount) 
                           FROM fee_payments fp 
                           WHERE fp.fee_id = f.id
                       ), 0)) as total_debt
                FROM fees f
                INNER JOIN fractions fr ON fr.id = f.fraction_id
                INNER JOIN condominiums c ON c.id = f.condominium_id
                WHERE f.condominium_id IN ($placeholders)
                AND f.status = 'pending'
                AND f.due_date < CURDATE()
                AND COALESCE(f.is_historical, 0) = 0
                GROUP BY fr.id, fr.identifier, c.name
                ORDER BY total_debt DESC
                LIMIT 5
            ");
            $stmt->execute($condominiumIds);
            $financialData['overdue_fractions'] = $stmt->fetchAll() ?: [];
            
            // Get recent documents (last 10)
            $documentModel = new \App\Models\Document();
            $recentDocuments = [];
            foreach ($condominiumIds as $condominiumId) {
                $documents = $documentModel->getByCondominium($condominiumId, [
                    'limit' => 10,
                    'sort_by' => 'created_at',
                    'sort_order' => 'DESC'
                ]);
                $recentDocuments = array_merge($recentDocuments, $documents);
            }
            usort($recentDocuments, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            $recentDocuments = array_slice($recentDocuments, 0, 10);
            
            // Document statistics
            $documentStats = [
                'total_documents' => 0,
                'documents_by_type' => [],
                'recent_uploads' => count($recentDocuments)
            ];
            
            foreach ($condominiumIds as $condominiumId) {
                $allDocs = $documentModel->getByCondominium($condominiumId);
                $documentStats['total_documents'] += count($allDocs);
            }
            
            // Get document types from all condominiums
            $allDocumentTypes = [];
            foreach ($condominiumIds as $condominiumId) {
                $types = $documentModel->getDocumentTypes($condominiumId);
                foreach ($types as $type) {
                    $key = $type['document_type'];
                    if (!isset($allDocumentTypes[$key])) {
                        $allDocumentTypes[$key] = ['document_type' => $key, 'count' => 0];
                    }
                    $allDocumentTypes[$key]['count'] += $type['count'];
                }
            }
            $documentStats['documents_by_type'] = array_values($allDocumentTypes);
        } else {
            $recentDocuments = [];
            $documentStats = [
                'total_documents' => 0,
                'documents_by_type' => [],
                'recent_uploads' => 0
            ];
        }

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/dashboard/admin.html.twig',
            'page' => ['titulo' => 'Painel Administrador'],
            'stats' => $stats,
            'financial_data' => $financialData,
            'condominiums' => $condominiums,
            'recent_documents' => $recentDocuments,
            'document_stats' => $documentStats,
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

