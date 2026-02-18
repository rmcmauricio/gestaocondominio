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
     * Admin/super_admin are redirected to full dashboard.
     */
    public function index(): void
    {
        AuthMiddleware::require();

        $user = AuthMiddleware::user();
        $role = $user['role'] ?? 'condomino';
        $userId = AuthMiddleware::userId();
        $condominiumUserModel = new \App\Models\CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);

        // Redirecionar para dashboard só se for super_admin ou se não for condómino em nenhum condomínio
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

        $today = date('Y-m-d');

        // Overdue fees (quotas em atraso) com valor em falta (remaining) e total
        $feeModel = new \App\Models\Fee();
        $overdueFees = [];
        $totalOverdue = 0.0;
        $overdueCondominiumIds = [];
        foreach ($userCondominiums as $uc) {
            if (empty($uc['fraction_id'])) {
                continue;
            }
            $fees = $feeModel->getOutstandingByFraction($uc['fraction_id']);
            foreach ($fees as $f) {
                $dueDate = $f['due_date'] ?? null;
                if ($dueDate && $dueDate < $today) {
                    $remaining = (float)($f['remaining'] ?? $f['amount'] ?? 0);
                    $f['remaining'] = $remaining;
                    $f['period_display'] = \App\Models\Fee::formatPeriodForDisplay($f);
                    $f['condominium_name'] = $uc['condominium_name'] ?? '';
                    $f['condominium_id'] = (int)$uc['condominium_id'];
                    $f['fraction_identifier'] = $uc['fraction_identifier'] ?? '';
                    $overdueFees[] = $f;
                    $totalOverdue += $remaining;
                    $overdueCondominiumIds[$f['condominium_id']] = true;
                }
            }
        }
        $overdueCondominiumIds = array_keys($overdueCondominiumIds);

        // IBAN por condomínio (conta principal) para os condomínios com quotas em atraso
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
                $condo = $condominiumModel->findById($cid);
                if ($iban !== null && $iban !== '') {
                    $overdueCondominiumIbans[] = [
                        'condominium_id' => $cid,
                        'condominium_name' => $condo['name'] ?? '',
                        'iban' => $iban,
                    ];
                }
            }
        }

        // Fraction account balance (saldo em conta) per user fraction
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
                    'balance' => (float)$acc['balance'],
                ];
            }
        }

        // Unread notifications (last 3 for dashboard, same structure as notifications page)
        $notificationService = new \App\Services\NotificationService();
        $allNotifications = $notificationService->getUnifiedNotifications($userId, 50);
        $unreadNotifications = array_values(array_filter($allNotifications, function ($n) {
            return isset($n['is_read']) && !$n['is_read'];
        }));
        $unreadNotifications = array_slice($unreadNotifications, 0, 3);

        // Last 3 receipts
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
        $lastReceipts = array_slice($allReceipts, 0, 3);

        // Unread messages count and last 3 unread (same layout as notifications)
        // Query includes root + replies (getUnreadCount counts all; getByCondominium only roots)
        $messageModel = new \App\Models\Message();
        $unreadMessagesCount = 0;
        $unreadMessages = [];
        $condominiumIds = array_unique(array_map(function ($uc) {
            return (int)$uc['condominium_id'];
        }, $userCondominiums));
        foreach ($condominiumIds as $condoId) {
            $unreadMessagesCount += $messageModel->getUnreadCount($condoId, $userId);
        }
        if (!empty($condominiumIds) && $unreadMessagesCount > 0) {
            global $db;
            if ($db) {
                $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
                $stmt = $db->prepare("
                    SELECT m.id, m.subject, m.message, m.created_at, m.condominium_id, m.from_user_id, m.to_user_id, m.thread_id,
                           u.name as sender_name, c.name as condominium_name
                    FROM messages m
                    LEFT JOIN users u ON u.id = m.from_user_id
                    INNER JOIN condominiums c ON c.id = m.condominium_id
                    WHERE m.condominium_id IN ($placeholders)
                    AND (m.to_user_id = ? OR m.to_user_id IS NULL)
                    AND m.is_read = 0
                    AND m.from_user_id != ?
                    ORDER BY m.created_at DESC
                    LIMIT 3
                ");
                $params = array_merge($condominiumIds, [$userId, $userId]);
                $stmt->execute($params);
                $rows = $stmt->fetchAll() ?: [];
                foreach ($rows as $m) {
                    $unreadMessages[] = $m;
                }
            }
        }

        // Future reservations
        $reservationModel = new \App\Models\Reservation();
        $futureReservations = [];
        if (!empty($userCondominiums)) {
            global $db;
            $condominiumIds = array_unique(array_column($userCondominiums, 'condominium_id'));
            $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
            $stmt = $db->prepare("
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
            $params = array_merge([$userId], $condominiumIds, [$today]);
            $stmt->execute($params);
            $futureReservations = $stmt->fetchAll() ?: [];
        }

        // First condominium ID for links (e.g. reportar ocorrência) – prefer one where user has fraction
        $firstCondominiumId = null;
        foreach ($userCondominiums as $uc) {
            $cid = isset($uc['condominium_id']) ? (int) $uc['condominium_id'] : 0;
            if ($cid > 0) {
                $firstCondominiumId = $cid;
                if (!empty($uc['fraction_id'])) {
                    break;
                }
            }
        }

        $_SESSION['mobile_version'] = true;

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/m/dashboard.html.twig',
            'page' => ['titulo' => 'Início - Minisite'],
            'user' => $user,
            'user_condominiums' => $userCondominiums,
            'first_condominium_id' => $firstCondominiumId,
            'overdue_fees' => $overdueFees,
            'total_overdue' => $totalOverdue,
            'overdue_condominium_ibans' => $overdueCondominiumIbans,
            'fraction_balances' => $fractionBalances,
            'unread_notifications' => $unreadNotifications,
            'last_receipts' => $lastReceipts,
            'unread_messages_count' => $unreadMessagesCount,
            'unread_messages' => $unreadMessages,
            'future_reservations' => $futureReservations,
            'csrf_token' => \App\Core\Security::generateCSRFToken(),
        ];

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
