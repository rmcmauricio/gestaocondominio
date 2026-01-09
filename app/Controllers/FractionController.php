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
        
        // Get owners for each fraction
        foreach ($fractions as &$fraction) {
            $owners = $this->fractionModel->getOwners($fraction['id']);
            $fraction['owners'] = $owners;
            $fraction['primary_owner'] = !empty($owners) ? $owners[0] : null;
        }

        $this->loadPageTranslations('fractions');
        
        $userId = AuthMiddleware::userId();
        
        $this->data += [
            'viewName' => 'pages/fractions/index.html.twig',
            'page' => ['titulo' => 'Frações'],
            'condominium' => $condominium,
            'fractions' => $fractions,
            'total_permillage' => $totalPermillage,
            'current_user_id' => $userId,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function create(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

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
        
        $this->data += [
            'viewName' => 'pages/fractions/create.html.twig',
            'page' => ['titulo' => 'Criar Fração'],
            'condominium' => $condominium,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

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

        $fraction = $this->fractionModel->findById($id);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fração não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
            exit;
        }

        $this->loadPageTranslations('fractions');
        
        $this->data += [
            'viewName' => 'pages/fractions/edit.html.twig',
            'page' => ['titulo' => 'Editar Fração'],
            'fraction' => $fraction,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function update(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

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
            }

            $_SESSION['success'] = 'Fração atribuída com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atribuir fração: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fractions');
        exit;
    }
}

