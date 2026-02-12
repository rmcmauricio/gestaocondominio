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
use App\Services\FractionImportService;
use App\Services\LicenseService;
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
                SELECT id, email, name, role, nif, phone, alternative_address, created_at, expires_at, token
                FROM invitations
                WHERE fraction_id = :fraction_id
                AND condominium_id = :condominium_id
                AND accepted_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())
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

        $this->renderMainTemplate();
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

        $this->renderMainTemplate();
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

        $this->renderMainTemplate();
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

    /**
     * Update contact information for a fraction owner
     */
    public function updateOwnerContact(int $condominiumId, int $fractionId)
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

        $associationId = (int)($_POST['association_id'] ?? 0);
        if (!$associationId) {
            $_SESSION['error'] = 'Associação não especificada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $fraction = $this->fractionModel->findById($fractionId);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fração não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        // Verify association belongs to this fraction
        global $db;
        $stmt = $db->prepare("
            SELECT id FROM condominium_users 
            WHERE id = :id 
            AND fraction_id = :fraction_id 
            AND condominium_id = :condominium_id
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $stmt->execute([
            ':id' => $associationId,
            ':fraction_id' => $fractionId,
            ':condominium_id' => $condominiumId
        ]);
        
        if (!$stmt->fetch()) {
            $_SESSION['error'] = 'Associação não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        try {
            $success = $this->condominiumUserModel->updateContactInfo($associationId, [
                'nif' => $_POST['nif'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'alternative_address' => $_POST['alternative_address'] ?? ''
            ]);

            if ($success) {
                $_SESSION['success'] = 'Dados de contato atualizados com sucesso!';
            } else {
                $_SESSION['error'] = 'Erro ao atualizar dados de contato.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar dados: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
        exit;
    }

    /**
     * Show fraction import page (upload + mapping)
     */
    public function import(int $condominiumId)
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
        // Frações que existem neste condomínio (para mostrar ao utilizador)
        $fractionsInCondominium = $this->fractionModel->getActiveCountByCondominium($condominiumId);

        $subscription = $this->subscriptionModel->getActiveSubscription($userId);
        $usedLicenses = 0;
        $licenseLimit = null;
        $allowOverage = false;
        $availableSlots = null; // null = unlimited or N/A

        if ($subscription) {
            // Recalcular licenças utilizadas para refletir o estado real da subscrição
            $licenseService = new LicenseService();
            $licenseService->recalculateAndUpdate($subscription['id']);
            $usedLicenses = (int)($this->subscriptionModel->calculateUsedLicenses($subscription['id']));
            $planModel = new \App\Models\Plan();
            $plan = $planModel->findById($subscription['plan_id']);
            $licenseMin = $plan ? (int)($plan['license_min'] ?? 0) : 0;
            // Limite efetivo: subscrição, depois plano (license_limit ou license_min)
            $licenseLimit = isset($subscription['license_limit']) && $subscription['license_limit'] !== null
                ? (int)$subscription['license_limit']
                : ($plan ? (int)($plan['license_limit'] ?? $licenseMin) : null);
            $allowOverage = !empty($subscription['allow_overage']);
            if ($licenseLimit !== null && !$allowOverage) {
                $availableSlots = max(0, $licenseLimit - $usedLicenses);
            }
        }

        $this->loadPageTranslations('fractions');

        $this->data += [
            'viewName' => 'pages/fractions/import.html.twig',
            'page' => ['titulo' => 'Importar Frações'],
            'condominium' => $condominium,
            'csrf_token' => Security::generateCSRFToken(),
            'fractions_in_condominium' => $fractionsInCondominium,
            'used_licenses' => $usedLicenses,
            'license_limit' => $licenseLimit,
            'allow_overage' => $allowOverage,
            'available_slots' => $availableSlots,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
        ];

        unset($_SESSION['error'], $_SESSION['success']);
        $this->renderMainTemplate();
    }

    /**
     * Upload file and return headers + suggested mapping (AJAX JSON)
     */
    public function uploadImport(int $condominiumId)
    {
        header('Content-Type: application/json; charset=utf-8');
        $oldDisplay = ini_get('display_errors');
        ini_set('display_errors', '0');

        try {
            AuthMiddleware::require();
            RoleMiddleware::requireCondominiumAccess($condominiumId);
            RoleMiddleware::requireAdminInCondominium($condominiumId);

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método não permitido']);
                exit;
            }

            $csrfToken = $_POST['csrf_token'] ?? '';
            if (!Security::verifyCSRFToken($csrfToken)) {
                echo json_encode(['success' => false, 'error' => 'Token de segurança inválido']);
                exit;
            }

            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Erro no upload do ficheiro']);
                exit;
            }

            $originalName = $_FILES['file']['name'] ?? '';
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
                echo json_encode(['success' => false, 'error' => 'Formato não suportado. Use .xlsx, .xls ou .csv']);
                exit;
            }

            $tmpFile = $_FILES['file']['tmp_name'];
            $hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';

            $importService = new FractionImportService();
            $fileData = $importService->readFile($tmpFile, $hasHeader, $originalName);
            $suggestedMapping = $importService->suggestMapping($fileData['headers']);

            echo json_encode([
                'success' => true,
                'headers' => $fileData['headers'],
                'suggestedMapping' => $suggestedMapping,
                'rowCount' => $fileData['rowCount'],
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        ini_set('display_errors', $oldDisplay);
    }

    /**
     * Preview import: parse file, validate rows, check license limit, show preview
     */
    public function previewImport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = 'Método não permitido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/import');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/import');
            exit;
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = 'Erro no upload do ficheiro.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/import');
            exit;
        }

        $originalName = $_FILES['file']['name'] ?? '';
        $hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';
        $columnMappingJson = $_POST['column_mapping'] ?? '{}';
        $columnMapping = json_decode($columnMappingJson, true);
        if (!is_array($columnMapping)) {
            $columnMapping = [];
        }

        if (!in_array('identifier', $columnMapping)) {
            $_SESSION['error'] = 'Deve mapear uma coluna para o campo "Identificador".';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/import');
            exit;
        }

        try {
            global $db;
            $importService = new FractionImportService($db);
            $tmpFile = $_FILES['file']['tmp_name'];
            $fileData = $importService->readFile($tmpFile, $hasHeader, $originalName);
            $parsedData = $importService->parseRows($fileData['rows'], $columnMapping);

            $existingInFile = [];
            $validRows = [];
            foreach ($parsedData as $row) {
                $validation = $importService->validateRow($row, $condominiumId, $existingInFile);
                $row['_valid'] = $validation['valid'];
                $row['_errors'] = $validation['errors'];
                if ($validation['valid']) {
                    $existingInFile[] = trim((string)$row['identifier']);
                }
                $validRows[] = $row;
            }
            $parsedData = $validRows;

            $validCount = count(array_filter($parsedData, function ($r) {
                return !empty($r['_valid']);
            }));

            $userId = AuthMiddleware::userId();
            $subscription = $this->subscriptionModel->getActiveSubscription($userId);
            $licenseService = new LicenseService();
            $maxAllowed = $validCount;
            $limitWarning = null;

            if ($subscription && $validCount > 0) {
                $validation = $licenseService->validateLicenseAvailability($subscription['id'], $validCount);
                if (!$validation['available'] && empty($subscription['allow_overage'])) {
                    $current = (int)($validation['current'] ?? $subscription['used_licenses'] ?? 0);
                    $limit = (int)($validation['limit'] ?? $subscription['license_limit'] ?? 0);
                    $maxAllowed = max(0, $limit - $current);
                    $parsedData = array_slice($parsedData, 0, $maxAllowed);
                    // Só mostrar aviso quando realmente se cortaram linhas (limite aplicado)
                    if ($maxAllowed > 0 && $validCount > $maxAllowed) {
                        $limitWarning = 'Apenas as primeiras ' . $maxAllowed . ' frações serão importadas devido ao limite da subscrição.';
                    }
                }
            }

            $_SESSION['fraction_import_data'] = [
                'condominium_id' => $condominiumId,
                'parsed_data' => $parsedData,
                'column_mapping' => $columnMapping,
                'max_allowed' => $maxAllowed,
                'limit_warning' => $limitWarning,
                'subscription_id' => $subscription ? ($subscription['id'] ?? null) : null,
            ];

            $condominium = $this->condominiumModel->findById($condominiumId);
            $this->loadPageTranslations('fractions');

            $this->data += [
                'viewName' => 'pages/fractions/import-preview.html.twig',
                'page' => ['titulo' => 'Preview da Importação de Frações'],
                'condominium' => $condominium,
                'parsedData' => $parsedData,
                'max_allowed' => $maxAllowed,
                'limit_warning' => $limitWarning,
                'csrf_token' => Security::generateCSRFToken(),
            ];

            $this->renderMainTemplate();
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao processar ficheiro: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/import');
            exit;
        }
    }

    /**
     * Process import: create fractions from session data, then recalculate licenses
     */
    public function processImport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = 'Método não permitido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $importData = $_SESSION['fraction_import_data'] ?? null;
        if (!$importData || (int)($importData['condominium_id'] ?? 0) !== (int)$condominiumId) {
            $_SESSION['error'] = 'Dados de importação não encontrados ou expirados.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions/import');
            exit;
        }

        $parsedData = $importData['parsed_data'] ?? [];
        $subscriptionId = $importData['subscription_id'] ?? null;

        // Aplicar edições e linhas excluídas do preview
        $editedData = json_decode($_POST['edited_data'] ?? '{}', true);
        $deletedRows = json_decode($_POST['deleted_rows'] ?? '[]', true);
        if (!is_array($editedData)) {
            $editedData = [];
        }
        if (!is_array($deletedRows)) {
            $deletedRows = [];
        }
        $deletedRows = array_map('intval', $deletedRows);

        $indexMapping = [];
        $newIndex = 0;
        foreach ($parsedData as $oldIndex => $row) {
            if (!in_array($oldIndex, $deletedRows, true)) {
                $indexMapping[$oldIndex] = $newIndex;
                $newIndex++;
            }
        }
        foreach ($deletedRows as $idx) {
            unset($parsedData[$idx]);
        }
        $parsedData = array_values($parsedData);

        foreach ($editedData as $oldIndex => $editedRow) {
            if (isset($indexMapping[$oldIndex]) && isset($parsedData[$indexMapping[$oldIndex]])) {
                $rowRef = &$parsedData[$indexMapping[$oldIndex]];
                foreach ($editedRow as $key => $value) {
                    if ($key !== '_valid' && $key !== '_errors') {
                        if ($key === 'permillage' || $key === 'area') {
                            $rowRef[$key] = is_numeric($value) ? (float)$value : ($rowRef[$key] ?? 0);
                        } else {
                            $rowRef[$key] = $value === '' ? null : $value;
                        }
                    }
                }
                // Se o utilizador editou a linha no preview, considerar válida para processar (correção de erros)
                if (!empty($editedRow)) {
                    $rowRef['_errors'] = [];
                    $rowRef['_valid'] = true;
                }
            }
        }
        unset($rowRef);

        $created = 0;
        $skippedDuplicates = 0;
        $errors = [];

        foreach ($parsedData as $row) {
            if (!empty($row['_errors'])) {
                $errors[] = 'Fração ' . ($row['identifier'] ?? '?') . ': ' . implode(' ', $row['_errors']);
                continue;
            }
            $identifier = Security::sanitize($row['identifier'] ?? '');
            $data = [
                'permillage' => (float)($row['permillage'] ?? 0),
                'floor' => !empty($row['floor']) ? Security::sanitize($row['floor']) : null,
                'typology' => !empty($row['typology']) ? Security::sanitize($row['typology']) : null,
                'area' => isset($row['area']) && $row['area'] !== '' && $row['area'] !== null ? (float)$row['area'] : null,
                'notes' => !empty($row['notes']) ? Security::sanitize($row['notes']) : null,
            ];
            try {
                // Se existir uma fração inativa (soft-deleted) com o mesmo identificador, reativá-la em vez de inserir
                $inactive = $this->fractionModel->findInactiveByCondominiumAndIdentifier($condominiumId, $identifier);
                if ($inactive) {
                    $data['is_active'] = true;
                    $this->fractionModel->update((int)$inactive['id'], $data);
                    $fractionId = (int)$inactive['id'];
                    $created++;
                } else {
                    $fractionId = $this->fractionModel->create([
                        'condominium_id' => $condominiumId,
                        'identifier' => $identifier,
                        'permillage' => $data['permillage'],
                        'floor' => $data['floor'],
                        'typology' => $data['typology'],
                        'area' => $data['area'],
                        'notes' => $data['notes'],
                    ]);
                    $created++;
                }

                // Criar ou atualizar convite quando há dados do condómino
                $ownerName = trim((string)($row['owner_name'] ?? ''));
                $ownerEmail = trim((string)($row['owner_email'] ?? ''));
                if ($ownerName !== '' || $ownerEmail !== '') {
                    $contactData = [
                        'nif' => isset($row['owner_nif']) && $row['owner_nif'] !== '' ? $row['owner_nif'] : null,
                        'phone' => isset($row['owner_phone']) && $row['owner_phone'] !== '' ? $row['owner_phone'] : null,
                        'alternative_address' => isset($row['owner_alternative_address']) && $row['owner_alternative_address'] !== '' ? $row['owner_alternative_address'] : null,
                    ];
                    $role = in_array($row['owner_role'] ?? '', ['condomino', 'proprietario', 'arrendatario'], true) ? $row['owner_role'] : 'condomino';
                    $pending = $this->invitationService->findPendingByFraction($condominiumId, $fractionId);
                    if ($pending) {
                        $this->invitationService->updateInvitation($pending['id'], $condominiumId, [
                            'name' => $ownerName !== '' ? $ownerName : ($pending['name'] ?? ''),
                            'email' => $ownerEmail !== '' ? $ownerEmail : ($pending['email'] ?? null),
                            'role' => $role,
                            'nif' => $contactData['nif'],
                            'phone' => $contactData['phone'],
                            'alternative_address' => $contactData['alternative_address'],
                        ]);
                    } else {
                        $nameForInvitation = $ownerName !== '' ? $ownerName : 'Condómino';
                        $this->invitationService->createInvitation($condominiumId, $fractionId, $ownerEmail !== '' ? $ownerEmail : null, $nameForInvitation, $role, $contactData);
                    }
                }
            } catch (\PDOException $e) {
                $isDuplicate = ($e->getCode() === '23000' || strpos($e->getMessage(), '1062') !== false);
                if ($isDuplicate) {
                    $skippedDuplicates++;
                } else {
                    $errors[] = 'Fração ' . ($row['identifier'] ?? '?') . ': ' . $e->getMessage();
                }
            } catch (\Exception $e) {
                $errors[] = 'Fração ' . ($row['identifier'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        if ($subscriptionId) {
            $licenseService = new LicenseService();
            $licenseService->recalculateAndUpdate($subscriptionId);
        }

        unset($_SESSION['fraction_import_data']);

        if ($created > 0) {
            $_SESSION['success'] = $created . ' fração(ões) importada(s) com sucesso.';
            if ($skippedDuplicates > 0) {
                $_SESSION['success'] .= ' ' . $skippedDuplicates . ' fração(ões) já existiam e foram ignoradas.';
            }
            if (!empty($errors)) {
                $_SESSION['success'] .= ' Avisos: ' . implode('; ', array_slice($errors, 0, 5));
            }
        } else {
            $msg = [];
            if ($skippedDuplicates > 0) {
                $msg[] = $skippedDuplicates . ' fração(ões) já existiam e foram ignoradas.';
            }
            if (!empty($errors)) {
                $msg[] = implode(' ', array_slice($errors, 0, 5));
            }
            $_SESSION['error'] = empty($msg) ? 'Nenhuma fração importada.' : implode(' ', $msg);
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
        exit;
    }
}

