<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Condominium;
use App\Models\Subscription;
use App\Services\SubscriptionService;

class CondominiumController extends Controller
{
    protected $condominiumModel;
    protected $subscriptionModel;
    protected $subscriptionService;

    public function __construct()
    {
        parent::__construct();
        $this->condominiumModel = new Condominium();
        $this->subscriptionModel = new Subscription();
        $this->subscriptionService = new SubscriptionService();
    }

    public function index()
    {
        // Redirect to dashboard - dashboard now shows condominiums list
        AuthMiddleware::require();
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }

    public function create()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireAnyRole(['admin', 'super_admin']);

        $userId = AuthMiddleware::userId();
        
        // Check subscription limits
        if (!$this->subscriptionModel->canCreateCondominium($userId)) {
            $_SESSION['error'] = 'Limite de condomínios atingido. Faça upgrade do seu plano.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/create.html.twig',
            'page' => ['titulo' => 'Criar Condomínio'],
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireAnyRole(['admin', 'super_admin']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/create');
            exit;
        }

        $userId = AuthMiddleware::userId();
        
        if (!$this->subscriptionModel->canCreateCondominium($userId)) {
            $_SESSION['error'] = 'Limite de condomínios atingido.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        try {
            $condominiumId = $this->condominiumModel->create([
                'user_id' => $userId,
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'address' => Security::sanitize($_POST['address'] ?? ''),
                'postal_code' => Security::sanitize($_POST['postal_code'] ?? ''),
                'city' => Security::sanitize($_POST['city'] ?? ''),
                'country' => Security::sanitize($_POST['country'] ?? 'Portugal'),
                'nif' => Security::sanitize($_POST['nif'] ?? ''),
                'iban' => Security::sanitize($_POST['iban'] ?? ''),
                'phone' => Security::sanitize($_POST['phone'] ?? ''),
                'email' => Security::sanitize($_POST['email'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? 'habitacional'),
                'total_fractions' => (int)($_POST['total_fractions'] ?? 0),
                'rules' => Security::sanitize($_POST['rules'] ?? '')
            ]);

            $_SESSION['success'] = 'Condomínio criado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar condomínio: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/create');
            exit;
        }
    }

