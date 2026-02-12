<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Models\AdminTransferPending;
use App\Models\CondominiumUser;
use App\Services\NotificationService;

class AdminTransferController extends Controller
{
    protected $adminTransferPendingModel;
    protected $condominiumUserModel;
    protected $subscriptionService;
    protected $notificationService;

    public function __construct()
    {
        parent::__construct();
        $this->adminTransferPendingModel = new AdminTransferPending();
        $this->condominiumUserModel = new CondominiumUser();
        $this->subscriptionService = new \App\Services\SubscriptionService();
        $this->notificationService = new NotificationService();
    }

    /**
     * Show pending admin transfers
     */
    public function pending()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        $pendingTransfers = $this->adminTransferPendingModel->getPendingForUser($userId);

        $this->loadPageTranslations('condominiums');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/admin-transfers/pending.html.twig',
            'page' => ['titulo' => 'Convites para Administração'],
            'pending_transfers' => $pendingTransfers,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
        ];

        $this->renderMainTemplate();
    }

    /**
     * Accept admin transfer
     */
    public function accept()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin-transfers/pending');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin-transfers/pending');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $transferId = (int)($_POST['transfer_id'] ?? 0);

        if (!$transferId) {
            $_SESSION['error'] = 'ID de transferência inválido.';
            header('Location: ' . BASE_URL . 'admin-transfers/pending');
            exit;
        }

        // Get pending transfer details
        global $db;
        $stmt = $db->prepare("
            SELECT * FROM admin_transfer_pending 
            WHERE id = :id 
            AND user_id = :user_id 
            AND status = 'pending'
        ");
        $stmt->execute([
            ':id' => $transferId,
            ':user_id' => $userId
        ]);
        $pending = $stmt->fetch();

        if (!$pending) {
            $_SESSION['error'] = 'Transferência pendente não encontrada ou já processada.';
            header('Location: ' . BASE_URL . 'admin-transfers/pending');
            exit;
        }

        try {
            // Mark as accepted
            if (!$this->adminTransferPendingModel->accept($transferId, $userId)) {
                throw new \Exception('Erro ao aceitar transferência.');
            }

            // Now assign admin role
            if (!$this->condominiumUserModel->assignAdmin(
                $pending['condominium_id'],
                $userId,
                $pending['assigned_by_user_id']
            )) {
                throw new \Exception('Erro ao atribuir cargo de administrador.');
            }

            // If professional transfer, transfer licenses and remove old admin
            if ($pending['is_professional_transfer'] && $pending['from_subscription_id'] && $pending['to_subscription_id']) {
                try {
                    // Get old admin user_id from subscription
                    $subscriptionModel = new \App\Models\Subscription();
                    $fromSubscription = $subscriptionModel->findById($pending['from_subscription_id']);
                    $oldAdminUserId = $fromSubscription['user_id'] ?? null;

                    // Transfer licenses
                    $this->subscriptionService->transferCondominiumLicenses(
                        $pending['condominium_id'],
                        $pending['from_subscription_id'],
                        $pending['to_subscription_id'],
                        $userId,
                        true // isProfessionalTransfer
                    );

                    // Remove admin role from old admin (if exists and is not the new admin)
                    if ($oldAdminUserId && $oldAdminUserId != $userId) {
                        // Check if old admin is the owner - don't remove owner's admin role
                        $condominiumModel = new \App\Models\Condominium();
                        $condominium = $condominiumModel->findById($pending['condominium_id']);
                        
                        if ($condominium && $condominium['user_id'] != $oldAdminUserId) {
                            // Not the owner, safe to remove admin role
                            try {
                                $this->condominiumUserModel->removeAdmin(
                                    $pending['condominium_id'],
                                    $oldAdminUserId,
                                    $userId
                                );

                                // Notify old admin
                                $oldAdminUserModel = new \App\Models\User();
                                $oldAdminUser = $oldAdminUserModel->findById($oldAdminUserId);
                                if ($oldAdminUser) {
                                    $currentUser = AuthMiddleware::user();
                                    $condominiumName = $condominium['name'] ?? 'Condomínio';
                                    $this->notificationService->createNotification(
                                        $oldAdminUserId,
                                        $pending['condominium_id'],
                                        'admin_transfer_completed',
                                        'Transferência de Administração Concluída',
                                        "O cargo de administrador do condomínio \"{$condominiumName}\" foi transferido para {$currentUser['name']}. O condomínio agora é gerido pela empresa profissional.",
                                        BASE_URL . 'condominiums/' . $pending['condominium_id']
                                    );
                                }
                            } catch (\Exception $e) {
                                error_log("Error removing old admin role: " . $e->getMessage());
                                // Continue even if removal fails
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Error transferring licenses after acceptance: " . $e->getMessage());
                    // Continue even if license transfer fails - admin is already assigned
                }
            }

            // Create notification for the person who assigned
            $condominiumModel = new \App\Models\Condominium();
            $condominium = $condominiumModel->findById($pending['condominium_id']);
            $condominiumName = $condominium['name'] ?? 'Condomínio';
            
            $currentUser = AuthMiddleware::user();
            $this->notificationService->createNotification(
                $pending['assigned_by_user_id'],
                $pending['condominium_id'],
                'admin_transfer_accepted',
                'Transferência Aceite',
                "{$currentUser['name']} aceitou o convite para administrar o condomínio \"{$condominiumName}\".",
                BASE_URL . 'condominiums/' . $pending['condominium_id']
            );

            $_SESSION['success'] = 'Transferência aceite com sucesso! Agora tem acesso ao condomínio como administrador.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao aceitar transferência: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin-transfers/pending');
        exit;
    }

    /**
     * Reject admin transfer
     */
    public function reject()
    {
        AuthMiddleware::require();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'admin-transfers/pending');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'admin-transfers/pending');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $transferId = (int)($_POST['transfer_id'] ?? 0);

        if (!$transferId) {
            $_SESSION['error'] = 'ID de transferência inválido.';
            header('Location: ' . BASE_URL . 'admin-transfers/pending');
            exit;
        }

        // Get pending transfer details
        global $db;
        $stmt = $db->prepare("
            SELECT * FROM admin_transfer_pending 
            WHERE id = :id 
            AND user_id = :user_id 
            AND status = 'pending'
        ");
        $stmt->execute([
            ':id' => $transferId,
            ':user_id' => $userId
        ]);
        $pending = $stmt->fetch();

        if (!$pending) {
            $_SESSION['error'] = 'Transferência pendente não encontrada ou já processada.';
            header('Location: ' . BASE_URL . 'admin-transfers/pending');
            exit;
        }

        try {
            // Mark as rejected
            if (!$this->adminTransferPendingModel->reject($transferId, $userId)) {
                throw new \Exception('Erro ao rejeitar transferência.');
            }

            // Create notification for the person who assigned
            $condominiumModel = new \App\Models\Condominium();
            $condominium = $condominiumModel->findById($pending['condominium_id']);
            $condominiumName = $condominium['name'] ?? 'Condomínio';
            
            $currentUser = AuthMiddleware::user();
            $this->notificationService->createNotification(
                $pending['assigned_by_user_id'],
                $pending['condominium_id'],
                'admin_transfer_rejected',
                'Transferência Rejeitada',
                "{$currentUser['name']} rejeitou o convite para administrar o condomínio \"{$condominiumName}\".",
                BASE_URL . 'condominiums/' . $pending['condominium_id'] . '/assign-admin'
            );

            $_SESSION['success'] = 'Transferência rejeitada.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao rejeitar transferência: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'admin-transfers/pending');
        exit;
    }
}
