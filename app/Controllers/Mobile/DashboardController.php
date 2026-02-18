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

        // Determinar condomínio selecionado: sessão > primeiro com fração
        $selectedCondominiumId = (int)($_SESSION['current_condominium_id'] ?? 0);
        $userCondominiumsWithFraction = array_values(array_filter($userCondominiums, function ($uc) {
            return !empty($uc['fraction_id']);
        }));
        $validCondominiumIds = array_unique(array_column($userCondominiumsWithFraction, 'condominium_id'));
        if ($selectedCondominiumId <= 0 || !in_array($selectedCondominiumId, $validCondominiumIds)) {
            $selectedCondominiumId = !empty($userCondominiumsWithFraction)
                ? (int)$userCondominiumsWithFraction[0]['condominium_id']
                : 0;
            if ($selectedCondominiumId > 0) {
                $_SESSION['current_condominium_id'] = $selectedCondominiumId;
            }
        }
        $filteredUserCondominiums = array_filter($userCondominiumsWithFraction, function ($uc) use ($selectedCondominiumId) {
            return (int)$uc['condominium_id'] === $selectedCondominiumId;
        });

        $today = date('Y-m-d');

        // Overdue fees (quotas em atraso) – apenas do condomínio selecionado
        $feeModel = new \App\Models\Fee();
        $overdueFees = [];
        $totalOverdue = 0.0;
        $overdueCondominiumIds = [];
        foreach ($filteredUserCondominiums as $uc) {
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

        // Pending fees (quotas pendentes) – apenas do condomínio selecionado
        $pendingFees = [];
        $totalPending = 0.0;
        foreach ($filteredUserCondominiums as $uc) {
            $fees = $feeModel->getOutstandingByFraction($uc['fraction_id']);
            foreach ($fees as $f) {
                $dueDate = $f['due_date'] ?? null;
                if ($dueDate && $dueDate >= $today) {
                    $remaining = (float)($f['remaining'] ?? $f['amount'] ?? 0);
                    $f['remaining'] = $remaining;
                    $f['period_display'] = \App\Models\Fee::formatPeriodForDisplay($f);
                    $f['condominium_name'] = $uc['condominium_name'] ?? '';
                    $f['condominium_id'] = (int)$uc['condominium_id'];
                    $f['fraction_identifier'] = $uc['fraction_identifier'] ?? '';
                    $pendingFees[] = $f;
                    $totalPending += $remaining;
                }
            }
        }

        // IBAN – apenas do condomínio selecionado (se tiver quotas em atraso)
        $overdueCondominiumIbans = [];
        if (!empty($overdueCondominiumIds) && in_array($selectedCondominiumId, $overdueCondominiumIds)) {
            $bankAccountModel = new \App\Models\BankAccount();
            $condominiumModel = new \App\Models\Condominium();
            $accounts = $bankAccountModel->getActiveAccounts($selectedCondominiumId);
            $iban = null;
            foreach ($accounts as $acc) {
                if (($acc['account_type'] ?? '') === 'bank' && !empty($acc['iban'])) {
                    $iban = trim($acc['iban']);
                    break;
                }
            }
            $condo = $condominiumModel->findById($selectedCondominiumId);
            if ($iban !== null && $iban !== '') {
                $overdueCondominiumIbans[] = [
                    'condominium_id' => $selectedCondominiumId,
                    'condominium_name' => $condo['name'] ?? '',
                    'iban' => $iban,
                ];
            }
        }

        // Saldo em conta – apenas do condomínio selecionado
        $fractionAccountModel = new \App\Models\FractionAccount();
        $condominiumModel = new \App\Models\Condominium();
        $fractionBalances = [];
        foreach ($filteredUserCondominiums as $uc) {
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

        // Notificações não lidas – apenas do condomínio selecionado
        $notificationService = new \App\Services\NotificationService();
        $allNotifications = $notificationService->getUnifiedNotifications($userId, 50);
        $unreadNotifications = array_values(array_filter($allNotifications, function ($n) use ($selectedCondominiumId) {
            if (!isset($n['is_read']) || $n['is_read']) {
                return false;
            }
            $nCid = $n['condominium_id'] ?? null;
            return $nCid === null || (int)$nCid === $selectedCondominiumId;
        }));
        $unreadNotifications = array_slice($unreadNotifications, 0, 3);

        // Últimos recibos – apenas do condomínio selecionado
        $receiptModel = new \App\Models\Receipt();
        $receiptFilters = ['receipt_type' => 'final'];
        if ($selectedCondominiumId > 0) {
            $receiptFilters['condominium_id'] = $selectedCondominiumId;
        }
        $allReceipts = $receiptModel->getByUser($userId, $receiptFilters);
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

        // Mensagens não lidas – apenas do condomínio selecionado
        $messageModel = new \App\Models\Message();
        $unreadMessagesCount = 0;
        $unreadMessages = [];
        if ($selectedCondominiumId > 0) {
            $unreadMessagesCount = $messageModel->getUnreadCount($selectedCondominiumId, $userId);
            if ($unreadMessagesCount > 0) {
                global $db;
                if ($db) {
                    $stmt = $db->prepare("
                        SELECT m.id, m.subject, m.message, m.created_at, m.condominium_id, m.from_user_id, m.to_user_id, m.thread_id,
                               u.name as sender_name, c.name as condominium_name
                        FROM messages m
                        LEFT JOIN users u ON u.id = m.from_user_id
                        INNER JOIN condominiums c ON c.id = m.condominium_id
                        WHERE m.condominium_id = ?
                        AND (m.to_user_id = ? OR m.to_user_id IS NULL)
                        AND m.is_read = 0
                        AND m.from_user_id != ?
                        ORDER BY m.created_at DESC
                        LIMIT 3
                    ");
                    $stmt->execute([$selectedCondominiumId, $userId, $userId]);
                    $unreadMessages = $stmt->fetchAll() ?: [];
                }
            }
        }

        // Marcações futuras – apenas do condomínio selecionado
        $futureReservations = [];
        if ($selectedCondominiumId > 0) {
            global $db;
            if ($db) {
                $stmt = $db->prepare("
                    SELECT r.*, s.name as space_name, f.identifier as fraction_identifier, c.name as condominium_name
                    FROM reservations r
                    INNER JOIN spaces s ON s.id = r.space_id
                    INNER JOIN fractions f ON f.id = r.fraction_id
                    INNER JOIN condominiums c ON c.id = r.condominium_id
                    WHERE r.user_id = ?
                    AND r.condominium_id = ?
                    AND r.start_date >= ?
                    ORDER BY r.start_date ASC
                    LIMIT 10
                ");
                $stmt->execute([$userId, $selectedCondominiumId, $today]);
                $futureReservations = $stmt->fetchAll() ?: [];
            }
        }

        $firstCondominiumId = $selectedCondominiumId > 0 ? $selectedCondominiumId : null;

        $_SESSION['mobile_version'] = true;

        $this->loadPageTranslations('dashboard');

        $this->data += [
            'viewName' => 'pages/m/dashboard.html.twig',
            'page' => ['titulo' => 'Início - Minisite'],
            'user' => $user,
            'first_condominium_id' => $firstCondominiumId,
            'overdue_fees' => $overdueFees,
            'total_overdue' => $totalOverdue,
            'overdue_condominium_ibans' => $overdueCondominiumIbans,
            'pending_fees' => $pendingFees,
            'total_pending' => $totalPending,
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
