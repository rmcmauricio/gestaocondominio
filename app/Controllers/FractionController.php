<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Fraction;
use App\Models\Condominium;
use App\Models\Subscription;
use App\Models\CondominiumUser;
use App\Services\InvitationService;
use App\Core\EmailService;
use App\Middleware\DemoProtectionMiddleware;
use App\Models\UserEmailPreference;

class FractionController extends Controller
{
    protected $fractionModel;
    protected $condominiumModel;
    protected $subscriptionModel;
    protected $condominiumUserModel;
    protected $invitationService;

    public function __construct()
    {
        parent::__construct();
        $this->fractionModel = new Fraction();
        $this->condominiumModel = new Condominium();
        $this->subscriptionModel = new Subscription();
        $this->condominiumUserModel = new CondominiumUser();
        $this->invitationService = new InvitationService();
    }

    public function index(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $fractions = $this->fractionModel->getByCondominiumId($condominiumId);
        $totalPermillage = $this->fractionModel->getTotalPermillage($condominiumId);
        
        // Get owners and pending invitations for each fraction
        foreach ($fractions as &$fraction) {
            $owners = $this->fractionModel->getOwners($fraction['id']);
            $fraction['owners'] = $owners;
            $fraction['primary_owner'] = !empty($owners) ? $owners[0] : null;
            
            // Get pending invitations for this fraction
            global $db;
            $stmt = $db->prepare("
                SELECT id, email, name, role, created_at, expires_at
                FROM invitations
                WHERE fraction_id = :fraction_id
                AND condominium_id = :condominium_id
                AND accepted_at IS NULL
                AND expires_at > NOW()
                ORDER BY created_at DESC
            ");
            $stmt->execute([
                ':fraction_id' => $fraction['id'],
                ':condominium_id' => $condominiumId
            ]);
            $fraction['pending_invitations'] = $stmt->fetchAll() ?: [];
        }

        $this->loadPageTranslations('fractions');
        
        $userId = AuthMiddleware::userId();
        $user = AuthMiddleware::user();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        $this->data += [
            'viewName' => 'pages/fractions/index.html.twig',
            'page' => ['titulo' => 'Frações'],
            'condominium' => $condominium,
            'fractions' => $fractions,
            'total_permillage' => $totalPermillage,
            'current_user_id' => $userId,
            'is_admin' => $isAdmin,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'user' => $user
        ];

        // Clear session messages after displaying them
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function create(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $userId = AuthMiddleware::userId();
        if (!$this->subscriptionModel->canCreateFraction($userId, $condominiumId)) {
            $_SESSION['error'] = 'Limite de frações atingido. Faça upgrade do seu plano.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $this->loadPageTranslations('fractions');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/fractions/create.html.twig',
            'page' => ['titulo' => 'Criar Fração'],
            'condominium' => $condominium,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/create');
            exit;
        }

        $userId = AuthMiddleware::userId();
        if (!$this->subscriptionModel->canCreateFraction($userId, $condominiumId)) {
            $_SESSION['error'] = 'Limite de frações atingido.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        try {
            $this->fractionModel->create([
                'condominium_id' => $condominiumId,
                'identifier' => Security::sanitize($_POST['identifier'] ?? ''),
                'permillage' => (float)($_POST['permillage'] ?? 0),
                'floor' => Security::sanitize($_POST['floor'] ?? ''),
                'typology' => Security::sanitize($_POST['typology'] ?? ''),
                'area' => !empty($_POST['area']) ? (float)$_POST['area'] : null,
                'notes' => Security::sanitize($_POST['notes'] ?? '')
            ]);

            $_SESSION['success'] = 'Fração criada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar fração: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/create');
            exit;
        }
    }

    public function edit(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $fraction = $this->fractionModel->findById($id);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fração não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        // Get condominium for sidebar
        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('fractions');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/fractions/edit.html.twig',
            'page' => ['titulo' => 'Editar Fração'],
            'condominium' => $condominium,
            'fraction' => $fraction,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function update(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/' . $id . '/edit');
            exit;
        }

        try {
            $this->fractionModel->update($id, [
                'identifier' => Security::sanitize($_POST['identifier'] ?? ''),
                'permillage' => (float)($_POST['permillage'] ?? 0),
                'floor' => Security::sanitize($_POST['floor'] ?? ''),
                'typology' => Security::sanitize($_POST['typology'] ?? ''),
                'area' => !empty($_POST['area']) ? (float)$_POST['area'] : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'notes' => Security::sanitize($_POST['notes'] ?? '')
            ]);

            $_SESSION['success'] = 'Fração atualizada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar fração: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/' . $id . '/edit');
            exit;
        }
    }

