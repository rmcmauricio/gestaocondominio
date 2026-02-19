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

        $userId = AuthMiddleware::userId();

        // Get user's condominiums separated by role (superadmin can also be admin or condomino)
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $condominiumsByRole = $condominiumUserModel->getUserCondominiumsWithRoles($userId);

        $adminCondominiums = $condominiumsByRole['admin'] ?? [];
        $condominoCondominiums = $condominiumsByRole['condomino'] ?? [];

        // Get pending admin transfers
        $adminTransferPendingModel = new \App\Models\AdminTransferPending();
        $pendingTransfers = $adminTransferPendingModel->getPendingForUser($userId);

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

        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        $info = $_SESSION['info'] ?? null;
        unset($_SESSION['error'], $_SESSION['success'], $_SESSION['info']);

        // Super admin always has access to create condominiums (subscription limits do not apply)
        $this->data += [
            'viewName' => 'pages/dashboard/super-admin.html.twig',
            'page' => ['titulo' => 'Painel Super Admin'],
            'user' => AuthMiddleware::user(),
            'stats' => $stats,
            'admin_condominiums' => $adminCondominiums,
            'condomino_condominiums' => $condominoCondominiums,
            'pending_transfers' => $pendingTransfers,
            'can_create_condominium' => true,
            'error' => $error,
            'success' => $success,
            'info' => $info
        ];

        $this->renderMainTemplate();
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

        // Get pending admin transfers
        $adminTransferPendingModel = new \App\Models\AdminTransferPending();
        $pendingTransfers = $adminTransferPendingModel->getPendingForUser($userId);

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

        // Combine all condominiums for needed data
        $allCondominiums = array_merge($adminCondominiums, $condominoCondominiums);

        // Get license information
        $licenseInfo = [
            'total_fractions' => 0,
            'used_licenses' => 0,
            'license_limit' => null,
            'license_min' => null,
            'extra_licenses' => 0,
            'plan_name' => null,
            'plan_type' => null
        ];

        // Calculate total active fractions across all admin condominiums
        if (!empty($adminCondominiums)) {
            try {
                $fractionModel = new \App\Models\Fraction();
                foreach ($adminCondominiums as $condo) {
                    $licenseInfo['total_fractions'] += $fractionModel->getActiveCountByCondominium($condo['id']);
                }
            } catch (\Exception $e) {
                // Silently fail if fractions fail
            }
        }

        // Used licenses = total fractions (all fractions consume licenses)
        $licenseInfo['used_licenses'] = $licenseInfo['total_fractions'];

        $subscriptionModel = new \App\Models\Subscription();
        $subscription = $subscriptionModel->getActiveSubscription($userId);
        
        if ($subscription) {
            $planModel = new \App\Models\Plan();
            $plan = $planModel->findById($subscription['plan_id']);
            
            if ($plan && isset($plan['plan_type']) && $plan['plan_type']) {
                // Get license info for license-based plans
                $licenseInfo['license_min'] = (int)($plan['license_min'] ?? 0);
                $licenseInfo['extra_licenses'] = (int)($subscription['extra_licenses'] ?? 0);
                
                // Always calculate license limit: license_min + extra_licenses
                // This ensures we always have a total to display
                $licenseInfo['license_limit'] = $licenseInfo['license_min'] + $licenseInfo['extra_licenses'];
                
                // If license_limit is explicitly set in subscription and different, use it
                // (this handles edge cases where it might be manually set)
                if (isset($subscription['license_limit']) && $subscription['license_limit'] !== null) {
                    $subscriptionLimit = (int)$subscription['license_limit'];
                    // Use subscription limit if it's greater than calculated (might have been manually adjusted)
                    if ($subscriptionLimit > $licenseInfo['license_limit']) {
                        $licenseInfo['license_limit'] = $subscriptionLimit;
                    }
                }
                
                $licenseInfo['plan_name'] = $plan['name'] ?? null;
                $licenseInfo['plan_type'] = $plan['plan_type'] ?? null;
            }
        }

        // Get unread notifications count
        $unreadNotificationsCount = 0;
        try {
            $notificationService = new \App\Services\NotificationService();
            $allNotifications = $notificationService->getUnifiedNotifications($userId, 1000);
            $unreadNotifications = array_filter($allNotifications, function($n) {
                return isset($n['is_read']) && !$n['is_read'];
            });
            $unreadNotificationsCount = count($unreadNotifications);
        } catch (\Exception $e) {
            // Silently fail if notifications service fails
            $unreadNotificationsCount = 0;
        }

        // Get unread messages count (across all condominiums)
        $unreadMessagesCount = 0;
        if (!empty($adminCondominiums)) {
            try {
                $messageModel = new \App\Models\Message();
                foreach ($adminCondominiums as $condo) {
                    $unreadMessagesCount += $messageModel->getUnreadCount($condo['id'], $userId);
                }
            } catch (\Exception $e) {
                // Silently fail if messages fail
            }
        }

        // Get open standalone votes (questionnaires) for all admin condominiums
        $openVotes = [];
        if (!empty($adminCondominiums)) {
            try {
                $standaloneVoteModel = new \App\Models\StandaloneVote();
                foreach ($adminCondominiums as $condo) {
                    $votes = $standaloneVoteModel->getOpenByCondominium($condo['id']);
                    foreach ($votes as $vote) {
                        $vote['condominium_id'] = $condo['id'];
                        $vote['condominium_name'] = $condo['name'];
                        $openVotes[] = $vote;
                    }
                }
                // Sort by voting_started_at DESC
                usort($openVotes, function($a, $b) {
                    $dateA = $a['voting_started_at'] ?? $a['created_at'] ?? '';
                    $dateB = $b['voting_started_at'] ?? $b['created_at'] ?? '';
                    return strtotime($dateB) - strtotime($dateA);
                });
            } catch (\Exception $e) {
                // Silently fail if votes fail
            }
        }

        // Check condominium limit for subscription (reuse subscription already obtained)
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
            'pending_transfers' => $pendingTransfers,
            'license_info' => $licenseInfo,
            'unread_notifications_count' => $unreadNotificationsCount,
            'unread_messages_count' => $unreadMessagesCount,
            'open_votes' => $openVotes,
            'user' => AuthMiddleware::user(),
            'can_create_condominium' => (bool)$canCreateCondominium, // Ensure boolean type
            'condominium_limit' => $condominiumLimit,
            'current_condominium_count' => $currentCondominiumCount,
            'limit_reached' => $limitReached,
            'error' => $error,
            'success' => $success,
            'info' => $info
        ];

        $this->renderMainTemplate();
    }


    /**
     * Returns the data array for the condomino dashboard (for use in DashboardController or CondominiumController).
     */
    public function getCondominoDashboardData(): array
    {
        AuthMiddleware::require();
        $userId = AuthMiddleware::userId();

        // Get user's condominiums and fractions
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);

        $today = date('Y-m-d');
        $feeModel = new \App\Models\Fee();

        // Overdue and pending fees (same structure as mobile – all condominiums)
        $overdueFees = [];
        $totalOverdue = 0.0;
        $overdueCondominiumIds = [];
        $pendingFees = [];
        $totalPending = 0.0;

        foreach ($userCondominiums as $uc) {
            if (empty($uc['fraction_id'])) {
                continue;
            }
            $fees = $feeModel->getOutstandingByFraction($uc['fraction_id']);
            foreach ($fees as $f) {
                $dueDate = $f['due_date'] ?? null;
                $remaining = (float)($f['remaining'] ?? $f['amount'] ?? 0);
                $f['remaining'] = $remaining;
                $f['period_display'] = \App\Models\Fee::formatPeriodForDisplay($f);
                $f['condominium_name'] = $uc['condominium_name'] ?? '';
                $f['condominium_id'] = (int)$uc['condominium_id'];
                $f['fraction_identifier'] = $uc['fraction_identifier'] ?? '';
                if ($dueDate && $dueDate < $today) {
                    $overdueFees[] = $f;
                    $totalOverdue += $remaining;
                    $overdueCondominiumIds[(int)$uc['condominium_id']] = true;
                } else {
                    $pendingFees[] = $f;
                    $totalPending += $remaining;
                }
            }
        }
        $overdueCondominiumIds = array_keys($overdueCondominiumIds);

        // IBAN for condominiums with overdue (for full dashboard)
        $overdueCondominiumIbans = [];
        if (!empty($overdueCondominiumIds)) {
            $bankAccountModel = new \App\Models\BankAccount();
            $condominiumModel = new \App\Models\Condominium();
            foreach ($overdueCondominiumIds as $cid) {
                $accounts = $bankAccountModel->getActiveAccounts($cid);
                $iban = null;
                foreach ($accounts as $acc) {
                    if (($acc['account_type'] ?? '') === 'bank' && !empty($acc['iban'])) {
                        $iban = trim($acc['iban']);
                        break;
                    }
                }
                if ($iban !== null && $iban !== '') {
                    $condo = $condominiumModel->findById($cid);
                    $overdueCondominiumIbans[] = [
                        'condominium_id' => $cid,
                        'condominium_name' => $condo['name'] ?? '',
                        'iban' => $iban,
                    ];
                }
            }
        }

        // Get user's reservations (last 5)
        $reservationModel = new \App\Models\Reservation();
        $userReservations = [];
        $futureReservations = [];
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

                $stmtFuture = $db->prepare("
                    SELECT r.*, s.name as space_name, f.identifier as fraction_identifier, c.name as condominium_name
                    FROM reservations r
                    INNER JOIN spaces s ON s.id = r.space_id
                    INNER JOIN fractions f ON f.id = r.fraction_id
                    INNER JOIN condominiums c ON c.id = r.condominium_id
                    WHERE r.user_id = ?
                    AND r.condominium_id IN ($placeholders)
                    AND r.start_date >= ?
                    ORDER BY r.start_date ASC
                    LIMIT 10
                ");
                $paramsFuture = array_merge([$userId], $condominiumIds, [$today]);
                $stmtFuture->execute($paramsFuture);
                $futureReservations = $stmtFuture->fetchAll() ?: [];
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

        // Get unread notifications (max 3)
        $notificationService = new \App\Services\NotificationService();
        $allNotifications = $notificationService->getUnifiedNotifications($userId, 50);
        $unreadNotifications = array_filter($allNotifications, function($notif) {
            return isset($notif['is_read']) && !$notif['is_read'];
        });
        // Re-index array and limit to 3 most recent
        $unreadNotifications = array_values($unreadNotifications);
        $unreadNotifications = array_slice($unreadNotifications, 0, 3);

        // Saldo em conta (créditos) – por fração, apenas quando balance != 0
        $fractionAccountModel = new \App\Models\FractionAccount();
        $condominiumModel = new \App\Models\Condominium();
        $fractionBalances = [];
        foreach ($userCondominiums as $uc) {
            if (empty($uc['fraction_id'])) {
                continue;
            }
            $acc = $fractionAccountModel->getByFraction($uc['fraction_id']);
            if ($acc && (float)$acc['balance'] != 0) {
                $condo = $condominiumModel->findById($uc['condominium_id']);
                $fractionBalances[] = [
                    'condominium_name' => $condo['name'] ?? '',
                    'fraction_identifier' => $uc['fraction_identifier'] ?? '',
                    'condominium_id' => (int)$uc['condominium_id'],
                    'balance' => (float)$acc['balance'],
                ];
            }
        }

        // Últimos recibos (todos os condomínios do utilizador)
        $receiptModel = new \App\Models\Receipt();
        $allReceipts = $receiptModel->getByUser($userId, ['receipt_type' => 'final']);
        foreach ($allReceipts as &$r) {
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
        $lastReceipts = array_slice($allReceipts, 0, 5);

        // Mensagens não lidas (todos os condomínios)
        $messageModel = new \App\Models\Message();
        $unreadMessagesCount = 0;
        $unreadMessages = [];
        $condominiumIds = array_unique(array_column($userCondominiums, 'condominium_id'));
        $condominiumNamesById = [];
        foreach ($userCondominiums as $uc) {
            $cid = (int)$uc['condominium_id'];
            if (!isset($condominiumNamesById[$cid])) {
                $condominiumNamesById[$cid] = $uc['condominium_name'] ?? '';
            }
        }
        foreach ($condominiumIds as $condominiumId) {
            $unreadMessagesCount += $messageModel->getUnreadCount($condominiumId, $userId);
            $list = $messageModel->getByCondominium($condominiumId, [
                'recipient_id' => $userId,
                'is_read' => 0,
                'limit' => 5,
            ]);
            foreach ($list as $msg) {
                if ((int)($msg['from_user_id'] ?? 0) !== $userId) {
                    $msg['condominium_name'] = $condominiumNamesById[(int)($msg['condominium_id'] ?? 0)] ?? '';
                    $unreadMessages[] = $msg;
                }
            }
        }
        usort($unreadMessages, function ($a, $b) {
            return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
        });
        $unreadMessages = array_slice($unreadMessages, 0, 5);

        $firstCondominiumId = !empty($userCondominiums) ? (int)$userCondominiums[0]['condominium_id'] : null;

        return [
            'user_condominiums' => $userCondominiums,
            'first_condominium_id' => $firstCondominiumId,
            'overdue_fees' => $overdueFees,
            'total_overdue' => $totalOverdue,
            'overdue_condominium_ibans' => $overdueCondominiumIbans,
            'pending_fees' => $pendingFees,
            'total_pending' => $totalPending,
            'user_reservations' => $userReservations,
            'user_occurrences' => $userOccurrences,
            'future_reservations' => $futureReservations,
            'unread_notifications' => $unreadNotifications,
            'fraction_balances' => $fractionBalances,
            'last_receipts' => $lastReceipts,
            'unread_messages_count' => $unreadMessagesCount,
            'unread_messages' => $unreadMessages,
            'csrf_token' => \App\Core\Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];
    }

    protected function condominoDashboard()
    {
        $this->loadPageTranslations('dashboard');
        $this->data += $this->getCondominoDashboardData();
        $this->data['viewName'] = 'pages/dashboard/condomino.html.twig';
        $this->data['page'] = ['titulo' => 'Painel Condómino'];
        $this->renderMainTemplate();
    }
}

