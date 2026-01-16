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

