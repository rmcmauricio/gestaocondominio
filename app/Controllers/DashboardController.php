<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\BankAccount;

class DashboardController extends Controller
{
    public function index()
    {
        AuthMiddleware::require();

        $user = AuthMiddleware::user();
        
        // Check if user is in demo mode and has selected a profile
        $demoProfile = $_SESSION['demo_profile'] ?? null;
        
        // If demo profile is set, use that role instead of user's actual role
        if ($demoProfile === 'condomino') {
            $role = 'condomino';
        } elseif ($demoProfile === 'admin') {
            $role = 'admin';
        } else {
            $role = $user['role'] ?? 'condomino';
        }

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

        global $db;
        
        // Get general statistics
        $stats = [];
        
        // Total users (excluding super_admin)
        $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role != 'super_admin'");
        $result = $stmt->fetch();
        $stats['total_users'] = (int)($result['count'] ?? 0);
        
        // Total active subscriptions
        $stmt = $db->query("SELECT COUNT(*) as count FROM subscriptions WHERE status = 'active'");
        $result = $stmt->fetch();
        $stats['active_subscriptions'] = (int)($result['count'] ?? 0);
        
        // Total condominiums
        $stmt = $db->query("SELECT COUNT(*) as count FROM condominiums");
        $result = $stmt->fetch();
        $stats['total_condominiums'] = (int)($result['count'] ?? 0);
        
        // Total payments (completed)
        $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'completed'");
        $result = $stmt->fetch();
        $stats['total_payments'] = (int)($result['count'] ?? 0);
        $stats['total_revenue'] = (float)($result['total'] ?? 0);

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/dashboard/super-admin.html.twig',
            'page' => ['titulo' => 'Painel Super Admin'],
            'user' => AuthMiddleware::user(),
            'stats' => $stats
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    protected function adminDashboard()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireAdmin();

        $userId = AuthMiddleware::userId();
        
        // Get user's condominiums separated by role
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $condominiumsByRole = $condominiumUserModel->getUserCondominiumsWithRoles($userId);
        
        $adminCondominiums = $condominiumsByRole['admin'] ?? [];
        $condominoCondominiums = $condominiumsByRole['condomino'] ?? [];
        
        // Add logo URLs to condominiums
        $fileStorageService = new \App\Services\FileStorageService();
        $condominiumModel = new \App\Models\Condominium();
        
        foreach ($adminCondominiums as &$condo) {
            $logoPath = $condominiumModel->getLogoPath($condo['id']);
            $condo['logo_url'] = $logoPath ? $fileStorageService->getFileUrl($logoPath) : null;
        }
        unset($condo);
        
        foreach ($condominoCondominiums as &$condo) {
            $logoPath = $condominiumModel->getLogoPath($condo['id']);
            $condo['logo_url'] = $logoPath ? $fileStorageService->getFileUrl($logoPath) : null;
        }
        unset($condo);
        
        // Combine all condominiums for statistics
        $allCondominiums = array_merge($adminCondominiums, $condominoCondominiums);
        
        global $db;
        
        // Initialize variables
        $bankAccounts = [];
        
        // Initialize stats
        $stats = [
            'total_condominiums' => count($allCondominiums),
            'total_fractions' => 0,
            'total_residents' => 0,
            'overdue_fees' => 0,
            'overdue_fees_amount' => 0,
            'open_occurrences' => 0,
            'total_revenue' => 0,
            'total_expenses' => 0,
            'current_account_balance' => 0,
            'total_bank_balance' => 0,
            'total_bank_accounts' => 0,
            'pending_fees_amount' => 0,
            'paid_fees_amount' => 0,
            'total_reservations' => 0,
            'total_spaces' => 0,
            'total_suppliers' => 0,
            'total_assemblies' => 0,
            'total_votes' => 0,
            'total_messages' => 0,
            'total_notifications' => 0,
            'total_documents' => 0,
            'total_budgets' => 0,
            'active_reservations' => 0,
            'pending_reservations' => 0
        ];

        if ($db && !empty($allCondominiums)) {
            $condominiumIds = array_column($allCondominiums, 'id');
            $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
            $currentYear = date('Y');
            
            // Count total fractions
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM fractions WHERE condominium_id IN ($placeholders) AND is_active = TRUE");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_fractions'] = (int)($result['count'] ?? 0);
            
            // Count total residents
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT user_id) as count 
                FROM condominium_users 
                WHERE condominium_id IN ($placeholders) 
                AND (ended_at IS NULL OR ended_at > CURDATE())
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_residents'] = (int)($result['count'] ?? 0);
            
            // Count overdue fees
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
            $stats['overdue_fees'] = (int)($result['count'] ?? 0);
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
            $stats['open_occurrences'] = (int)($result['count'] ?? 0);
            
            // Calculate total revenue for current year
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total
                FROM revenues
                WHERE condominium_id IN ($placeholders)
                AND YEAR(revenue_date) = ?
            ");
            $stmt->execute(array_merge($condominiumIds, [$currentYear]));
            $result = $stmt->fetch();
            $stats['total_revenue'] = (float)($result['total'] ?? 0);
            
            // Calculate total expenses for current year
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total
                FROM expenses
                WHERE condominium_id IN ($placeholders)
                AND YEAR(expense_date) = ?
            ");
            $stmt->execute(array_merge($condominiumIds, [$currentYear]));
            $result = $stmt->fetch();
            $stats['total_expenses'] = (float)($result['total'] ?? 0);
            
            // Get bank accounts and balances
            $bankAccountModel = new BankAccount();
            $bankAccounts = [];
            foreach ($condominiumIds as $condominiumId) {
                $accounts = $bankAccountModel->getActiveAccounts($condominiumId);
                foreach ($accounts as $account) {
                    // Update balance
                    $bankAccountModel->updateBalance($account['id']);
                    
                    // Get refreshed account data
                    $refreshedAccounts = $bankAccountModel->getActiveAccounts($condominiumId);
                    foreach ($refreshedAccounts as $refreshedAccount) {
                        if ($refreshedAccount['id'] == $account['id']) {
                            $refreshedAccount['condominium_id'] = $condominiumId;
                            $refreshedAccount['condominium_name'] = '';
                            foreach ($allCondominiums as $condo) {
                                if ($condo['id'] == $condominiumId) {
                                    $refreshedAccount['condominium_name'] = $condo['name'];
                                    break;
                                }
                            }
                            $bankAccounts[] = $refreshedAccount;
                            break;
                        }
                    }
                }
            }
            
            $stats['total_bank_accounts'] = count($bankAccounts);
            $stats['total_bank_balance'] = array_sum(array_column($bankAccounts, 'current_balance'));
            
            // Get main account balance (first account or 0)
            $stats['current_account_balance'] = !empty($bankAccounts) ? (float)$bankAccounts[0]['current_balance'] : 0;
            
            // Count pending fees amount
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
            
            // Count paid fees amount
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(fp.amount), 0) as total
                FROM fee_payments fp
                INNER JOIN fees f ON f.id = fp.fee_id
                WHERE f.condominium_id IN ($placeholders)
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['paid_fees_amount'] = (float)($result['total'] ?? 0);
            