    public function delete(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        // Check if fraction has fees or payments before deletion
        if ($this->fractionModel->hasFees($id)) {
            $_SESSION['error'] = 'Não é possível remover esta fração porque existem quotas associadas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        if ($this->fractionModel->hasPayments($id)) {
            $_SESSION['error'] = 'Não é possível remover esta fração porque existem pagamentos associados.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        if ($this->fractionModel->delete($id)) {
            $_SESSION['success'] = 'Fração removida com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao remover fração.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
        exit;
    }

    /**
     * Assign fraction to current admin user
     */
    public function assignToSelf(int $condominiumId, int $fractionId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $fraction = $this->fractionModel->findById($fractionId);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fração não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $userId = AuthMiddleware::userId();

        // Check if user is already associated with this fraction
        global $db;
        $stmt = $db->prepare("
            SELECT id FROM condominium_users 
            WHERE user_id = :user_id 
            AND fraction_id = :fraction_id 
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':fraction_id' => $fractionId
        ]);
        
        if ($stmt->fetch()) {
            $_SESSION['info'] = 'Já está associado a esta fração.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        try {
            // Check if user is already associated with condominium
            $stmt = $db->prepare("
                SELECT id FROM condominium_users 
                WHERE user_id = :user_id 
                AND condominium_id = :condominium_id 
                AND (ended_at IS NULL OR ended_at > CURDATE())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':condominium_id' => $condominiumId
            ]);
            $existingAssociation = $stmt->fetch();

            $associationCreated = false;
            if ($existingAssociation) {
                // Update existing association to include fraction
                $stmt = $db->prepare("
                    UPDATE condominium_users 
                    SET fraction_id = :fraction_id, 
                        is_primary = TRUE,
                        role = 'proprietario'
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':fraction_id' => $fractionId,
                    ':id' => $existingAssociation['id']
                ]);
                $associationCreated = true;
            } else {
                // Create new association
                $this->condominiumUserModel->associate([
                    'condominium_id' => $condominiumId,
                    'user_id' => $userId,
                    'fraction_id' => $fractionId,
                    'role' => 'proprietario',
                    'is_primary' => true,
                    'can_view_finances' => true,
                    'can_vote' => true
                ]);
                $associationCreated = true;
            }

            // Send email notification if association was created/updated
            if ($associationCreated) {
                try {
                    // Check if user is demo - demo users never receive emails
                    if (!DemoProtectionMiddleware::isDemoUser($userId)) {
                        // Check user preferences
                        $preferenceModel = new UserEmailPreference();
                        if ($preferenceModel->hasEmailEnabled($userId, 'notification')) {
                            // Get user and condominium info
                            $userStmt = $db->prepare("SELECT email, name FROM users WHERE id = :user_id LIMIT 1");
                            $userStmt->execute([':user_id' => $userId]);
                            $user = $userStmt->fetch();

                            $condominium = $this->condominiumModel->findById($condominiumId);
                            
                            if ($user && !empty($user['email']) && $condominium) {
                                $emailService = new EmailService();
                                $fractionLink = BASE_URL . 'condominiums/' . $condominiumId . '/fractions';
                                
                                $emailService->sendFractionAssignmentEmail(
                                    $user['email'],
                                    $user['name'] ?? 'Utilizador',
                                    $condominium['name'],
                                    $fraction['identifier'],
                                    $fractionLink,
                                    $userId
                                );
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Log error but don't fail fraction assignment
                    error_log("Failed to send fraction assignment email: " . $e->getMessage());
                }
            }

            $_SESSION['success'] = 'Fração atribuída com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atribuir fração: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
        exit;
    }

    /**
     * Remove a user from a fraction
     */
    public function removeOwner(int $condominiumId, int $fractionId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        if (!$userId) {
            $_SESSION['error'] = 'Utilizador não especificado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $fraction = $this->fractionModel->findById($fractionId);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fração não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        // Check how many owners this fraction has (allow removing even if only one, admin can reassign)
        $owners = $this->fractionModel->getOwners($fractionId);
        // Removed restriction - admin can remove any owner, even if it's the last one

        // Find the condominium_users entry
        global $db;
        $stmt = $db->prepare("
            SELECT id FROM condominium_users 
            WHERE user_id = :user_id 
            AND fraction_id = :fraction_id 
            AND condominium_id = :condominium_id
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':fraction_id' => $fractionId,
            ':condominium_id' => $condominiumId
        ]);
        $association = $stmt->fetch();

        if (!$association) {
            $_SESSION['error'] = 'Associação não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        try {
            // Remove the association by setting ended_at
            $condominiumUserModel = new \App\Models\CondominiumUser();
            $success = $condominiumUserModel->removeAssociation($association['id']);

            if ($success) {
                $_SESSION['success'] = 'Condómino removido da fração com sucesso!';
            } else {
                $_SESSION['error'] = 'Erro ao remover condómino da fração.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao remover condómino: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
        exit;
    }
}

