<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\BankAccount;
use App\Models\Condominium;

class BankAccountController extends Controller
{
    protected $bankAccountModel;
    protected $condominiumModel;

    public function __construct()
    {
        parent::__construct();
        $this->bankAccountModel = new BankAccount();
        $this->condominiumModel = new Condominium();
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

        $accounts = $this->bankAccountModel->getByCondominium($condominiumId);
        
        // Update balances for all accounts
        foreach ($accounts as $account) {
            $this->bankAccountModel->updateBalance($account['id']);
        }
        
        // Refresh accounts with updated balances
        $accounts = $this->bankAccountModel->getByCondominium($condominiumId);
        
        // Check which accounts have transactions
        foreach ($accounts as &$account) {
            $account['has_transactions'] = $this->bankAccountModel->hasTransactions($account['id']);
        }
        unset($account);

        $this->loadPageTranslations('finances');
        
        $isAdmin = RoleMiddleware::isAdmin();
        
        $this->data += [
            'viewName' => 'pages/bank-accounts/index.html.twig',
            'page' => ['titulo' => 'Contas Bancárias'],
            'condominium' => $condominium,
            'accounts' => $accounts,
            'is_admin' => $isAdmin,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];

        unset($_SESSION['error'], $_SESSION['success']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function create(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/bank-accounts/create.html.twig',
            'page' => ['titulo' => 'Criar Conta Bancária'],
            'condominium' => $condominium,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];

        unset($_SESSION['error'], $_SESSION['success']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/create');
            exit;
        }

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $name = Security::sanitize($_POST['name'] ?? '');
        $accountType = Security::sanitize($_POST['account_type'] ?? 'bank');
        $initialBalance = (float)($_POST['initial_balance'] ?? 0);

        // Validation
        if (empty($name)) {
            $_SESSION['error'] = 'O nome da conta é obrigatório.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/create');
            exit;
        }

        if ($accountType === 'bank') {
            $iban = Security::sanitize($_POST['iban'] ?? '');
            $swift = Security::sanitize($_POST['swift'] ?? '');

            if (empty($iban) || empty($swift)) {
                $_SESSION['error'] = 'Para contas bancárias, IBAN e SWIFT são obrigatórios.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/create');
                exit;
            }

            // Validate IBAN format
            if (!Security::validateIban($iban)) {
                $_SESSION['error'] = 'Formato de IBAN inválido. O IBAN deve ter entre 15 e 34 caracteres, começar com 2 letras (código do país), seguido de 2 dígitos e caracteres alfanuméricos.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/create');
                exit;
            }
        } else {
            // For cash accounts, ignore bank fields
            $iban = null;
            $swift = null;
        }

        try {
            $accountId = $this->bankAccountModel->create([
                'condominium_id' => $condominiumId,
                'name' => $name,
                'account_type' => $accountType,
                'bank_name' => Security::sanitize($_POST['bank_name'] ?? ''),
                'account_number' => Security::sanitize($_POST['account_number'] ?? ''),
                'iban' => $iban,
                'swift' => $swift,
                'initial_balance' => $initialBalance,
                'current_balance' => $initialBalance,
                'is_active' => true
            ]);

            $_SESSION['success'] = 'Conta criada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar conta: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/create');
            exit;
        }
    }

    public function edit(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $account = $this->bankAccountModel->findById($id);
        if (!$account || $account['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Conta não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        }

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/bank-accounts/edit.html.twig',
            'page' => ['titulo' => 'Editar Conta Bancária'],
            'condominium' => $condominium,
            'account' => $account,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];

        unset($_SESSION['error'], $_SESSION['success']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function update(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/' . $id . '/edit');
            exit;
        }

        $account = $this->bankAccountModel->findById($id);
        if (!$account || $account['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Conta não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        }

        $name = Security::sanitize($_POST['name'] ?? '');
        $accountType = Security::sanitize($_POST['account_type'] ?? $account['account_type']);

        // Validation
        if (empty($name)) {
            $_SESSION['error'] = 'O nome da conta é obrigatório.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/' . $id . '/edit');
            exit;
        }

        $updateData = [
            'name' => $name,
            'account_type' => $accountType
        ];

        if ($accountType === 'bank') {
            $iban = Security::sanitize($_POST['iban'] ?? '');
            $swift = Security::sanitize($_POST['swift'] ?? '');

            if (empty($iban) || empty($swift)) {
                $_SESSION['error'] = 'Para contas bancárias, IBAN e SWIFT são obrigatórios.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/' . $id . '/edit');
                exit;
            }

            // Validate IBAN format
            if (!Security::validateIban($iban)) {
                $_SESSION['error'] = 'Formato de IBAN inválido. O IBAN deve ter entre 15 e 34 caracteres, começar com 2 letras (código do país), seguido de 2 dígitos e caracteres alfanuméricos.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/' . $id . '/edit');
                exit;
            }

            $updateData['iban'] = $iban;
            $updateData['swift'] = $swift;
            $updateData['bank_name'] = Security::sanitize($_POST['bank_name'] ?? '');
            $updateData['account_number'] = Security::sanitize($_POST['account_number'] ?? '');
        } else {
            // For cash accounts, clear bank fields
            $updateData['iban'] = null;
            $updateData['swift'] = null;
            $updateData['bank_name'] = null;
            $updateData['account_number'] = null;
        }

        if (isset($_POST['initial_balance'])) {
            $updateData['initial_balance'] = (float)$_POST['initial_balance'];
        }

        if (isset($_POST['is_active'])) {
            $updateData['is_active'] = true;
        } else {
            $updateData['is_active'] = false;
        }

        try {
            $this->bankAccountModel->update($id, $updateData);
            $_SESSION['success'] = 'Conta atualizada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar conta: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts/' . $id . '/edit');
            exit;
        }
    }

    public function delete(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        }

        $account = $this->bankAccountModel->findById($id);
        if (!$account || $account['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Conta não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        }

        // Check if account has transactions
        if ($this->bankAccountModel->hasTransactions($id)) {
            $_SESSION['error'] = 'Não é possível eliminar uma conta que possui movimentos financeiros.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
            exit;
        }

        try {
            $this->bankAccountModel->delete($id);
            $_SESSION['success'] = 'Conta eliminada com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao eliminar conta: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/bank-accounts');
        exit;
    }
}