            // Count total reservations
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_reservations'] = (int)($result['count'] ?? 0);
            
            // Count active reservations (current and future)
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM reservations 
                WHERE condominium_id IN ($placeholders)
                AND end_date >= CURDATE()
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['active_reservations'] = (int)($result['count'] ?? 0);
            
            // Count pending reservations
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM reservations 
                WHERE condominium_id IN ($placeholders)
                AND status = 'pending'
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['pending_reservations'] = (int)($result['count'] ?? 0);
            
            // Count total spaces
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM spaces WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_spaces'] = (int)($result['count'] ?? 0);
            
            // Count total suppliers
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM suppliers WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_suppliers'] = (int)($result['count'] ?? 0);
            
            // Count total assemblies
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM assemblies WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_assemblies'] = (int)($result['count'] ?? 0);
            
            // Count total standalone votes
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM standalone_votes WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_votes'] = (int)($result['count'] ?? 0);
            
            // Count total messages
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_messages'] = (int)($result['count'] ?? 0);
            
            // Count total notifications
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_notifications'] = (int)($result['count'] ?? 0);
            
            // Count total documents
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM documents WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_documents'] = (int)($result['count'] ?? 0);
            
            // Count total budgets
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM budgets WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $stats['total_budgets'] = (int)($result['count'] ?? 0);
        }
        
        // Get financial data for chart (last 6 months)
        $financial_data = [
            'monthly_revenue' => [],
            'monthly_expenses' => [],
            'overdue_fractions' => []
        ];
        
        if ($db && !empty($allCondominiums)) {
            $condominiumIds = array_column($allCondominiums, 'id');
            $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
            
            // Get monthly revenue for last 6 months
            $monthNames = [
                1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
                5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
            ];
            
            for ($i = 5; $i >= 0; $i--) {
                $date = date('Y-m', strtotime("-$i months"));
                $year = date('Y', strtotime("-$i months"));
                $month = date('n', strtotime("-$i months"));
                
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(amount), 0) as total
                    FROM revenues
                    WHERE condominium_id IN ($placeholders)
                    AND YEAR(revenue_date) = ?
                    AND MONTH(revenue_date) = ?
                ");
                $stmt->execute(array_merge($condominiumIds, [$year, $month]));
                $result = $stmt->fetch();
                $financial_data['monthly_revenue'][] = [
                    'month' => $monthNames[$month] . ' ' . $year,
                    'amount' => (float)($result['total'] ?? 0)
                ];
                
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(amount), 0) as total
                    FROM expenses
                    WHERE condominium_id IN ($placeholders)
                    AND YEAR(expense_date) = ?
                    AND MONTH(expense_date) = ?
                ");
                $stmt->execute(array_merge($condominiumIds, [$year, $month]));
                $result = $stmt->fetch();
                $financial_data['monthly_expenses'][] = [
                    'month' => $monthNames[$month] . ' ' . $year,
                    'amount' => (float)($result['total'] ?? 0)
                ];
            }
            
            // Get top 5 overdue fractions
            $stmt = $db->prepare("
                SELECT 
                    c.name as condominium_name,
                    f.identifier as fraction_identifier,
                    COALESCE(SUM(fee.amount - COALESCE((
                        SELECT SUM(fp.amount) 
                        FROM fee_payments fp 
                        WHERE fp.fee_id = fee.id
                    ), 0)), 0) as total_debt
                FROM fees fee
                INNER JOIN condominiums c ON c.id = fee.condominium_id
                INNER JOIN fractions f ON f.id = fee.fraction_id
                WHERE fee.condominium_id IN ($placeholders)
                AND fee.status = 'pending'
                AND fee.due_date < CURDATE()
                AND COALESCE(fee.is_historical, 0) = 0
                GROUP BY fee.condominium_id, fee.fraction_id, c.name, f.identifier
                HAVING total_debt > 0
                ORDER BY total_debt DESC
                LIMIT 5
            ");
            $stmt->execute($condominiumIds);
            $financial_data['overdue_fractions'] = $stmt->fetchAll() ?: [];
        }
        
        // Get document statistics
        $document_stats = [
            'total_documents' => 0,
            'documents_by_type' => []
        ];
        
        if ($db && !empty($allCondominiums)) {
            $condominiumIds = array_column($allCondominiums, 'id');
            $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
            
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM documents WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $document_stats['total_documents'] = (int)($result['count'] ?? 0);
            
            $stmt = $db->prepare("
                SELECT document_type, COUNT(*) as count
                FROM documents
                WHERE condominium_id IN ($placeholders)
                GROUP BY document_type
                ORDER BY count DESC
            ");
            $stmt->execute($condominiumIds);
            $document_stats['documents_by_type'] = $stmt->fetchAll() ?: [];
        }
        
        // Get recent documents (last 5)
        $recent_documents = [];
        if ($db && !empty($allCondominiums)) {
            $condominiumIds = array_column($allCondominiums, 'id');
            $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
            
            $stmt = $db->prepare("
                SELECT d.*, u.name as uploaded_by_name
                FROM documents d
                LEFT JOIN users u ON u.id = d.uploaded_by
                WHERE d.condominium_id IN ($placeholders)
                ORDER BY d.created_at DESC
                LIMIT 5
            ");
            $stmt->execute($condominiumIds);
            $recent_documents = $stmt->fetchAll() ?: [];
        }
        
        // Get occurrence statistics
        $occurrence_stats = [
            'total' => 0,
            'by_status' => [],
            'by_priority' => [],
            'average_resolution_time' => 0,
            'recent' => []
        ];
        
        if ($db && !empty($allCondominiums)) {
            $condominiumIds = array_column($allCondominiums, 'id');
            $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
            
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM occurrences WHERE condominium_id IN ($placeholders)");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $occurrence_stats['total'] = (int)($result['count'] ?? 0);
            
            $stmt = $db->prepare("
                SELECT status, COUNT(*) as count
                FROM occurrences
                WHERE condominium_id IN ($placeholders)
                GROUP BY status
                ORDER BY count DESC
            ");
            $stmt->execute($condominiumIds);
            $occurrence_stats['by_status'] = $stmt->fetchAll() ?: [];
            
            $stmt = $db->prepare("
                SELECT priority, COUNT(*) as count
                FROM occurrences
                WHERE condominium_id IN ($placeholders)
                GROUP BY priority
                ORDER BY count DESC
            ");
            $stmt->execute($condominiumIds);
            $occurrence_stats['by_priority'] = $stmt->fetchAll() ?: [];
            
            // Calculate average resolution time (for completed occurrences)
            $stmt = $db->prepare("
                SELECT AVG(DATEDIFF(completed_at, created_at)) as avg_days
                FROM occurrences
                WHERE condominium_id IN ($placeholders)
                AND status = 'completed'
                AND completed_at IS NOT NULL
            ");
            $stmt->execute($condominiumIds);
            $result = $stmt->fetch();
            $occurrence_stats['average_resolution_time'] = $result['avg_days'] ? round((float)$result['avg_days'], 1) : 0;
            
            // Get recent occurrences (last 5)
            $stmt = $db->prepare("
                SELECT o.*
                FROM occurrences o
                WHERE o.condominium_id IN ($placeholders)
                ORDER BY o.created_at DESC
                LIMIT 5
            ");
            $stmt->execute($condominiumIds);
            $occurrence_stats['recent'] = $stmt->fetchAll() ?: [];
        }
        
        // Get condominium users for admin view
        $condominium_users = [];
        if ($db && !empty($allCondominiums)) {
            $condominiumIds = array_column($allCondominiums, 'id');
            $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
            
            $stmt = $db->prepare("
                SELECT 
                    cu.id,
                    cu.fraction_id,
                    cu.role,
                    cu.is_primary,
                    u.id as user_id,
                    u.name,
                    u.email,
                    u.phone,
                    f.identifier as fraction_identifier,
                    c.name as condominium_name
                FROM condominium_users cu
                INNER JOIN users u ON u.id = cu.user_id
                LEFT JOIN fractions f ON f.id = cu.fraction_id
                INNER JOIN condominiums c ON c.id = cu.condominium_id
                WHERE cu.condominium_id IN ($placeholders)
                AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
                ORDER BY c.name ASC, cu.is_primary DESC, f.identifier ASC, u.name ASC
                LIMIT 50
            ");
            $stmt->execute($condominiumIds);
            $condominium_users = $stmt->fetchAll() ?: [];
        }

        // Check condominium limit for subscription
        $subscriptionModel = new \App\Models\Subscription();
        $subscription = $subscriptionModel->getActiveSubscription($userId);
        $canCreateCondominium = true; // Default to true
        $condominiumLimit = null;
        $currentCondominiumCount = 0;
        $limitReached = false;

        // Check if user is demo user
        $isDemoUser = \App\Middleware\DemoProtectionMiddleware::isDemoUser($userId);
        
        if ($isDemoUser) {
            // For demo users, limit is 2 condominiums
            // Count ALL condominiums where user is admin (not just subscription-associated ones)
            $condominiumLimit = 2;
            $currentCondominiumCount = count($adminCondominiums);
            $limitReached = $currentCondominiumCount >= $condominiumLimit;
            $canCreateCondominium = !$limitReached; // Explicitly set to false if limit reached
        } elseif ($subscription) {
            $planModel = new \App\Models\Plan();
            $plan = $planModel->findById($subscription['plan_id']);
            
            if ($plan) {
                // Check if plan is demo plan (slug = 'demo' or limit_condominios = 2 and is_active = false)
                $planSlug = $plan['slug'] ?? '';
                $planLimitCondominios = isset($plan['limit_condominios']) ? (int)$plan['limit_condominios'] : null;
                $planIsActive = isset($plan['is_active']) ? (bool)$plan['is_active'] : true;
                
                $isDemoPlan = ($planSlug === 'demo') || 
                              ($planLimitCondominios === 2 && $planIsActive === false);
                
                if ($isDemoPlan) {
                    // For demo plan, count ALL condominiums where user is admin (not just subscription-associated ones)
                    $condominiumLimit = 2;
                    $currentCondominiumCount = count($adminCondominiums);
                    $limitReached = $currentCondominiumCount >= $condominiumLimit;
                    $canCreateCondominium = !$limitReached;
                } else {
                    $condominiumLimit = isset($plan['limit_condominios']) && $plan['limit_condominios'] !== null ? (int)$plan['limit_condominios'] : null;
                    
                    if ($condominiumLimit !== null) {
                        // Count active condominiums associated with subscription
                        $count = 0;
                        if ($subscription['condominium_id']) {
                            $count++;
                        }
                        $subscriptionCondominiumModel = new \App\Models\SubscriptionCondominium();
                        $count += $subscriptionCondominiumModel->countActiveBySubscription($subscription['id']);
                        
                        $currentCondominiumCount = $count;
                        $limitReached = $count >= $condominiumLimit;
                        $canCreateCondominium = !$limitReached;
                    } else {
                        // Unlimited - count all user condominiums for display
                        $currentCondominiumCount = count($adminCondominiums);
                    }
                }
            } else {
                // No plan - count all user condominiums for display
                $currentCondominiumCount = count($adminCondominiums);
            }
        } else {
            // No subscription - count all user condominiums for display
            $currentCondominiumCount = count($adminCondominiums);
        }

        $this->loadPageTranslations('dashboard');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        $info = $_SESSION['info'] ?? null;
        unset($_SESSION['error'], $_SESSION['success'], $_SESSION['info']);
        
        $this->data += [
            'viewName' => 'pages/dashboard/admin.html.twig',
            'page' => ['titulo' => 'Dashboard'],
            'admin_condominiums' => $adminCondominiums,
            'condomino_condominiums' => $condominoCondominiums,
            'condominiums' => $allCondominiums, // Keep for backward compatibility
            'stats' => $stats,
            'financial_data' => $financial_data,
            'document_stats' => $document_stats,
            'occurrence_stats' => $occurrence_stats,
            'recent_documents' => $recent_documents,
            'bank_accounts' => $bankAccounts,
            'condominium_users' => $condominium_users,
            'user' => AuthMiddleware::user(),
            'can_create_condominium' => (bool)$canCreateCondominium, // Ensure boolean type
            'condominium_limit' => $condominiumLimit,
            'current_condominium_count' => $currentCondominiumCount,
            'limit_reached' => $limitReached,
            'error' => $error,
            'success' => $success,
            'info' => $info
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

        // Get user's reservations (last 5)
        $reservationModel = new \App\Models\Reservation();
        $userReservations = [];
        if (!empty($userCondominiums)) {
            $condominiumIds = array_unique(array_column($userCondominiums, 'condominium_id'));
            global $db;
            if ($db && !empty($condominiumIds)) {
                $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
                $stmt = $db->prepare("
                    SELECT r.*, 
                           s.name as space_name, 
                           f.identifier as fraction_identifier,
                           c.name as condominium_name
                    FROM reservations r
                    INNER JOIN spaces s ON s.id = r.space_id
                    INNER JOIN fractions f ON f.id = r.fraction_id
                    INNER JOIN condominiums c ON c.id = r.condominium_id
                    WHERE r.user_id = ? 
                    AND r.condominium_id IN ($placeholders)
                    ORDER BY r.start_date DESC
                    LIMIT 5
                ");
                $params = array_merge([$userId], $condominiumIds);
                $stmt->execute($params);
                $userReservations = $stmt->fetchAll() ?: [];
            }
        }

        // Get user's occurrences (last 5)
        $occurrenceModel = new \App\Models\Occurrence();
        $userOccurrences = [];
        if (!empty($userCondominiums)) {
            $condominiumIds = array_unique(array_column($userCondominiums, 'condominium_id'));
            global $db;
            if ($db && !empty($condominiumIds)) {
                $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
                $stmt = $db->prepare("
                    SELECT o.*, 
                           f.identifier as fraction_identifier,
                           c.name as condominium_name
                    FROM occurrences o
                    LEFT JOIN fractions f ON f.id = o.fraction_id
                    INNER JOIN condominiums c ON c.id = o.condominium_id
                    WHERE o.reported_by = ? 
                    AND o.condominium_id IN ($placeholders)
                    ORDER BY o.created_at DESC
                    LIMIT 5
                ");
                $params = array_merge([$userId], $condominiumIds);
                $stmt->execute($params);
                $userOccurrences = $stmt->fetchAll() ?: [];
            }
        }

        // Create a map of condominium_id to condominium_name
        $condominiumNames = [];
        foreach ($userCondominiums as $uc) {
            if (!isset($condominiumNames[$uc['condominium_id']])) {
                $condominiumNames[$uc['condominium_id']] = $uc['condominium_name'];
            }
        }

        // Get open votes for user's condominiums
        $standaloneVoteModel = new \App\Models\StandaloneVote();
        $openVotes = [];
        $recentVoteResults = [];
        if (!empty($userCondominiums)) {
            $condominiumIds = array_unique(array_column($userCondominiums, 'condominium_id'));
            foreach ($condominiumIds as $condominiumId) {
                $votes = $standaloneVoteModel->getOpenByCondominium($condominiumId);
                foreach ($votes as $vote) {
                    $vote['condominium_id'] = $condominiumId;
                    $vote['condominium_name'] = $condominiumNames[$condominiumId] ?? '';
                    $openVotes[] = $vote;
                }
                
                // Get recent results (last 3 per condominium)
                $results = $standaloneVoteModel->getRecentResults($condominiumId, 3);
                foreach ($results as $result) {
                    $result['condominium_id'] = $condominiumId;
                    $result['condominium_name'] = $condominiumNames[$condominiumId] ?? '';
                    $result['results'] = $standaloneVoteModel->getResults($result['id']);
                    $recentVoteResults[] = $result;
                }
            }
            
            // Sort by voting_started_at DESC
            usort($openVotes, function($a, $b) {
                return strtotime($b['voting_started_at'] ?? '') - strtotime($a['voting_started_at'] ?? '');
            });
            
            // Sort recent results by voting_ended_at DESC
            usort($recentVoteResults, function($a, $b) {
                return strtotime($b['voting_ended_at'] ?? '') - strtotime($a['voting_ended_at'] ?? '');
            });
            
            // Limit to last 3 overall
            $recentVoteResults = array_slice($recentVoteResults, 0, 3);
        }

        // Get vote options for each vote (filtered by allowed options)
        $voteOptionModel = new \App\Models\VoteOption();
        $voteOptionsByVote = [];
        if (!empty($openVotes)) {
            foreach ($openVotes as $vote) {
                $allowedOptionIds = $vote['allowed_options'] ?? [];
                $allOptions = $voteOptionModel->getByCondominium($vote['condominium_id']);
                
                // Filter to only allowed options
                $options = [];
                if (!empty($allowedOptionIds)) {
                    foreach ($allOptions as $option) {
                        if (in_array($option['id'], $allowedOptionIds)) {
                            $options[] = $option;
                        }
                    }
                } else {
                    // Backward compatibility: if no allowed options specified, use all
                    $options = $allOptions;
                }
                
                $voteOptionsByVote[$vote['id']] = $options;
            }
        }

        // Get user's votes for open votes
        $standaloneVoteResponseModel = new \App\Models\StandaloneVoteResponse();
        $userVotes = [];
        if (!empty($openVotes) && !empty($userCondominiums)) {
            foreach ($userCondominiums as $uc) {
                if ($uc['fraction_id']) {
                    foreach ($openVotes as $vote) {
                        if ($vote['condominium_id'] == $uc['condominium_id']) {
                            $userVote = $standaloneVoteResponseModel->getByFraction($vote['id'], $uc['fraction_id']);
                            if ($userVote) {
                                $userVotes[$vote['id']] = $userVote;
                            }
                        }
                    }
                }
            }
        }

        // Get unread notifications (max 3)
        $notificationService = new \App\Services\NotificationService();
        $allNotifications = $notificationService->getUnifiedNotifications($userId, 50);
        $unreadNotifications = array_filter($allNotifications, function($notif) {
            return isset($notif['is_read']) && !$notif['is_read'];
        });
        // Re-index array and limit to 3 most recent
        $unreadNotifications = array_values($unreadNotifications);
        $unreadNotifications = array_slice($unreadNotifications, 0, 3);

        $this->loadPageTranslations('dashboard');
        
        $this->data += [
            'viewName' => 'pages/dashboard/condomino.html.twig',
            'page' => ['titulo' => 'Painel Condómino'],
            'user_condominiums' => $userCondominiums,
            'pending_fees' => array_slice($pendingFees, 0, 5),
            'total_pending' => $totalPending,
            'recent_expenses' => $recentExpenses,
            'recent_documents' => $recentDocuments,
            'user_reservations' => $userReservations,
            'user_occurrences' => $userOccurrences,
            'open_votes' => $openVotes,
            'recent_vote_results' => $recentVoteResults,
            'vote_options_by_vote' => $voteOptionsByVote,
            'user_votes' => $userVotes,
            'unread_notifications' => $unreadNotifications,
            'csrf_token' => \App\Core\Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

