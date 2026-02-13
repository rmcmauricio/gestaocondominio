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
                foreach ($fees as &$f) {
                    $f['period_display'] = \App\Models\Fee::formatPeriodForDisplay($f);
                }
                unset($f);
                $pendingFees = array_merge($pendingFees, $fees);
                $totalPending += $feeModel->getTotalPendingByFraction($uc['fraction_id']);
            }
        }

        // Get recent expenses (last 5, from financial_transactions)
        $transactionModel = new \App\Models\FinancialTransaction();
        $recentExpenses = [];
        if (!empty($userCondominiums)) {
            $condominiumIds = array_unique(array_column($userCondominiums, 'condominium_id'));
            foreach ($condominiumIds as $condominiumId) {
                $txs = $transactionModel->getByCondominium($condominiumId, ['transaction_type' => 'expense', 'limit' => 5]);
                $recentExpenses = array_merge($recentExpenses, $txs);
            }
            usort($recentExpenses, function($a, $b) {
                return strtotime($b['transaction_date'] ?? '') - strtotime($a['transaction_date'] ?? '');
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

        $this->renderMainTemplate();
    }
}

