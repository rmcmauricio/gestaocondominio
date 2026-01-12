<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\FinancialTransaction;
use App\Models\BankAccount;
use App\Models\Condominium;
use App\Models\Fee;

class FinancialTransactionController extends Controller
{
    protected $transactionModel;
    protected $bankAccountModel;
    protected $condominiumModel;
    protected $feeModel;

    public function __construct()
    {
        parent::__construct();
        $this->transactionModel = new FinancialTransaction();
        $this->bankAccountModel = new BankAccount();
        $this->condominiumModel = new Condominium();
        $this->feeModel = new Fee();
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

        $filters = [];
        if (!empty($_GET['bank_account_id'])) {
            $filters['bank_account_id'] = (int)$_GET['bank_account_id'];
        }
        if (!empty($_GET['transaction_type'])) {
            $filters['transaction_type'] = Security::sanitize($_GET['transaction_type']);
        }
        if (!empty($_GET['from_date'])) {
            $filters['from_date'] = $_GET['from_date'];
        }
        if (!empty($_GET['to_date'])) {
            $filters['to_date'] = $_GET['to_date'];
        }

        $transactions = $this->transactionModel->getByCondominium($condominiumId, $filters);
        $accounts = $this->bankAccountModel->getActiveAccounts($condominiumId);

        // Calculate running balance for each transaction
        $runningBalance = [];
        $accountBalances = [];
        foreach ($accounts as $account) {
            $accountBalances[$account['id']] = $account['initial_balance'];
        }

        foreach ($transactions as $transaction) {
            $accountId = $transaction['bank_account_id'];
            if (!isset($accountBalances[$accountId])) {
                $accountBalances[$accountId] = 0;
            }
            
            if ($transaction['transaction_type'] === 'income') {
                $accountBalances[$accountId] += (float)$transaction['amount'];
            } else {
                $accountBalances[$accountId] -= (float)$transaction['amount'];
            }
            
            $runningBalance[$transaction['id']] = $accountBalances[$accountId];
        }

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/financial-transactions/index.html.twig',
            'page' => ['titulo' => 'Movimentos Financeiros'],
            'condominium' => $condominium,
            'transactions' => $transactions,
            'accounts' => $accounts,
            'runningBalance' => $runningBalance,
            'filters' => $filters,
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

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $accounts = $this->bankAccountModel->getActiveAccounts($condominiumId);
        $pendingFees = $this->feeModel->getByCondominium($condominiumId, ['status' => 'pending']);

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/financial-transactions/create.html.twig',
            'page' => ['titulo' => 'Criar Movimento Financeiro'],
            'condominium' => $condominium,
            'accounts' => $accounts,
            'pendingFees' => $pendingFees,
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

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $bankAccountId = (int)($_POST['bank_account_id'] ?? 0);
        $transactionType = Security::sanitize($_POST['transaction_type'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $transactionDate = $_POST['transaction_date'] ?? date('Y-m-d');
        $description = Security::sanitize($_POST['description'] ?? '');
        $category = Security::sanitize($_POST['category'] ?? '');
        $reference = Security::sanitize($_POST['reference'] ?? '');

        // Validation
        if ($bankAccountId <= 0) {
            $_SESSION['error'] = 'Por favor, selecione uma conta.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }

        $account = $this->bankAccountModel->findById($bankAccountId);
        if (!$account || $account['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Conta inválida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }

        if (!in_array($transactionType, ['income', 'expense'])) {
            $_SESSION['error'] = 'Tipo de transação inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }

        if ($amount <= 0) {
            $_SESSION['error'] = 'O valor deve ser maior que zero.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }

        if (empty($description)) {
            $_SESSION['error'] = 'A descrição é obrigatória.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }

        // Check balance for expenses
        if ($transactionType === 'expense') {
            $currentBalance = $this->transactionModel->calculateAccountBalance($bankAccountId);
            if ($amount > $currentBalance) {
                $_SESSION['error'] = 'Saldo insuficiente. Saldo atual: €' . number_format($currentBalance, 2, ',', '.');
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
                exit;
            }
        }

        try {
            $userId = AuthMiddleware::userId();
            
            $this->transactionModel->create([
                'condominium_id' => $condominiumId,
                'bank_account_id' => $bankAccountId,
                'transaction_type' => $transactionType,
                'amount' => $amount,
                'transaction_date' => $transactionDate,
                'description' => $description,
                'category' => $category,
                'reference' => $reference,
                'related_type' => 'manual',
                'related_id' => null,
                'created_by' => $userId
            ]);

            $_SESSION['success'] = 'Movimento financeiro criado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar movimento: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }
    }

    public function edit(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $transaction = $this->transactionModel->findById($id);
        if (!$transaction || $transaction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Movimento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        // Don't allow editing transactions linked to fee payments
        if ($this->transactionModel->isLinkedToFeePayment($id)) {
            $_SESSION['error'] = 'Não é possível editar movimentos associados a pagamentos de quotas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        $accounts = $this->bankAccountModel->getActiveAccounts($condominiumId);

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/financial-transactions/edit.html.twig',
            'page' => ['titulo' => 'Editar Movimento Financeiro'],
            'condominium' => $condominium,
            'transaction' => $transaction,
            'accounts' => $accounts,
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

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/' . $id . '/edit');
            exit;
        }

        $transaction = $this->transactionModel->findById($id);
        if (!$transaction || $transaction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Movimento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        // Don't allow editing transactions linked to fee payments
        if ($this->transactionModel->isLinkedToFeePayment($id)) {
            $_SESSION['error'] = 'Não é possível editar movimentos associados a pagamentos de quotas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        $bankAccountId = (int)($_POST['bank_account_id'] ?? 0);
        $transactionType = Security::sanitize($_POST['transaction_type'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $transactionDate = $_POST['transaction_date'] ?? date('Y-m-d');
        $description = Security::sanitize($_POST['description'] ?? '');
        $category = Security::sanitize($_POST['category'] ?? '');
        $reference = Security::sanitize($_POST['reference'] ?? '');

        // Validation
        if ($bankAccountId <= 0) {
            $_SESSION['error'] = 'Por favor, selecione uma conta.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/' . $id . '/edit');
            exit;
        }

        $account = $this->bankAccountModel->findById($bankAccountId);
        if (!$account || $account['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Conta inválida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/' . $id . '/edit');
            exit;
        }

        if (!in_array($transactionType, ['income', 'expense'])) {
            $_SESSION['error'] = 'Tipo de transação inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/' . $id . '/edit');
            exit;
        }

        if ($amount <= 0) {
            $_SESSION['error'] = 'O valor deve ser maior que zero.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/' . $id . '/edit');
            exit;
        }

        if (empty($description)) {
            $_SESSION['error'] = 'A descrição é obrigatória.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/' . $id . '/edit');
            exit;
        }

        try {
            $this->transactionModel->update($id, [
                'bank_account_id' => $bankAccountId,
                'transaction_type' => $transactionType,
                'amount' => $amount,
                'transaction_date' => $transactionDate,
                'description' => $description,
                'category' => $category,
                'reference' => $reference
            ]);

            $_SESSION['success'] = 'Movimento financeiro atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar movimento: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/' . $id . '/edit');
            exit;
        }
    }

    public function delete(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        $transaction = $this->transactionModel->findById($id);
        if (!$transaction || $transaction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Movimento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        // Don't allow deleting transactions linked to fee payments
        if ($this->transactionModel->isLinkedToFeePayment($id)) {
            $_SESSION['error'] = 'Não é possível eliminar movimentos associados a pagamentos de quotas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        try {
            $this->transactionModel->delete($id);
            $_SESSION['success'] = 'Movimento financeiro eliminado com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao eliminar movimento: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
        exit;
    }

    public function getAccountBalance(int $condominiumId, int $accountId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $account = $this->bankAccountModel->findById($accountId);
        if (!$account || $account['condominium_id'] != $condominiumId) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Conta não encontrada']);
            exit;
        }

        $balance = $this->transactionModel->calculateAccountBalance($accountId);
        
        header('Content-Type: application/json');
        echo json_encode([
            'account_id' => $accountId,
            'balance' => $balance,
            'formatted_balance' => '€' . number_format($balance, 2, ',', '.')
        ]);
        exit;
    }
}
