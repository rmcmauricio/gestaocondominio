<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Expense;
use App\Models\Fee;
use App\Models\FeePayment;
use App\Models\Condominium;
use App\Models\Revenue;
use App\Models\BankAccount;
use App\Models\FinancialTransaction;
use App\Services\FeeService;

class FinanceController extends Controller
{
    protected $budgetModel;
    protected $budgetItemModel;
    protected $expenseModel;
    protected $feeModel;
    protected $feePaymentModel;
    protected $condominiumModel;
    protected $revenueModel;
    protected $feeService;

    public function __construct()
    {
        parent::__construct();
        $this->budgetModel = new Budget();
        $this->budgetItemModel = new BudgetItem();
        $this->expenseModel = new Expense();
        $this->feeModel = new Fee();
        $this->feePaymentModel = new FeePayment();
        $this->condominiumModel = new Condominium();
        $this->revenueModel = new Revenue();
        $this->feeService = new FeeService();
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

        $currentYear = date('Y');
        $budget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $currentYear);
        $nextYearBudget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $currentYear + 1);
        $allBudgets = $this->budgetModel->getByCondominium($condominiumId);
        
        $expenses = $this->expenseModel->getByCondominium($condominiumId, ['year' => $currentYear, 'limit' => 10]);
        
        // Get total expenses for current year
        $totalExpenses = $this->expenseModel->getTotalByPeriod(
            $condominiumId,
            "{$currentYear}-01-01",
            "{$currentYear}-12-31"
        );

        // Get bank accounts and balances
        $bankAccountModel = new BankAccount();
        $bankAccounts = $bankAccountModel->getActiveAccounts($condominiumId);
        
        // Update balances
        foreach ($bankAccounts as $account) {
            $bankAccountModel->updateBalance($account['id']);
        }
        
        // Refresh accounts with updated balances
        $bankAccounts = $bankAccountModel->getActiveAccounts($condominiumId);
        
        // Calculate total balance
        $totalBalance = 0;
        foreach ($bankAccounts as $account) {
            $totalBalance += (float)$account['current_balance'];
        }

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/index.html.twig',
            'page' => ['titulo' => 'Finanças'],
            'condominium' => $condominium,
            'budget' => $budget,
            'next_year_budget' => $nextYearBudget,
            'all_budgets' => $allBudgets,
            'expenses' => $expenses,
            'total_expenses' => $totalExpenses,
            'current_year' => $currentYear,
            'bank_accounts' => $bankAccounts,
            'total_balance' => $totalBalance,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'user' => AuthMiddleware::user()
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function createBudget(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $currentYear = date('Y');
        $selectedYear = !empty($_GET['year']) ? (int)$_GET['year'] : $currentYear;
        
        // Check if budget already exists for selected year
        $existingBudget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $selectedYear);
        
        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/create-budget.html.twig',
            'page' => ['titulo' => 'Criar Orçamento'],
            'condominium' => $condominium,
            'current_year' => $currentYear,
            'selected_year' => $selectedYear,
            'existing_budget' => $existingBudget,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function storeBudget(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/create');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $notes = Security::sanitize($_POST['notes'] ?? '');
        
        // Process revenue items
        $revenueItems = $_POST['revenue_items'] ?? [];
        $totalRevenue = 0;
        foreach ($revenueItems as $item) {
            if (!empty($item['amount']) && $item['amount'] > 0) {
                $totalRevenue += (float)$item['amount'];
            }
        }
        
        // Process expense items
        $expenseItems = $_POST['expense_items'] ?? [];
        $totalExpenses = 0;
        foreach ($expenseItems as $item) {
            if (!empty($item['amount']) && $item['amount'] > 0) {
                $totalExpenses += (float)$item['amount'];
            }
        }
        
        // Total amount is revenue minus expenses (or just revenue for quotas calculation)
        $totalAmount = $totalRevenue;

        if ($totalAmount <= 0) {
            $_SESSION['error'] = 'O orçamento deve ter pelo menos uma receita.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/create');
            exit;
        }

        global $db;
        try {
            $db->beginTransaction();
            
            // Create budget
            $budgetId = $this->budgetModel->create([
                'condominium_id' => $condominiumId,
                'year' => $year,
                'total_amount' => $totalAmount,
                'status' => 'draft',
                'notes' => $notes
            ]);

            // Create revenue items
            $sortOrder = 0;
            foreach ($revenueItems as $item) {
                if (!empty($item['amount']) && $item['amount'] > 0) {
                    $this->budgetItemModel->create([
                        'budget_id' => $budgetId,
                        'category' => 'Receita: ' . Security::sanitize($item['category'] ?? 'Outras'),
                        'description' => Security::sanitize($item['description'] ?? ''),
                        'amount' => (float)$item['amount'],
                        'sort_order' => $sortOrder++
                    ]);
                }
            }

            // Create expense items
            foreach ($expenseItems as $item) {
                if (!empty($item['amount']) && $item['amount'] > 0) {
                    $this->budgetItemModel->create([
                        'budget_id' => $budgetId,
                        'category' => 'Despesa: ' . Security::sanitize($item['category'] ?? 'Outras'),
                        'description' => Security::sanitize($item['description'] ?? ''),
                        'amount' => (float)$item['amount'],
                        'sort_order' => $sortOrder++
                    ]);
                }
            }

            $db->commit();
            $_SESSION['success'] = 'Orçamento criado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/' . $budgetId);
            exit;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = 'Erro ao criar orçamento: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/create');
            exit;
        }
    }

    public function createExpense(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        // Get fractions for dropdown
        $fractionModel = new \App\Models\Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        // Get suppliers for dropdown
        global $db;
        $suppliers = [];
        if ($db) {
            $stmt = $db->prepare("SELECT id, name FROM suppliers WHERE condominium_id = :condominium_id AND is_active = TRUE");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $suppliers = $stmt->fetchAll() ?: [];
        }

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/create-expense.html.twig',
            'page' => ['titulo' => 'Registar Despesa'],
            'condominium' => $condominium,
            'fractions' => $fractions,
            'suppliers' => $suppliers,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function storeExpense(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/expenses/create');
            exit;
        }

        $userId = AuthMiddleware::userId();

        try {
            $expenseId = $this->expenseModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null,
                'supplier_id' => !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
                'category' => Security::sanitize($_POST['category'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'amount' => (float)($_POST['amount'] ?? 0),
                'type' => Security::sanitize($_POST['type'] ?? 'ordinaria'),
                'expense_date' => $_POST['expense_date'] ?? date('Y-m-d'),
                'invoice_number' => Security::sanitize($_POST['invoice_number'] ?? ''),
                'invoice_date' => $_POST['invoice_date'] ?? null,
                'payment_method' => Security::sanitize($_POST['payment_method'] ?? ''),
                'is_paid' => isset($_POST['is_paid']),
                'notes' => Security::sanitize($_POST['notes'] ?? ''),
                'created_by' => $userId
            ]);

            $_SESSION['success'] = 'Despesa registada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao registar despesa: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/expenses/create');
            exit;
        }
    }

    public function showBudget(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $budget = $this->budgetModel->findById($id);
        if (!$budget || $budget['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Orçamento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances');
            exit;
        }

        $items = $this->budgetItemModel->getByBudget($id);
        $revenueItems = array_filter($items, function($item) {
            return strpos($item['category'], 'Receita:') === 0;
        });
        $expenseItems = array_filter($items, function($item) {
            return strpos($item['category'], 'Despesa:') === 0;
        });

        $totalRevenue = array_sum(array_column($revenueItems, 'amount'));
        $totalExpenses = array_sum(array_column($expenseItems, 'amount'));

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/show-budget.html.twig',
            'page' => ['titulo' => 'Orçamento ' . $budget['year']],
            'condominium' => $condominium,
            'budget' => $budget,
            'budget_items' => $items,
            'revenue_items' => $revenueItems,
            'expense_items' => $expenseItems,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'user' => AuthMiddleware::user()
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function editBudget(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $budget = $this->budgetModel->findById($id);
        if (!$budget || $budget['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Orçamento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances');
            exit;
        }

        $items = $this->budgetItemModel->getByBudget($id);

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/edit-budget.html.twig',
            'page' => ['titulo' => 'Editar Orçamento'],
            'condominium' => $condominium,
            'budget' => $budget,
            'budget_items' => $items,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'user' => AuthMiddleware::user()
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function updateBudget(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/' . $id . '/edit');
            exit;
        }

        $budget = $this->budgetModel->findById($id);
        if (!$budget || $budget['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Orçamento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances');
            exit;
        }

        $notes = Security::sanitize($_POST['notes'] ?? '');
        
        // Process revenue items
        $revenueItems = $_POST['revenue_items'] ?? [];
        $totalRevenue = 0;
        foreach ($revenueItems as $item) {
            if (!empty($item['amount']) && $item['amount'] > 0) {
                $totalRevenue += (float)$item['amount'];
            }
        }
        
        // Process expense items
        $expenseItems = $_POST['expense_items'] ?? [];
        $totalExpenses = 0;
        foreach ($expenseItems as $item) {
            if (!empty($item['amount']) && $item['amount'] > 0) {
                $totalExpenses += (float)$item['amount'];
            }
        }
        
        $totalAmount = $totalRevenue;

        if ($totalAmount <= 0) {
            $_SESSION['error'] = 'O orçamento deve ter pelo menos uma receita.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/' . $id . '/edit');
            exit;
        }

        global $db;
        try {
            $db->beginTransaction();
            
            // Update budget
            $this->budgetModel->update($id, [
                'total_amount' => $totalAmount,
                'notes' => $notes
            ]);

            // Delete existing items
            $this->budgetItemModel->deleteByBudget($id);

            // Create revenue items
            $sortOrder = 0;
            foreach ($revenueItems as $item) {
                if (!empty($item['amount']) && $item['amount'] > 0) {
                    $this->budgetItemModel->create([
                        'budget_id' => $id,
                        'category' => 'Receita: ' . Security::sanitize($item['category'] ?? 'Outras'),
                        'description' => Security::sanitize($item['description'] ?? ''),
                        'amount' => (float)$item['amount'],
                        'sort_order' => $sortOrder++
                    ]);
                }
            }

            // Create expense items
            foreach ($expenseItems as $item) {
                if (!empty($item['amount']) && $item['amount'] > 0) {
                    $this->budgetItemModel->create([
                        'budget_id' => $id,
                        'category' => 'Despesa: ' . Security::sanitize($item['category'] ?? 'Outras'),
                        'description' => Security::sanitize($item['description'] ?? ''),
                        'amount' => (float)$item['amount'],
                        'sort_order' => $sortOrder++
                    ]);
                }
            }

            $db->commit();
            $_SESSION['success'] = 'Orçamento atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/' . $id);
            exit;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = 'Erro ao atualizar orçamento: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/' . $id . '/edit');
            exit;
        }
    }

    public function approveBudget(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/' . $id);
            exit;
        }

        $userId = AuthMiddleware::userId();
        
        if ($this->budgetModel->approve($id, $userId)) {
            $_SESSION['success'] = 'Orçamento aprovado com sucesso! Agora pode gerar quotas.';
        } else {
            $_SESSION['error'] = 'Erro ao aprovar orçamento.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/budgets/' . $id);
        exit;
    }

    public function generateFees(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $month = (int)($_POST['month'] ?? date('m'));

        try {
            $generated = $this->feeService->generateMonthlyFees($condominiumId, $year, $month);
            
            $_SESSION['success'] = count($generated) . ' quotas geradas com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao gerar quotas: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }
    }

    /**
     * Add partial payment to fee
     */
    public function addPayment(int $condominiumId, int $feeId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Quota não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = Security::sanitize($_POST['payment_method'] ?? '');
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $reference = Security::sanitize($_POST['reference'] ?? '');
        $notes = Security::sanitize($_POST['notes'] ?? '');

        if ($amount <= 0 || empty($paymentMethod)) {
            $_SESSION['error'] = 'Por favor, preencha todos os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        // Get current paid amount
        $totalPaid = $this->feePaymentModel->getTotalPaid($feeId);
        $remainingAmount = (float)$fee['amount'] - $totalPaid;

        if ($amount > $remainingAmount) {
            $_SESSION['error'] = 'O valor do pagamento não pode ser superior ao valor pendente (€' . number_format($remainingAmount, 2, ',', '.') . ').';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        try {
            $userId = AuthMiddleware::userId();
            $transactionModel = new FinancialTransaction();
            $bankAccountModel = new BankAccount();
            
            // Start transaction
            global $db;
            $db->beginTransaction();
            
            try {
                $financialTransactionId = null;
                $transactionAction = $_POST['transaction_action'] ?? 'create'; // 'create' or 'associate'
                
                if ($transactionAction === 'associate') {
                    // Associate with existing transaction
                    $existingTransactionId = (int)($_POST['existing_transaction_id'] ?? 0);
                    if ($existingTransactionId > 0) {
                        $existingTransaction = $transactionModel->findById($existingTransactionId);
                        if ($existingTransaction && $existingTransaction['condominium_id'] == $condominiumId) {
                            $financialTransactionId = $existingTransactionId;
                        } else {
                            throw new \Exception('Movimento financeiro não encontrado ou inválido.');
                        }
                    } else {
                        throw new \Exception('Por favor, selecione um movimento financeiro existente.');
                    }
                } else {
                    // Create new financial transaction
                    $bankAccountId = (int)($_POST['bank_account_id'] ?? 0);
                    
                    if ($bankAccountId <= 0) {
                        // Get cash account if no account selected
                        $cashAccount = $bankAccountModel->getCashAccount($condominiumId);
                        if (!$cashAccount) {
                            $bankAccountId = $bankAccountModel->createCashAccount($condominiumId);
                        } else {
                            $bankAccountId = $cashAccount['id'];
                        }
                    } else {
                        // Verify account belongs to condominium
                        $account = $bankAccountModel->findById($bankAccountId);
                        if (!$account || $account['condominium_id'] != $condominiumId) {
                            throw new \Exception('Conta bancária inválida.');
                        }
                    }
                    
                    // Create financial transaction first
                    $description = "Pagamento de quota";
                    if ($reference) {
                        $description .= " - Ref: " . $reference;
                    }
                    if ($notes) {
                        $description .= " - " . $notes;
                    }
                    
                    $financialTransactionId = $transactionModel->create([
                        'condominium_id' => $condominiumId,
                        'bank_account_id' => $bankAccountId,
                        'transaction_type' => 'income',
                        'amount' => $amount,
                        'transaction_date' => $paymentDate,
                        'description' => $description,
                        'category' => 'Quotas',
                        'reference' => $reference,
                        'related_type' => 'fee_payment',
                        'related_id' => null, // Will be set after payment creation
                        'created_by' => $userId
                    ]);
                }
                
                // Create payment with transaction ID
                $paymentId = $this->feePaymentModel->create([
                    'fee_id' => $feeId,
                    'financial_transaction_id' => $financialTransactionId,
                    'amount' => $amount,
                    'payment_method' => $paymentMethod,
                    'payment_date' => $paymentDate,
                    'reference' => $reference,
                    'notes' => $notes,
                    'created_by' => $userId
                ]);
                
                // Update transaction with payment ID if it was created new
                if ($transactionAction === 'create') {
                    global $db;
                    $stmt = $db->prepare("UPDATE financial_transactions SET related_id = :related_id WHERE id = :id");
                    $stmt->execute([
                        ':related_id' => $paymentId,
                        ':id' => $financialTransactionId
                    ]);
                }
                
                // Check if fee is now fully paid
                $newTotalPaid = $this->feePaymentModel->getTotalPaid($feeId);
                if ($newTotalPaid >= (float)$fee['amount']) {
                    $this->feeModel->markAsPaid($feeId);
                }
                
                $db->commit();
                $_SESSION['success'] = 'Pagamento registado com sucesso!';
            } catch (\Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao registar pagamento: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
        exit;
    }

    /**
     * Get fee details with payments (for modal)
     */
    public function getFeeDetails(int $condominiumId, int $feeId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            http_response_code(404);
            echo json_encode(['error' => 'Quota não encontrada']);
            exit;
        }

        // Get fraction info
        $fractionModel = new \App\Models\Fraction();
        $fraction = $fractionModel->findById($fee['fraction_id']);

        // Get payments
        $payments = $this->feePaymentModel->getByFee($feeId);
        $totalPaid = $this->feePaymentModel->getTotalPaid($feeId);
        $pendingAmount = (float)$fee['amount'] - $totalPaid;

        header('Content-Type: application/json');
        echo json_encode([
            'fee' => $fee,
            'fraction' => $fraction,
            'payments' => $payments,
            'total_amount' => (float)$fee['amount'],
            'total_paid' => $totalPaid,
            'pending_amount' => $pendingAmount
        ]);
        exit;
    }

    public function fees(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $currentYear = date('Y');
        $currentMonth = date('m');
        $selectedYear = !empty($_GET['year']) ? (int)$_GET['year'] : null;
        $selectedMonth = !empty($_GET['month']) ? (int)$_GET['month'] : null;
        $selectedStatus = $_GET['status'] ?? null;
        // Check if show_historical is set and equals '1' (can be from checkbox or URL parameter)
        $showHistorical = !empty($_GET['show_historical']) && $_GET['show_historical'] == '1';

        $filters = [
            'year' => $selectedYear
        ];

        if ($selectedMonth) {
            $filters['month'] = $selectedMonth;
        }

        // Get fees including historical debts
        global $db;
        // Use subquery to calculate paid_amount to avoid duplicates from JOIN
        // Use DISTINCT to ensure no duplicate fees
        $sql = "SELECT DISTINCT f.id, f.*, fr.identifier as fraction_identifier,
                       COALESCE((
                           SELECT SUM(fp.amount) 
                           FROM fee_payments fp 
                           WHERE fp.fee_id = f.id
                       ), 0) as paid_amount,
                       (f.amount - COALESCE((
                           SELECT SUM(fp.amount) 
                           FROM fee_payments fp 
                           WHERE fp.fee_id = f.id
                       ), 0)) as pending_amount
                FROM fees f
                INNER JOIN fractions fr ON fr.id = f.fraction_id
                WHERE f.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        // Build filter conditions
        // Handle is_historical - MySQL stores booleans as TINYINT(1): 1 for TRUE, 0 for FALSE
        if ($showHistorical) {
            // Show all historical debts + regular fees matching year/month filters
            // Historical debts always appear regardless of year/month filters
            $sql .= " AND (f.is_historical = 1";
            
            // Add regular fees condition
            if ($selectedYear !== null || $selectedMonth !== null) {
                // Regular fees must match year/month filters
                $sql .= " OR (COALESCE(f.is_historical, 0) = 0";
                if ($selectedYear !== null) {
                    $sql .= " AND f.period_year = :year";
                    $params[':year'] = $selectedYear;
                }
                if ($selectedMonth !== null) {
                    $sql .= " AND f.period_month = :month";
                    $params[':month'] = $selectedMonth;
                }
                $sql .= ")";
            } else {
                // No year/month filters, show all regular fees too
                $sql .= " OR COALESCE(f.is_historical, 0) = 0";
            }
            $sql .= ")";
            
            // Status filter: apply only to regular fees, not historical debts
            if ($selectedStatus === 'overdue') {
                $sql .= " AND (f.is_historical = 1 OR (f.status = 'pending' AND f.due_date < CURDATE()))";
            } elseif ($selectedStatus) {
                $sql .= " AND (f.is_historical = 1 OR f.status = :status)";
                $params[':status'] = $selectedStatus;
            }
        } else {
            // Only show regular fees matching filters
            $sql .= " AND COALESCE(f.is_historical, 0) = 0";
            if ($selectedYear !== null) {
                $sql .= " AND f.period_year = :year";
                $params[':year'] = $selectedYear;
            }
            if ($selectedMonth !== null) {
                $sql .= " AND f.period_month = :month";
                $params[':month'] = $selectedMonth;
            }
            
            // Status filter for regular fees only
            if ($selectedStatus === 'overdue') {
                $sql .= " AND f.status = 'pending' AND f.due_date < CURDATE()";
            } elseif ($selectedStatus) {
                $sql .= " AND f.status = :status";
                $params[':status'] = $selectedStatus;
            }
        }

        $sql .= " ORDER BY f.is_historical ASC, f.period_year DESC, f.period_month DESC, fr.identifier ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $fees = $stmt->fetchAll() ?: [];
        
        // Remove duplicates by ID (in case DISTINCT doesn't work due to subquery)
        $uniqueFees = [];
        $seenIds = [];
        foreach ($fees as $fee) {
            if (!in_array($fee['id'], $seenIds)) {
                $uniqueFees[] = $fee;
                $seenIds[] = $fee['id'];
            }
        }
        $fees = $uniqueFees;

        // Calculate actual status based on payments
        foreach ($fees as &$fee) {
            $paidAmount = (float)$fee['paid_amount'];
            $totalAmount = (float)$fee['amount'];
            $pendingAmount = (float)$fee['pending_amount'];

            // Update status based on payments
            if ($paidAmount >= $totalAmount) {
                $fee['calculated_status'] = 'paid';
            } elseif ($pendingAmount > 0 && strtotime($fee['due_date']) < time()) {
                $fee['calculated_status'] = 'overdue';
            } elseif ($paidAmount > 0) {
                $fee['calculated_status'] = 'partial';
            } else {
                $fee['calculated_status'] = 'pending';
            }
        }

        // Calculate summary
        $summary = [
            'total' => 0,
            'paid' => 0,
            'pending' => 0,
            'overdue' => 0,
            'partial' => 0
        ];

        foreach ($fees as $fee) {
            $totalAmount = (float)$fee['amount'];
            $paidAmount = (float)$fee['paid_amount'];
            $pendingAmount = (float)$fee['pending_amount'];

            $summary['total'] += $totalAmount;
            $summary['paid'] += $paidAmount;
            $summary['pending'] += $pendingAmount;

            if ($fee['calculated_status'] === 'overdue') {
                $summary['overdue'] += $pendingAmount;
            } elseif ($fee['calculated_status'] === 'partial') {
                $summary['partial'] += $pendingAmount;
            }
        }

        // Get bank accounts for payment modal
        $bankAccountModel = new BankAccount();
        $bankAccounts = $bankAccountModel->getActiveAccounts($condominiumId);

        // Get available transactions for association
        $transactionModel = new FinancialTransaction();
        $availableTransactions = $transactionModel->getByCondominium($condominiumId, [
            'transaction_type' => 'income',
            'limit' => 50
        ]);

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/fees.html.twig',
            'page' => ['titulo' => 'Quotas'],
            'condominium' => $condominium,
            'fees' => $fees,
            'summary' => $summary,
            'current_year' => $currentYear,
            'current_month' => $currentMonth,
            'selected_year' => $selectedYear ?? '',
            'selected_month' => $selectedMonth,
            'selected_status' => $selectedStatus,
            'show_historical' => $showHistorical,
            'bank_accounts' => $bankAccounts,
            'available_transactions' => $availableTransactions,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'user' => AuthMiddleware::user()
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function markFeeAsPaid(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        if ($this->feeModel->markAsPaid($id)) {
            $_SESSION['success'] = 'Quota marcada como paga!';
        } else {
            $_SESSION['error'] = 'Erro ao marcar quota como paga.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
        exit;
    }

    /**
     * Show historical debts page
     */
    public function historicalDebts(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        // Get fractions
        $fractionModel = new \App\Models\Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        // Get historical debts (fees from years before system registration)
        global $db;
        $historicalDebts = [];
        if ($db) {
            $stmt = $db->prepare("
                SELECT f.*, fr.identifier as fraction_identifier, fr.permillage
                FROM fees f
                INNER JOIN fractions fr ON fr.id = f.fraction_id
                WHERE f.condominium_id = :condominium_id
                AND f.is_historical = TRUE
                ORDER BY f.period_year DESC, f.period_month DESC, fr.identifier ASC
            ");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $historicalDebts = $stmt->fetchAll() ?: [];
        }

        $currentYear = date('Y');
        
        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/historical-debts.html.twig',
            'page' => ['titulo' => 'Dívidas Históricas'],
            'condominium' => $condominium,
            'fractions' => $fractions,
            'historical_debts' => $historicalDebts,
            'current_year' => $currentYear,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'user' => AuthMiddleware::user()
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Store historical debts
     */
    public function storeHistoricalDebts(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/historical-debts');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/historical-debts');
            exit;
        }

        $fractionId = (int)($_POST['fraction_id'] ?? 0);
        $year = (int)($_POST['year'] ?? date('Y'));
        $month = !empty($_POST['month']) ? (int)$_POST['month'] : null;
        $amount = (float)($_POST['amount'] ?? 0);
        $dueDate = $_POST['due_date'] ?? date('Y-m-d');
        $notes = Security::sanitize($_POST['notes'] ?? '');

        if ($fractionId <= 0 || $amount <= 0) {
            $_SESSION['error'] = 'Por favor, preencha todos os campos obrigatórios.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/historical-debts');
            exit;
        }

        // Verify fraction belongs to condominium
        $fractionModel = new \App\Models\Fraction();
        $fraction = $fractionModel->findById($fractionId);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fração inválida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/historical-debts');
            exit;
        }

        try {
            // Create historical fee
            // Explicitly set is_historical to 1 (not boolean true) to ensure MySQL stores it correctly
            $this->feeModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => $fractionId,
                'period_type' => $month ? 'monthly' : 'yearly',
                'period_year' => $year,
                'period_month' => $month,
                'amount' => $amount,
                'base_amount' => $amount,
                'status' => 'pending',
                'due_date' => $dueDate,
                'notes' => 'Dívida histórica: ' . $notes,
                'is_historical' => 1  // Use integer 1 instead of boolean true
            ]);

            $_SESSION['success'] = 'Dívida histórica registada com sucesso!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao registar dívida histórica: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/historical-debts');
        exit;
    }

    /**
     * Show revenues page
     */
    public function revenues(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $currentYear = date('Y');
        $selectedYear = !empty($_GET['year']) ? (int)$_GET['year'] : $currentYear;
        $selectedMonth = !empty($_GET['month']) ? (int)$_GET['month'] : null;

        $filters = ['year' => $selectedYear];
        if ($selectedMonth) {
            $filters['month'] = $selectedMonth;
        }

        $revenues = $this->revenueModel->getByCondominium($condominiumId, $filters);
        
        // Calculate totals
        $totalRevenues = array_sum(array_column($revenues, 'amount'));
        $totalByMonth = [];
        foreach ($revenues as $revenue) {
            $month = date('Y-m', strtotime($revenue['revenue_date']));
            if (!isset($totalByMonth[$month])) {
                $totalByMonth[$month] = 0;
            }
            $totalByMonth[$month] += (float)$revenue['amount'];
        }

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/revenues.html.twig',
            'page' => ['titulo' => 'Receitas'],
            'condominium' => $condominium,
            'revenues' => $revenues,
            'total_revenues' => $totalRevenues,
            'total_by_month' => $totalByMonth,
            'current_year' => $currentYear,
            'selected_year' => $selectedYear,
            'selected_month' => $selectedMonth,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'user' => AuthMiddleware::user()
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show create revenue form
     */
    public function createRevenue(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        // Get fractions for dropdown
        $fractionModel = new \App\Models\Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/create-revenue.html.twig',
            'page' => ['titulo' => 'Registar Receita'],
            'condominium' => $condominium,
            'fractions' => $fractions,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Store revenue
     */
    public function storeRevenue(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues/create');
            exit;
        }

        $userId = AuthMiddleware::userId();

        try {
            $revenueId = $this->revenueModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null,
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'amount' => (float)($_POST['amount'] ?? 0),
                'revenue_date' => $_POST['revenue_date'] ?? date('Y-m-d'),
                'payment_method' => Security::sanitize($_POST['payment_method'] ?? ''),
                'reference' => Security::sanitize($_POST['reference'] ?? ''),
                'notes' => Security::sanitize($_POST['notes'] ?? ''),
                'created_by' => $userId
            ]);

            $_SESSION['success'] = 'Receita registada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao registar receita: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues/create');
            exit;
        }
    }

    /**
     * Edit revenue
     */
    public function editRevenue(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $revenue = $this->revenueModel->findById($id);
        if (!$revenue || $revenue['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Receita não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
            exit;
        }

        // Get fractions for dropdown
        $fractionModel = new \App\Models\Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/finances/edit-revenue.html.twig',
            'page' => ['titulo' => 'Editar Receita'],
            'condominium' => $condominium,
            'revenue' => $revenue,
            'fractions' => $fractions,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Update revenue
     */
    public function updateRevenue(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues/' . $id . '/edit');
            exit;
        }

        $revenue = $this->revenueModel->findById($id);
        if (!$revenue || $revenue['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Receita não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
            exit;
        }

        try {
            $this->revenueModel->update($id, [
                'fraction_id' => !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null,
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'amount' => (float)($_POST['amount'] ?? 0),
                'revenue_date' => $_POST['revenue_date'] ?? date('Y-m-d'),
                'payment_method' => Security::sanitize($_POST['payment_method'] ?? ''),
                'reference' => Security::sanitize($_POST['reference'] ?? ''),
                'notes' => Security::sanitize($_POST['notes'] ?? '')
            ]);

            $_SESSION['success'] = 'Receita atualizada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar receita: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues/' . $id . '/edit');
            exit;
        }
    }

    /**
     * Delete revenue
     */
    public function deleteRevenue(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
            exit;
        }

        $revenue = $this->revenueModel->findById($id);
        if (!$revenue || $revenue['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Receita não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
            exit;
        }

        if ($this->revenueModel->delete($id)) {
            $_SESSION['success'] = 'Receita eliminada com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao eliminar receita.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/revenues');
        exit;
    }

    /**
     * Bulk mark fees as paid
     */
    public function bulkMarkFeesAsPaid(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        $feeIds = $_POST['fee_ids'] ?? [];
        
        if (empty($feeIds)) {
            $_SESSION['error'] = 'Nenhuma quota selecionada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
            exit;
        }

        $successCount = 0;
        $errorCount = 0;

        foreach ($feeIds as $feeId) {
            $fee = $this->feeModel->findById((int)$feeId);
            if ($fee && $fee['condominium_id'] == $condominiumId) {
                if ($this->feeModel->markAsPaid((int)$feeId)) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } else {
                $errorCount++;
            }
        }

        if ($successCount > 0) {
            $_SESSION['success'] = "{$successCount} quota(s) marcada(s) como paga(s) com sucesso!";
        }
        if ($errorCount > 0) {
            $_SESSION['error'] = "Erro ao processar {$errorCount} quota(s).";
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees');
        exit;
    }
}