    public function show(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        $condominium = $this->condominiumModel->getWithStats($id);
        
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        global $db;
        
        // Get bank accounts information
        $bankAccountModel = new \App\Models\BankAccount();
        
        // Ensure cash account exists
        $cashAccount = $bankAccountModel->getCashAccount($id);
        if (!$cashAccount) {
            $bankAccountModel->createCashAccount($id);
        }
        
        $bankAccountsRaw = $bankAccountModel->getActiveAccounts($id);
        $totalBankBalance = 0;
        $currentAccountBalance = 0;
        $bankAccounts = [];
        $mainAccountIban = null; // IBAN da conta principal
        
        foreach ($bankAccountsRaw as $accountRaw) {
            $bankAccountModel->updateBalance($accountRaw['id']);
            $account = $bankAccountModel->findById($accountRaw['id']);
            if ($account) {
                $balance = (float)($account['current_balance'] ?? 0);
                $totalBankBalance += $balance;
                
                // Check if it's a current account (conta à ordem)
                if ($account['account_type'] === 'bank' && 
                    (stripos($account['name'], 'ordem') !== false || 
                     stripos($account['name'], 'corrente') !== false ||
                     stripos($account['name'], 'principal') !== false)) {
                    $currentAccountBalance += $balance;
                    // Store IBAN of the first/main current account found
                    if ($mainAccountIban === null && !empty($account['iban'])) {
                        $mainAccountIban = $account['iban'];
                    }
                }
                
                $bankAccounts[] = $account;
            }
        }
        
        // If no IBAN found in current account, try to get from first bank account
        if ($mainAccountIban === null) {
            foreach ($bankAccounts as $account) {
                if ($account['account_type'] === 'bank' && !empty($account['iban'])) {
                    $mainAccountIban = $account['iban'];
                    break;
                }
            }
        }
        
        // Get condominium users (condóminos)
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
                f.identifier as fraction_identifier
            FROM condominium_users cu
            INNER JOIN users u ON u.id = cu.user_id
            LEFT JOIN fractions f ON f.id = cu.fraction_id
            WHERE cu.condominium_id = :condominium_id
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            ORDER BY cu.is_primary DESC, f.identifier ASC, u.name ASC
        ");
        $stmt->execute([':condominium_id' => $id]);
        $condominiumUsers = $stmt->fetchAll() ?: [];
        
        // Get additional statistics
        $stats = [
            'total_residents' => count($condominiumUsers),
            'total_bank_balance' => $totalBankBalance,
            'current_account_balance' => $currentAccountBalance,
            'total_bank_accounts' => count($bankAccounts)
        ];
        
        // Count overdue fees
        $stmt = $db->prepare("
            SELECT COUNT(*) as count,
                   COALESCE(SUM(f.amount - COALESCE((
                       SELECT SUM(fp.amount) 
                       FROM fee_payments fp 
                       WHERE fp.fee_id = f.id
                   ), 0)), 0) as total_amount
            FROM fees f
            WHERE f.condominium_id = :condominium_id
            AND f.status = 'pending'
            AND f.due_date < CURDATE()
            AND COALESCE(f.is_historical, 0) = 0
        ");
        $stmt->execute([':condominium_id' => $id]);
        $overdueResult = $stmt->fetch();
        $stats['overdue_fees'] = $overdueResult['count'] ?? 0;
        $stats['overdue_fees_amount'] = (float)($overdueResult['total_amount'] ?? 0);
        
        // Count open occurrences
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM occurrences 
            WHERE condominium_id = :condominium_id
            AND status IN ('open', 'in_analysis', 'assigned')
        ");
        $stmt->execute([':condominium_id' => $id]);
        $occurrenceResult = $stmt->fetch();
        $stats['open_occurrences'] = $occurrenceResult['count'] ?? 0;
        
        // Count pending fees
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(f.amount - COALESCE((
                SELECT SUM(fp.amount) 
                FROM fee_payments fp 
                WHERE fp.fee_id = f.id
            ), 0)), 0) as total
            FROM fees f
            WHERE f.condominium_id = :condominium_id
            AND f.status = 'pending'
            AND COALESCE(f.is_historical, 0) = 0
        ");
        $stmt->execute([':condominium_id' => $id]);
        $pendingResult = $stmt->fetch();
        $stats['pending_fees_amount'] = (float)($pendingResult['total'] ?? 0);
        
        // Count paid fees
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(fp.amount), 0) as total
            FROM fee_payments fp
            INNER JOIN fees f ON f.id = fp.fee_id
            WHERE f.condominium_id = :condominium_id
        ");
        $stmt->execute([':condominium_id' => $id]);
        $paidResult = $stmt->fetch();
        $stats['paid_fees_amount'] = (float)($paidResult['total'] ?? 0);

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/show.html.twig',
            'page' => ['titulo' => $condominium['name']],
            'condominium' => $condominium,
            'bank_accounts' => $bankAccounts,
            'condominium_users' => $condominiumUsers,
            'stats' => $stats,
            'main_account_iban' => $mainAccountIban
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function edit(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        $condominium = $this->condominiumModel->findById($id);
        
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/edit.html.twig',
            'page' => ['titulo' => 'Editar Condomínio'],
            'condominium' => $condominium,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function update(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            exit;
        }

        try {
            $this->condominiumModel->update($id, [
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'address' => Security::sanitize($_POST['address'] ?? ''),
                'postal_code' => Security::sanitize($_POST['postal_code'] ?? ''),
                'city' => Security::sanitize($_POST['city'] ?? ''),
                'country' => Security::sanitize($_POST['country'] ?? 'Portugal'),
                'nif' => Security::sanitize($_POST['nif'] ?? ''),
                'iban' => Security::sanitize($_POST['iban'] ?? ''),
                'phone' => Security::sanitize($_POST['phone'] ?? ''),
                'email' => Security::sanitize($_POST['email'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? 'habitacional'),
                'total_fractions' => (int)($_POST['total_fractions'] ?? 0),
                'rules' => Security::sanitize($_POST['rules'] ?? '')
            ]);

            $_SESSION['success'] = 'Condomínio atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar condomínio: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            exit;
        }
    }

    public function delete(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        // Prevent deleting demo condominium
        \App\Middleware\DemoProtectionMiddleware::preventDemoCondominiumDelete($id);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        if ($this->condominiumModel->delete($id)) {
            $_SESSION['success'] = 'Condomínio removido com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao remover condomínio.';
        }

        header('Location: ' . BASE_URL . 'condominiums');
        exit;
    }
}





