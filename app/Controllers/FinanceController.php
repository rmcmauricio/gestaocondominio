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
use App\Models\Fraction;
use App\Models\FractionAccount;
use App\Models\FractionAccountMovement;
use App\Services\FeeService;
use App\Services\LiquidationService;
use App\Services\ReceiptService;
use App\Services\AuditService;

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
    protected $auditService;

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
        $this->auditService = new AuditService();
    }

    /**
     * Build the fees page redirect URL preserving current filters (year, month, status, etc.).
     * Uses POST params (redirect_*) from form if present, else HTTP_REFERER if it's the fees page.
     */
    private function buildFeesRedirectUrl(int $condominiumId): string
    {
        $baseUrl = BASE_URL . 'condominiums/' . $condominiumId . '/fees';
        $params = [];

        // Prefer explicit redirect params from form
        if (!empty($_POST['redirect_year'])) {
            $params['year'] = $_POST['redirect_year'];
        }
        if (!empty($_POST['redirect_month'])) {
            $params['month'] = $_POST['redirect_month'];
        }
        if (!empty($_POST['redirect_status'])) {
            $params['status'] = $_POST['redirect_status'];
        }
        if (!empty($_POST['redirect_fraction_id'])) {
            $params['fraction_id'] = $_POST['redirect_fraction_id'];
        }
        if (!empty($_POST['redirect_show_historical']) && $_POST['redirect_show_historical'] === '1') {
            $params['show_historical'] = '1';
        }
        if (!empty($_POST['redirect_fees_year'])) {
            $params['fees_year'] = $_POST['redirect_fees_year'];
        }

        if (!empty($params)) {
            return $baseUrl . '?' . http_build_query($params);
        }

        // Fallback: use Referer if it's our fees page for this condominium
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer && strpos($referer, 'condominiums/' . $condominiumId . '/fees') !== false) {
            $parsed = parse_url($referer);
            $query = $parsed['query'] ?? '';
            return $baseUrl . ($query ? '?' . $query : '');
        }

        return $baseUrl;
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
        
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
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
            'is_admin' => $isAdmin,
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/finances/create-budget.html.twig',
            'page' => ['titulo' => 'Criar Orçamento'],
            'condominium' => $condominium,
            'current_year' => $currentYear,
            'selected_year' => $selectedYear,
            'existing_budget' => $existingBudget,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user(),
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function storeBudget(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/finances/create-expense.html.twig',
            'page' => ['titulo' => 'Registar Despesa'],
            'condominium' => $condominium,
            'fractions' => $fractions,
            'suppliers' => $suppliers,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function storeExpense(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        $feeMode = $_POST['fee_mode'] ?? 'annual'; // 'annual' or 'extra'

        try {
            if ($feeMode === 'annual') {
                // Annual fees generation
                $annualType = $_POST['annual_type'] ?? 'budget'; // 'budget' or 'manual'

                if ($annualType === 'budget') {
                    // Generate from approved budget
                    $generated = $this->feeService->generateAnnualFeesFromBudget($condominiumId, $year);
                    $_SESSION['success'] = count($generated) . ' quota(s) anual(is) gerada(s) automaticamente com base no orçamento para todos os 12 meses!';
                } else {
                    // Manual generation
                    $usePermillage = isset($_POST['manual_use_permillage']) && $_POST['manual_use_permillage'] === '1';
                    
                    if ($usePermillage) {
                        // Manual with permillage
                        $totalAmount = (float)($_POST['total_amount'] ?? 0);
                        if ($totalAmount <= 0) {
                            throw new \Exception('O valor total da quota anual deve ser maior que zero.');
                        }
                        $generated = $this->feeService->generateAnnualFeesManual($condominiumId, $year, $totalAmount);
                        $_SESSION['success'] = count($generated) . ' quota(s) anual(is) gerada(s) manualmente com permilagem para todos os 12 meses!';
                    } else {
                        // Manual without permillage - values per fraction
                        $fractionAmounts = $_POST['fraction_amounts'] ?? [];
                        if (empty($fractionAmounts)) {
                            throw new \Exception('Deve fornecer valores para pelo menos uma fração.');
                        }
                        
                        // Convert to proper format: fraction_id => annual_amount
                        $amounts = [];
                        foreach ($fractionAmounts as $fractionId => $amount) {
                            $fractionId = (int)$fractionId;
                            $amount = (float)$amount;
                            if ($amount > 0) {
                                $amounts[$fractionId] = $amount;
                            }
                        }
                        
                        if (empty($amounts)) {
                            throw new \Exception('Deve fornecer valores válidos para pelo menos uma fração.');
                        }
                        
                        $generated = $this->feeService->generateAnnualFeesManualPerFraction($condominiumId, $year, $amounts);
                        $_SESSION['success'] = count($generated) . ' quota(s) anual(is) gerada(s) manualmente para todos os 12 meses!';
                    }
                }
            } else {
                // Extra fees generation
                $extraUsePermillage = isset($_POST['extra_use_permillage']) && $_POST['extra_use_permillage'] === '1';
                
                // Get months array
                $months = $_POST['months'] ?? [];
                if (!is_array($months)) {
                    $month = (int)($_POST['month'] ?? date('m'));
                    $months = [$month];
                } else {
                    $months = array_filter(array_map('intval', $months), function($m) {
                        return $m >= 1 && $m <= 12;
                    });
                }

                if (empty($months)) {
                    throw new \Exception('Selecione pelo menos um mês para gerar as quotas extras.');
                }

                $extraDescription = Security::sanitize($_POST['extra_description'] ?? '');

                if ($extraUsePermillage) {
                    // Extra with permillage
                    $totalAmount = (float)($_POST['extra_total_amount'] ?? 0);
                    if ($totalAmount <= 0) {
                        throw new \Exception('O valor total da quota extra deve ser maior que zero.');
                    }
                    $generated = $this->feeService->generateExtraFeesWithPermillage(
                        $condominiumId,
                        $year,
                        $months,
                        $totalAmount,
                        $extraDescription
                    );
                    $monthCount = count($months);
                    $_SESSION['success'] = count($generated) . ' quota(s) extra(s) gerada(s) com permilagem para ' . $monthCount . ' mês(es)!';
                } else {
                    // Extra manual - values per fraction
                    $fractionAmounts = $_POST['extra_fraction_amounts'] ?? [];
                    if (empty($fractionAmounts)) {
                        throw new \Exception('Deve fornecer valores para pelo menos uma fração.');
                    }
                    
                    // Convert to proper format: fraction_id => annual_amount
                    $amounts = [];
                    foreach ($fractionAmounts as $fractionId => $amount) {
                        $fractionId = (int)$fractionId;
                        $amount = (float)$amount;
                        if ($amount > 0) {
                            $amounts[$fractionId] = $amount;
                        }
                    }
                    
                    if (empty($amounts)) {
                        throw new \Exception('Deve fornecer valores válidos para pelo menos uma fração.');
                    }
                    
                    $generated = $this->feeService->generateExtraFeesManual(
                        $condominiumId,
                        $year,
                        $months,
                        $amounts,
                        $extraDescription
                    );
                    $monthCount = count($months);
                    $_SESSION['success'] = count($generated) . ' quota(s) extra(s) gerada(s) manualmente para ' . $monthCount . ' mês(es)!';
                }
            }
            
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        } catch (\Exception $e) {
            $errorMessage = 'Erro ao gerar quotas: ' . $e->getMessage();
            
            // If error is about missing budget, store the year for link generation
            if (strpos($e->getMessage(), 'Orçamento não encontrado') !== false && strpos($e->getMessage(), 'Crie um orçamento primeiro') !== false) {
                $_SESSION['error'] = $errorMessage;
                $_SESSION['error_budget_year'] = $year; // Store year for link generation
            } else {
                $_SESSION['error'] = $errorMessage;
            }
            
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }
    }

    /**
     * Check budget status for AJAX requests
     */
    public function checkBudgetStatus(int $condominiumId, int $year)
    {
        header('Content-Type: application/json');
        
        // Check authentication
        if (!AuthMiddleware::user()) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado']);
            exit;
        }
        
        // Check condominium access
        if (!RoleMiddleware::canAccessCondominium($condominiumId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Não tem permissão para aceder a este condomínio.']);
            exit;
        }

        try {
            $budget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $year);
            $annualFeesGenerated = $this->feeModel->hasAnnualFeesForYear($condominiumId, $year);

            if (!$budget) {
                echo json_encode([
                    'exists' => false,
                    'approved' => false,
                    'annual_fees_generated' => $annualFeesGenerated,
                    'status' => null,
                    'message' => $annualFeesGenerated
                        ? 'As quotas anuais já foram geradas para este ano.'
                        : 'Orçamento não encontrado para este ano.'
                ]);
                exit;
            }

            $isApproved = in_array($budget['status'], ['approved', 'active']);

            echo json_encode([
                'exists' => true,
                'approved' => $isApproved,
                'annual_fees_generated' => $annualFeesGenerated,
                'status' => $budget['status'],
                'budget_id' => $budget['id'],
                'message' => $annualFeesGenerated 
                    ? 'As quotas anuais já foram geradas automaticamente para este ano.' 
                    : ($isApproved 
                        ? 'Orçamento aprovado. Pode gerar quotas automaticamente.' 
                        : 'Orçamento não está aprovado. Status: ' . $budget['status'])
            ]);
            exit;
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao verificar orçamento: ' . $e->getMessage()]);
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Quota não encontrada.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = Security::sanitize($_POST['payment_method'] ?? '');
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $reference = Security::sanitize($_POST['reference'] ?? '');
        $notes = Security::sanitize($_POST['notes'] ?? '');

        if ($amount <= 0 || empty($paymentMethod)) {
            $_SESSION['error'] = 'Por favor, preencha todos os campos obrigatórios.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Get current paid amount
        $totalPaid = $this->feePaymentModel->getTotalPaid($feeId);
        $remainingAmount = (float)$fee['amount'] - $totalPaid;

        if ($amount > $remainingAmount) {
            $_SESSION['error'] = 'O valor do pagamento não pode ser superior ao valor pendente (€' . number_format($remainingAmount, 2, ',', '.') . ').';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
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
                
                // Log fee payment creation
                $oldStatus = $fee['status'] ?? 'pending';
                $this->auditService->logFinancial([
                    'condominium_id' => $condominiumId,
                    'entity_type' => 'fee_payment',
                    'entity_id' => $paymentId,
                    'action' => 'fee_payment_created',
                    'user_id' => $userId,
                    'amount' => $amount,
                    'new_status' => 'completed',
                    'description' => "Pagamento de quota criado manualmente. Quota ID: {$feeId}, Valor: €" . number_format($amount, 2, ',', '.') . ", Método: {$paymentMethod}" . ($reference ? ", Referência: {$reference}" : '')
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
                $isFullyPaid = $newTotalPaid >= (float)$fee['amount'];
                
                if ($isFullyPaid) {
                    $this->feeModel->markAsPaid($feeId);
                    
                    // Log fee status change to paid
                    $this->auditService->logFinancial([
                        'condominium_id' => $condominiumId,
                        'entity_type' => 'fee',
                        'entity_id' => $feeId,
                        'action' => 'fee_marked_as_paid',
                        'user_id' => $userId,
                        'amount' => $fee['amount'],
                        'old_status' => $oldStatus,
                        'new_status' => 'paid',
                        'description' => "Quota marcada como paga após pagamento manual. Quota ID: {$feeId}, Valor total: €" . number_format($fee['amount'], 2, ',', '.') . ($fee['reference'] ? " - Referência: {$fee['reference']}" : '')
                    ]);
                }
                
                // Generate receipts
                $this->generateReceipts($feeId, $paymentId, $condominiumId, $userId, $isFullyPaid);
                
                $db->commit();
                $_SESSION['success'] = 'Pagamento registado com sucesso!';
            } catch (\Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao registar pagamento: ' . $e->getMessage();
        }

        header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
        exit;
    }

    /**
     * Get fee details with payments (for modal)
     */
    public function getFeeDetails(int $condominiumId, int $feeId)
    {
        // Set JSON header first for AJAX requests
        header('Content-Type: application/json');
        
        // Check authentication
        if (!AuthMiddleware::user()) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado']);
            exit;
        }
        
        // Check condominium access
        if (!RoleMiddleware::canAccessCondominium($condominiumId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Não tem permissão para aceder a este condomínio.']);
            exit;
        }

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            http_response_code(404);
            echo json_encode(['error' => 'Quota não encontrada']);
            exit;
        }

        // Get fraction info
        $fractionModel = new \App\Models\Fraction();
        $fraction = $fractionModel->findById($fee['fraction_id']);
        
        if (!$fraction) {
            http_response_code(404);
            echo json_encode(['error' => 'Fração não encontrada']);
            exit;
        }

        // Get all fees for the same month/fraction (regular + extra)
        $allFees = [];
        if ($fee['period_year'] && $fee['period_month']) {
            $allFees = $this->feeModel->getByMonthAndFraction(
                $condominiumId,
                (int)$fee['period_year'],
                (int)$fee['period_month'],
                (int)$fee['fraction_id']
            );
        }

        // If no fees found, use current fee as the only fee
        if (empty($allFees)) {
            $allFees = [$fee];
        }

        // Separate regular and extra fees
        $regularFee = null;
        $extraFees = []; // Array to hold all extra fees
        
        foreach ($allFees as $f) {
            $feeType = $f['fee_type'] ?? 'regular';
            if ($feeType === 'regular' || ($feeType === null && !$regularFee)) {
                $regularFee = $f;
            } elseif ($feeType === 'extra') {
                $extraFees[] = $f;
            }
        }

        // Get payments for all fees in this month/fraction
        $allPayments = [];
        $totalPaid = 0;
        $regularPaid = 0;
        $extraPaid = 0;
        
        foreach ($allFees as $f) {
            $feePayments = $this->feePaymentModel->getByFee($f['id']);
            $feePaid = $this->feePaymentModel->getTotalPaid($f['id']);
            
            // Add payments to all payments array
            foreach ($feePayments as $payment) {
                $allPayments[] = $payment;
            }
            
            // Sum paid amounts
            $totalPaid += $feePaid;
            if ($f['fee_type'] === 'extra') {
                $extraPaid += $feePaid;
            } else {
                $regularPaid += $feePaid;
            }
        }

        // If no allFees, get payments for current fee
        if (empty($allFees)) {
            $allPayments = $this->feePaymentModel->getByFee($feeId);
            $totalPaid = $this->feePaymentModel->getTotalPaid($feeId);
            if ($fee['fee_type'] === 'extra') {
                $extraPaid = $totalPaid;
            } else {
                $regularPaid = $totalPaid;
            }
        }

        // Calculate amounts
        $regularAmount = $regularFee ? (float)$regularFee['amount'] : 0;
        $extraAmount = 0;
        $extraFeesData = [];
        
        // Calculate paid amount per extra fee
        foreach ($extraFees as $extraFee) {
            $extraFeePaid = 0;
            $extraFeePayments = $this->feePaymentModel->getByFee($extraFee['id']);
            foreach ($extraFeePayments as $payment) {
                $extraFeePaid += (float)$payment['amount'];
            }
            
            $extraFeeAmount = (float)$extraFee['amount'];
            $extraAmount += $extraFeeAmount;
            
            $extraFeesData[] = [
                'id' => $extraFee['id'],
                'amount' => $extraFeeAmount,
                'paid_amount' => $extraFeePaid,
                'pending_amount' => $extraFeeAmount - $extraFeePaid,
                'due_date' => $extraFee['due_date'] ?? null,
                'reference' => $extraFee['reference'] ?? null,
                'notes' => $extraFee['notes'] ?? null,
                'description' => $extraFee['notes'] ?? 'Quota Extra'
            ];
        }
        
        $totalAmount = $regularAmount + $extraAmount;
        $pendingAmount = $totalAmount - $totalPaid;
        $regularPending = $regularAmount - $regularPaid;

        // Get receipts (for all fees)
        $receiptModel = new \App\Models\Receipt();
        $receipts = [];
        foreach ($allFees as $f) {
            $feeReceipts = $receiptModel->getByFee($f['id']);
            $receipts = array_merge($receipts, $feeReceipts);
        }
        if (empty($allFees)) {
            $receipts = $receiptModel->getByFee($feeId);
        }

        // Get payment history (for all fees)
        $historyModel = new \App\Models\FeePaymentHistory();
        $paymentHistory = [];
        foreach ($allFees as $f) {
            $feeHistory = $historyModel->getByFee($f['id']);
            $paymentHistory = array_merge($paymentHistory, $feeHistory);
        }
        if (empty($allFees)) {
            $paymentHistory = $historyModel->getByFee($feeId);
        }

        echo json_encode([
            'fee' => $fee,
            'fraction' => $fraction,
            'payments' => $allPayments,
            'receipts' => $receipts,
            'payment_history' => $paymentHistory,
            'total_amount' => $totalAmount,
            'total_paid' => $totalPaid,
            'pending_amount' => $pendingAmount,
            'regular_fee' => $regularFee ? [
                'id' => $regularFee['id'],
                'amount' => $regularAmount,
                'paid_amount' => $regularPaid,
                'pending_amount' => $regularPending,
                'due_date' => $regularFee['due_date'] ?? null,
                'reference' => $regularFee['reference'] ?? null
            ] : null,
            'extra_fees' => $extraFeesData, // Array of all extra fees
            // Keep extra_fee for backward compatibility (first extra fee if exists)
            'extra_fee' => !empty($extraFeesData) ? $extraFeesData[0] : null
        ]);
        exit;
    }

    private function feeLabelForLiquidation(array $f): string
    {
        if (($f['fee_type'] ?? '') === 'extra' && !empty($f['reference'])) {
            return 'Quota extra: ' . $f['reference'];
        }
        if (!empty($f['period_month']) && !empty($f['period_year'])) {
            return 'Quota ' . sprintf('%02d/%d', $f['period_month'], $f['period_year']);
        }
        return 'Quota ' . ($f['period_year'] ?? '');
    }

    /**
     * Generate receipts for a payment
     */
    private function generateReceipts(int $feeId, int $paymentId, int $condominiumId, int $userId, bool $isFullyPaid): void
    {
        try {
            if ($isFullyPaid) {
                (new ReceiptService())->generateForFullyPaidFee($feeId, $paymentId, $condominiumId, $userId);
            }
        } catch (\Exception $e) {
            error_log("Error generating receipt: " . $e->getMessage());
        }
    }

    /**
     * Regenerate receipts for a fee (delete existing and create new if fully paid)
     */
    private function regenerateReceiptsForFee(int $feeId, int $condominiumId, int $userId): void
    {
        try {
            $fee = $this->feeModel->findById($feeId);
            if (!$fee) {
                return;
            }

            $receiptModel = new \App\Models\Receipt();
            $existingReceipts = $receiptModel->getByFee($feeId);

            // Delete all existing receipts for this fee
            global $db;
            foreach ($existingReceipts as $receipt) {
                // Delete receipt file if exists
                if (!empty($receipt['file_path'])) {
                    $filePath = $receipt['file_path'];
                    if (strpos($filePath, 'condominiums/') === 0) {
                        $fullPath = __DIR__ . '/../../storage/' . $filePath;
                    } else {
                        $fullPath = __DIR__ . '/../../storage/documents/' . $filePath;
                    }
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
                
                // Delete receipt record
                $stmt = $db->prepare("DELETE FROM receipts WHERE id = :id");
                $stmt->execute([':id' => $receipt['id']]);
            }

            // Check if fee is still fully paid after changes
            $totalPaid = $this->feePaymentModel->getTotalPaid($feeId);
            $isFullyPaid = $totalPaid >= (float)$fee['amount'];

            // Only regenerate receipt if fully paid
            // If partially paid, receipts are deleted but not regenerated until fully paid
            if ($isFullyPaid) {
                $fractionModel = new \App\Models\Fraction();
                $fraction = $fractionModel->findById($fee['fraction_id']);
                if (!$fraction) {
                    return;
                }

                $condominium = $this->condominiumModel->findById($condominiumId);
                if (!$condominium) {
                    return;
                }

                $pdfService = new \App\Services\PdfService();
                $periodYear = (int)$fee['period_year'];
                $receiptNumber = $receiptModel->generateReceiptNumber($condominiumId, $periodYear);
                $fractionIdentifier = $fraction['identifier'] ?? '';
                $htmlContent = $pdfService->generateReceiptReceipt($fee, $fraction, $condominium, null, 'final');
                
                // Create receipt record
                $receiptId = $receiptModel->create([
                    'fee_id' => $feeId,
                    'fee_payment_id' => null,
                    'condominium_id' => $condominiumId,
                    'fraction_id' => $fee['fraction_id'],
                    'receipt_number' => $receiptNumber,
                    'receipt_type' => 'final',
                    'amount' => $fee['amount'],
                    'file_path' => '',
                    'file_name' => '',
                    'file_size' => 0,
                    'generated_at' => date('Y-m-d H:i:s'),
                    'generated_by' => $userId
                ]);

                // Generate PDF with folder structure
                $filePath = $pdfService->generateReceiptPdf($htmlContent, $receiptId, $receiptNumber, $condominiumId, $fractionIdentifier, (string)$periodYear, $userId);
                $fullPath = __DIR__ . '/../../storage/' . $filePath;
                $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
                $fileName = basename($filePath);

                // Update receipt with file info
                $stmt = $db->prepare("
                    UPDATE receipts 
                    SET file_path = :file_path, file_name = :file_name, file_size = :file_size 
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':file_path' => $filePath,
                    ':file_name' => $fileName,
                    ':file_size' => $fileSize,
                    ':id' => $receiptId
                ]);

                // Create entry in documents table
                try {
                    $receiptFolderService = new \App\Services\ReceiptFolderService();
                    $folderPath = $receiptFolderService->ensureReceiptFolders($condominiumId, (string)$periodYear, $fractionIdentifier, $userId);

                    $documentModel = new \App\Models\Document();
                    $documentModel->create([
                        'condominium_id' => $condominiumId,
                        'fraction_id' => $fee['fraction_id'],
                        'folder' => $folderPath,
                        'title' => 'Recibo ' . $receiptNumber,
                        'description' => 'Recibo regenerado para quota #' . $feeId . ' - Fração: ' . $fractionIdentifier . ' - Ano: ' . $periodYear,
                        'file_path' => $filePath,
                        'file_name' => $fileName,
                        'file_size' => $fileSize,
                        'mime_type' => 'application/pdf',
                        'visibility' => 'fraction',
                        'document_type' => 'receipt',
                        'uploaded_by' => $userId
                    ]);
                } catch (\Exception $e) {
                    // Log error but don't fail receipt generation
                    error_log("Error creating document entry for receipt: " . $e->getMessage());
                }

                // Log audit
                $auditService = new \App\Services\AuditService();
                $auditService->logDocument([
                    'condominium_id' => $condominiumId,
                    'document_type' => 'receipt',
                    'action' => 'regenerate',
                    'user_id' => $userId,
                    'receipt_id' => $receiptId,
                    'fee_id' => $feeId,
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'file_size' => $fileSize,
                    'description' => 'Recibo regenerado para quota #' . $feeId . ' - Número: ' . $receiptNumber,
                    'metadata' => [
                        'receipt_number' => $receiptNumber,
                        'receipt_type' => 'final',
                        'amount' => $fee['amount']
                    ]
                ]);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the operation
            error_log("Error regenerating receipts: " . $e->getMessage());
        }
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
        // Default to current year if no year filter is provided
        // If 'year' parameter exists but is empty, it means "Todos" was selected
        if (isset($_GET['year']) && $_GET['year'] === '') {
            $selectedYear = null; // Show all years
        } elseif (!empty($_GET['year'])) {
            $selectedYear = (int)$_GET['year'];
        } else {
            $selectedYear = $currentYear; // Default to current year
        }
        $selectedMonth = !empty($_GET['month']) ? (int)$_GET['month'] : null;
        $selectedStatus = $_GET['status'] ?? null;
        $selectedFraction = !empty($_GET['fraction_id']) ? (int)$_GET['fraction_id'] : null;
        // Check if show_historical is set and equals '1' (can be from checkbox or URL parameter)
        $showHistorical = !empty($_GET['show_historical']) && $_GET['show_historical'] == '1';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 25);
        if ($perPage < 10) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        // Get user fractions for this condominium (for non-admin users)
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        $userFractions = [];
        if (!$isAdmin) {
            $userId = AuthMiddleware::userId();
            $condominiumUserModel = new \App\Models\CondominiumUser();
            $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
            $userFractions = array_filter($userCondominiums, function($uc) use ($condominiumId) {
                return $uc['condominium_id'] == $condominiumId && !empty($uc['fraction_id']);
            });
            $userFractionIds = array_column($userFractions, 'fraction_id');
            
            // If no fraction selected and user has fractions, default to first user fraction
            if ($selectedFraction === null && !empty($userFractionIds)) {
                $selectedFraction = (int)$userFractionIds[0];
            }
            
            // If fraction selected but not in user fractions, reset to first user fraction
            if ($selectedFraction !== null && !in_array($selectedFraction, $userFractionIds)) {
                $selectedFraction = !empty($userFractionIds) ? (int)$userFractionIds[0] : null;
            }
        }

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

        // Filter by fraction if selected, or filter by user fractions if not admin
        if ($selectedFraction !== null) {
            $sql .= " AND f.fraction_id = :fraction_id";
            $params[':fraction_id'] = $selectedFraction;
        } elseif (!$isAdmin && !empty($userFractions)) {
            // Non-admin users: filter by their fractions
            $userFractionIds = array_column($userFractions, 'fraction_id');
            if (!empty($userFractionIds)) {
                $placeholders = implode(',', array_fill(0, count($userFractionIds), '?'));
                $sql .= " AND f.fraction_id IN ($placeholders)";
                foreach ($userFractionIds as $fractionId) {
                    $params[] = $fractionId;
                }
            }
        }

        // Build filter conditions
        // Handle is_historical - MySQL stores booleans as TINYINT(1): 1 for TRUE, 0 for FALSE
        if ($showHistorical) {
            // Show all historical debts + regular fees matching year/month filters
            // Historical debts always appear regardless of year/month filters
            $sql .= " AND (f.is_historical = 1";
            
            // Add regular fees condition
            if ($selectedYear || $selectedMonth !== null) {
                // Regular fees must match year/month filters
                $sql .= " OR (COALESCE(f.is_historical, 0) = 0";
                if ($selectedYear) {
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
            // selectedYear is now always set (defaults to current year), so always filter by it
            if ($selectedYear) {
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
        $allFees = $stmt->fetchAll() ?: [];
        
        // Remove duplicates by ID (in case DISTINCT doesn't work due to subquery)
        $uniqueFees = [];
        $seenIds = [];
        foreach ($allFees as $fee) {
            if (!in_array($fee['id'], $seenIds)) {
                $uniqueFees[] = $fee;
                $seenIds[] = $fee['id'];
            }
        }
        $allFees = $uniqueFees;
        $totalCount = count($allFees);
        $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 1;
        $page = min($page, max(1, $totalPages));
        $offset = ($page - 1) * $perPage;
        $fees = array_slice($allFees, $offset, $perPage);

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
        unset($fee);

        $fractionModel = new \App\Models\Fraction();
        $feeFids = array_unique(array_filter(array_column($fees, 'fraction_id')));
        $feeInfo = $fractionModel->getOwnerAndFloorByFractionIds($feeFids);
        foreach ($fees as &$fee) {
            $x = $feeInfo[(int)($fee['fraction_id'] ?? 0)] ?? [];
            $fee['owner_name'] = $x['owner_name'] ?? '';
            $fee['fraction_floor'] = $x['floor'] ?? '';
        }
        unset($fee);

        // Calculate summary (from all matching fees, not just current page)
        $summary = [
            'total' => 0,
            'paid' => 0,
            'pending' => 0,
            'overdue' => 0,
            'partial' => 0
        ];

        foreach ($allFees as $fee) {
            $totalAmount = (float)$fee['amount'];
            $paidAmount = (float)$fee['paid_amount'];
            $pendingAmount = (float)$fee['pending_amount'];
            $status = ($paidAmount >= $totalAmount) ? 'paid' : (($pendingAmount > 0 && strtotime($fee['due_date']) < time()) ? 'overdue' : (($paidAmount > 0) ? 'partial' : 'pending'));

            $summary['total'] += $totalAmount;
            $summary['paid'] += $paidAmount;
            $summary['pending'] += $pendingAmount;

            if ($status === 'overdue') {
                $summary['overdue'] += $pendingAmount;
            } elseif ($status === 'partial') {
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

        // Prepare data for fees map block
        $feeModel = new \App\Models\Fee();
        $fractionModel = new \App\Models\Fraction();
        
        // Get available years for fees map
        $availableYears = $feeModel->getAvailableYears($condominiumId);
        // Always include current year even if no fees exist yet
        if (!in_array($currentYear, $availableYears)) {
            $availableYears[] = $currentYear;
            sort($availableYears);
        }
        // Reverse to show most recent first
        rsort($availableYears);
        
        // Get selected year for fees map (check fees_year parameter first, then selectedYear from filters, then default)
        $selectedFeesYear = !empty($_GET['fees_year']) ? (int)$_GET['fees_year'] : ($selectedYear ?? $availableYears[0]);
        $selectedFeesYear = (int)$selectedFeesYear;
        
        // Get fractions for the condominium
        $fractions = $fractionModel->getByCondominiumId($condominiumId);
        $fracInfo = $fractionModel->getOwnerAndFloorByFractionIds(array_column($fractions, 'id'));
        foreach ($fractions as &$fr) {
            $x = $fracInfo[(int)($fr['id'] ?? 0)] ?? [];
            $fr['owner_name'] = $x['owner_name'] ?? '';
            $fr['floor'] = $fr['floor'] ?? $x['floor'] ?? '';
        }
        unset($fr);

        // Get fees map for selected year
        $feesMap = $feeModel->getFeesMapByYear($condominiumId, $selectedFeesYear);
        
        // Month names in Portuguese
        $monthNames = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
        ];

        $this->loadPageTranslations('finances');
        
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        // Build base query for pagination links (preserve filters)
        $queryParams = [];
        if (array_key_exists('year', $_GET)) $queryParams[] = 'year=' . urlencode((string)($_GET['year'] ?? ''));
        if (!empty($_GET['month'])) $queryParams[] = 'month=' . (int)$_GET['month'];
        if (!empty($_GET['status'])) $queryParams[] = 'status=' . urlencode($_GET['status']);
        if (!empty($_GET['fraction_id'])) $queryParams[] = 'fraction_id=' . (int)$_GET['fraction_id'];
        if (!empty($_GET['show_historical'])) $queryParams[] = 'show_historical=1';
        $queryParams[] = 'per_page=' . $perPage;
        $baseQuery = implode('&', $queryParams);

        $this->data += [
            'viewName' => 'pages/finances/fees.html.twig',
            'page' => ['titulo' => 'Quotas'],
            'condominium' => $condominium,
            'fees' => $fees,
            'summary' => $summary,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'per_page' => $perPage,
                'from' => $totalCount > 0 ? $offset + 1 : 0,
                'to' => $totalCount > 0 ? min($offset + $perPage, $totalCount) : 0,
                'base_query' => $baseQuery
            ],
            'current_year' => $currentYear,
            'current_month' => $currentMonth,
            'selected_year' => $selectedYear,
            'selected_month' => $selectedMonth,
            'selected_status' => $selectedStatus,
            'selected_fraction' => $selectedFraction,
            'user_fractions' => $userFractions,
            'user_fraction_ids' => !empty($userFractions) ? array_column($userFractions, 'fraction_id') : [],
            'show_historical' => $showHistorical,
            'fractions' => $fractions,
            'bank_accounts' => $bankAccounts,
            'available_transactions' => $availableTransactions,
            'is_admin' => $isAdmin,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'error_budget_year' => $_SESSION['error_budget_year'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'user' => AuthMiddleware::user(),
            // Variables for fees map block
            'fees_map' => $feesMap,
            'fractions' => $fractions,
            'available_years' => $availableYears,
            'selected_fees_year' => $selectedFeesYear,
            'fees_map_form_action' => BASE_URL . 'condominiums/' . $condominiumId . '/fees',
            'month_names' => $monthNames,
            'available_filter_years' => $availableYears // Years available for filtering
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['error_budget_year']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Liquidar quotas: regista um pagamento na conta da fração e aplica às quotas em atraso.
     * Equivalente a criar um movimento financeiro de entrada Quotas para a fração.
     */
    public function createLiquidateQuotas(int $condominiumId)
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

        // Get fractions for dropdown
        $fractionModel = new \App\Models\Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        // Get bank accounts for dropdown
        $bankAccountModel = new BankAccount();
        $bankAccounts = $bankAccountModel->getActiveAccounts($condominiumId);

        $this->loadPageTranslations('finances');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/finances/liquidate-quotas.html.twig',
            'page' => ['titulo' => 'Liquidar Quotas'],
            'condominium' => $condominium,
            'fractions' => $fractions,
            'bank_accounts' => $bankAccounts,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function liquidateQuotas(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees/liquidate');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $fractionId = (int)($_POST['fraction_id'] ?? 0);
        $amount = !empty($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $paymentMethod = Security::sanitize($_POST['payment_method'] ?? '');
        $bankAccountId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $notes = Security::sanitize($_POST['notes'] ?? '');
        $useFractionBalance = !empty($_POST['use_fraction_balance']);
        $selectedFeeIds = !empty($_POST['selected_fee_ids']) ? array_map('intval', $_POST['selected_fee_ids']) : [];

        if ($fractionId <= 0) {
            $_SESSION['error'] = 'Selecione a fração.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees/liquidate');
            exit;
        }
        
        // If using only fraction balance (no new payment), skip payment validations
        if (!$useFractionBalance) {
            if ($amount <= 0) {
                $_SESSION['error'] = 'O valor deve ser maior que zero ou selecione "Usar apenas saldo da fração".';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees/liquidate');
                exit;
            }
            if (!in_array($paymentMethod, ['multibanco', 'mbway', 'transfer', 'cash', 'card', 'sepa'])) {
                $_SESSION['error'] = 'Método de pagamento inválido.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees/liquidate');
                exit;
            }
            if ($bankAccountId <= 0) {
                $_SESSION['error'] = 'Selecione a conta bancária.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees/liquidate');
                exit;
            }

            $bankAccountModel = new BankAccount();
            $account = $bankAccountModel->findById($bankAccountId);
            if (!$account || $account['condominium_id'] != $condominiumId) {
                $_SESSION['error'] = 'Conta bancária inválida.';
                header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
                exit;
            }
        } else {
            // Using only balance - validate that balance exists
            $faModel = new FractionAccount();
            $fa = $faModel->getOrCreate($fractionId, $condominiumId);
            $existingBalance = (float)($fa['balance'] ?? 0.0);
            if ($existingBalance <= 0) {
                $_SESSION['error'] = 'A fração não tem saldo disponível.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees/liquidate');
                exit;
            }
        }

        $fractionModel = new Fraction();
        $fraction = $fractionModel->findById($fractionId);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fração inválida.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        try {
            global $db;
            $db->beginTransaction();
            $userId = AuthMiddleware::userId();

            $faModel = new FractionAccount();
            $fa = $faModel->getOrCreate($fractionId, $condominiumId);
            $accountId = (int)$fa['id'];
            $existingBalance = (float)($fa['balance'] ?? 0.0);
            
            $transactionId = null;
            $movementId = null;
            
            // If using only balance, ignore amount and use only existing balance
            if ($useFractionBalance) {
                // Force amount to 0 when using only balance
                $amount = 0;
                // Only apply existing balance to quotas - no new transaction
                $description = 'Liquidação de quotas usando saldo da fração - Fração ' . $fraction['identifier'];
                if ($notes) {
                    $description .= ' - ' . $notes;
                }
                
                // Verify balance exists before liquidating
                if ($existingBalance <= 0) {
                    throw new \Exception('A fração não tem saldo disponível para liquidar quotas.');
                }
                
                // Use liquidateSelectedFees if fees are selected, otherwise use liquidate
                $liquidationService = new LiquidationService();
                if (!empty($selectedFeeIds)) {
                    $result = $liquidationService->liquidateSelectedFees($fractionId, $selectedFeeIds, $userId, $paymentDate, null);
                } else {
                    $result = $liquidationService->liquidate($fractionId, $userId, $paymentDate, null);
                }
                
                // Check if any quotas were liquidated
                $fullyPaidCount = count($result['fully_paid'] ?? []);
                $partiallyPaidCount = count($result['partially_paid'] ?? []);
                
                if ($fullyPaidCount == 0 && $partiallyPaidCount == 0) {
                    throw new \Exception('Não foram encontradas quotas pendentes para liquidar ou o saldo não é suficiente.');
                }
            } else {
                // Create new transaction and add credit
                $description = 'Liquidação de quotas - Fração ' . $fraction['identifier'];
                if ($notes) {
                    $description .= ' - ' . $notes;
                }

                // Generate reference automatically
                $ref = 'REF' . $condominiumId . $fractionId . date('YmdHis');

                $transactionModel = new FinancialTransaction();
                $transactionId = $transactionModel->create([
                    'condominium_id' => $condominiumId,
                    'bank_account_id' => $bankAccountId,
                    'fraction_id' => $fractionId,
                    'transaction_type' => 'income',
                    'amount' => $amount,
                    'transaction_date' => $paymentDate,
                    'description' => $description,
                    'category' => 'Quotas',
                    'income_entry_type' => 'quota',
                    'reference' => $ref,
                    'related_type' => 'fraction_account',
                    'related_id' => $accountId,
                    'transfer_to_account_id' => null,
                    'created_by' => $userId
                ]);

                $movementId = $faModel->addCredit($accountId, $amount, 'quota_payment', $transactionId, $description);
                
                // Liquidate with new credit + existing balance (if using both)
                // Use liquidateSelectedFees if fees are selected, otherwise use liquidate
                $liquidationService = new LiquidationService();
                if (!empty($selectedFeeIds)) {
                    $result = $liquidationService->liquidateSelectedFees($fractionId, $selectedFeeIds, $userId, $paymentDate, $transactionId);
                } else {
                    $result = $liquidationService->liquidate($fractionId, $userId, $paymentDate, $transactionId);
                }
            }

            $parts = [];
            foreach ($result['fully_paid'] ?? [] as $fid) {
                $f = $this->feeModel->findById($fid);
                $parts[] = $f ? $this->feeLabelForLiquidation($f) : ('Quota #' . $fid);
            }
            foreach (array_keys($result['partially_paid'] ?? []) as $fid) {
                $f = $this->feeModel->findById($fid);
                $parts[] = ($f ? $this->feeLabelForLiquidation($f) : ('Quota #' . $fid)) . ' (parcial)';
            }
            $builtDesc = implode(', ', $parts);
            
            // Update transaction/movement description if transaction was created
            if ($transactionId && $builtDesc !== '') {
                $transactionModel = new FinancialTransaction();
                $transactionModel->update($transactionId, ['description' => $builtDesc]);
                if ($movementId) {
                    (new FractionAccountMovement())->update($movementId, ['description' => $builtDesc]);
                }
            }

            // Log liquidation operation
            $fullyPaidCount = count($result['fully_paid'] ?? []);
            $partiallyPaidCount = count($result['partially_paid'] ?? []);
            
            if ($useFractionBalance) {
                // Using only balance
                $usedBalance = $existingBalance - ($result['credit_remaining'] ?? $existingBalance);
                $auditDescription = "Liquidação de quotas executada para fração {$fraction['identifier']} usando apenas saldo da fração. Saldo utilizado: €" . number_format($usedBalance, 2, ',', '.') . ". Quotas totalmente pagas: {$fullyPaidCount}, Pagamentos parciais: {$partiallyPaidCount}";
            } else {
                // Using new payment + optionally balance
                $auditDescription = "Liquidação de quotas executada para fração {$fraction['identifier']}. Valor: €" . number_format($amount, 2, ',', '.');
                if ($useFractionBalance && $existingBalance > 0) {
                    $usedBalance = $existingBalance - ($result['credit_remaining'] ?? $existingBalance);
                    $auditDescription .= ", Saldo da fração também utilizado: €" . number_format($usedBalance, 2, ',', '.');
                }
                $auditDescription .= ". Quotas totalmente pagas: {$fullyPaidCount}, Pagamentos parciais: {$partiallyPaidCount}. Método: {$paymentMethod}";
            }
            
            $this->auditService->logFinancial([
                'condominium_id' => $condominiumId,
                'entity_type' => $transactionId ? 'financial_transaction' : 'fraction_account',
                'entity_id' => $transactionId ?: $accountId,
                'action' => 'quotas_liquidated',
                'user_id' => $userId,
                'amount' => $amount > 0 ? $amount : ($existingBalance - ($result['credit_remaining'] ?? 0)),
                'new_status' => 'completed',
                'description' => $auditDescription
            ]);

            $receiptSvc = new ReceiptService();
            foreach ($result['fully_paid'] ?? [] as $fid) {
                $receiptSvc->generateForFullyPaidFee($fid, $result['fully_paid_payments'][$fid] ?? null, $condominiumId, $userId);
            }

            $db->commit();
            if ($useFractionBalance) {
                $fullyPaidCount = count($result['fully_paid'] ?? []);
                $partiallyPaidCount = count($result['partially_paid'] ?? []);
                $usedBalance = $existingBalance - ($result['credit_remaining'] ?? $existingBalance);
                $_SESSION['success'] = 'Saldo da fração (€' . number_format($usedBalance, 2, ',', '.') . ') aplicado às quotas em atraso da fração ' . $fraction['identifier'] . '. ' . $fullyPaidCount . ' quota(s) totalmente paga(s), ' . $partiallyPaidCount . ' parcialmente paga(s).';
            } else {
                $_SESSION['success'] = 'Pagamento registado. O valor foi aplicado às quotas em atraso da fração ' . $fraction['identifier'] . '.';
            }
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        } catch (\Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $_SESSION['error'] = 'Erro ao liquidar quotas: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees/liquidate');
            exit;
        }
    }

    /**
     * Lista contas por fração: frações com saldo corrente.
     */
    public function fractionAccounts(int $condominiumId)
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
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');

        $fractionModel = new Fraction();
        $faModel = new FractionAccount();
        $allFractions = $fractionModel->getByCondominiumId($condominiumId);
        $accounts = $faModel->getByCondominium($condominiumId);
        $byFraction = [];
        foreach ($accounts as $a) {
            $byFraction[(int)$a['fraction_id']] = $a;
        }

        // Condómino: filtrar pelas suas frações
        if (!$isAdmin) {
            $cuModel = new \App\Models\CondominiumUser();
            $ucs = $cuModel->getUserCondominiums($userId);
            $userFractionIds = array_filter(array_unique(array_column(
                array_filter($ucs, fn($u) => (int)($u['condominium_id'] ?? 0) === $condominiumId && !empty($u['fraction_id'])),
                'fraction_id'
            )));
            $allFractions = array_filter($allFractions, fn($f) => in_array((int)$f['id'], $userFractionIds));
        }

        $rows = [];
        foreach ($allFractions as $f) {
            $fid = (int)$f['id'];
            $acc = $byFraction[$fid] ?? null;
            $bal = $acc ? (float)$acc['balance'] : 0.0;
            $due = $this->feeModel->getTotalDueByFraction($fid);
            $rows[] = [
                'fraction' => $f,
                'fraction_id' => $fid,
                'balance' => $bal,
                'due' => $due,
                'situacao' => $bal - $due,
                'fraction_account_id' => $acc ? (int)$acc['id'] : null
            ];
        }
        $info = $fractionModel->getOwnerAndFloorByFractionIds(array_column($rows, 'fraction_id'));
        foreach ($rows as &$r) {
            $x = $info[$r['fraction_id']] ?? [];
            $r['owner_name'] = $x['owner_name'] ?? '';
            $r['floor'] = $x['floor'] ?? '';
        }
        unset($r);

        $this->loadPageTranslations('finances');
        $this->data += [
            'viewName' => 'pages/finances/fraction-accounts/index.html.twig',
            'page' => ['titulo' => 'Contas por fração'],
            'condominium' => $condominium,
            'rows' => $rows,
            'is_admin' => $isAdmin,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        unset($_SESSION['error'], $_SESSION['success']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Detalhe da conta de uma fração: pagamentos (créditos), quotas liquidadas (débitos) e saldo.
     */
    public function fractionAccountShow(int $condominiumId, int $fractionId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $fractionModel = new Fraction();
        $fraction = $fractionModel->findById($fractionId);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fração não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fraction-accounts');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        if (!$isAdmin) {
            $cuModel = new \App\Models\CondominiumUser();
            $ucs = $cuModel->getUserCondominiums($userId);
            $allowed = in_array($fractionId, array_filter(array_unique(array_column(
                array_filter($ucs, fn($u) => (int)($u['condominium_id'] ?? 0) === $condominiumId && !empty($u['fraction_id'])),
                'fraction_id'
            ))));
            if (!$allowed) {
                $_SESSION['error'] = 'Sem acesso a esta fração.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fraction-accounts');
                exit;
            }
        }

        $faModel = new FractionAccount();
        $account = $faModel->getByFraction($fractionId);
        $balance = $account ? (float)$account['balance'] : 0.0;
        $fractionAccountId = $account ? (int)$account['id'] : null;
        $emFalta = $this->feeModel->getTotalDueByFraction($fractionId);
        $feesEmFalta = $this->feeModel->getOutstandingByFraction($fractionId);

        $movements = [];
        $quotaDebitFeeIds = [];
        if ($fractionAccountId) {
            $movementModel = new \App\Models\FractionAccountMovement();
            $raw = $movementModel->getByFractionAccountWithFeeInfo($fractionAccountId);
            foreach ($raw as $m) {
                $q = $m['description'] ?? '';
                if (($m['fee_type'] ?? '') === 'extra' && !empty($m['fee_reference'])) {
                    $q = 'Quota extra: ' . $m['fee_reference'];
                } elseif (!empty($m['fee_period_year'])) {
                    $q = 'Quota ' . (isset($m['fee_period_month']) && $m['fee_period_month'] ? sprintf('%02d/%d', $m['fee_period_month'], $m['fee_period_year']) : $m['fee_period_year']);
                }
                $isQuotaDebit = ($m['type'] ?? '') === 'debit' && ($m['source_type'] ?? '') === 'quota_application';
                $pid = $isQuotaDebit ? (int)($m['source_reference_id'] ?? 0) : 0;
                $ftId = !empty($m['ft_id']) ? (int)$m['ft_id'] : null;
                $pref = null;
                if ($isQuotaDebit) {
                    if (!empty($m['ft_reference'])) {
                        $pref = trim((string)$m['ft_reference']);
                    } elseif (!empty($m['fee_payment_reference'])) {
                        $pref = trim((string)$m['fee_payment_reference']);
                    } elseif ($pid) {
                        $pref = '#' . $pid;
                    }
                }
                $feeAmt = (float)($m['fee_amount'] ?? 0);
                $feePaid = (float)($m['fee_total_paid'] ?? 0);
                $isPartial = $isQuotaDebit && $feeAmt > 0 && ($feeAmt - $feePaid) > 0;
                if ($isQuotaDebit && !empty($m['fee_id'])) {
                    $quotaDebitFeeIds[(int)$m['fee_id']] = true;
                }
                $movements[] = [
                    'id' => $m['id'],
                    'type' => $m['type'],
                    'amount' => (float)$m['amount'],
                    'source_type' => $m['source_type'] ?? null,
                    'description' => $m['description'] ?? null,
                    'created_at' => $m['created_at'] ?? null,
                    'quota_label' => $q,
                    'fee_id' => $isQuotaDebit ? (int)($m['fee_id'] ?? 0) : null,
                    'fee_payment_id' => $pid ?: null,
                    'financial_transaction_id' => $ftId,
                    'payment_reference' => $pref,
                    'is_partial' => $isPartial
                ];
            }
            $refsMap = $this->feeModel->getPaymentRefsByFeeIds(array_keys($quotaDebitFeeIds));
            foreach ($movements as &$mov) {
                $fid = $mov['fee_id'] ?? 0;
                $mov['payment_refs'] = $fid ? ($refsMap[$fid] ?? []) : [];
            }
            unset($mov);
        }

        $refsByFee = $this->feeModel->getPaymentRefsByFeeIds(array_map(fn($x) => (int)($x['id'] ?? 0), $feesEmFalta));
        foreach ($feesEmFalta as &$q) {
            $q['payment_refs'] = array_map(fn($x) => $x['ref'], $refsByFee[(int)($q['id'] ?? 0)] ?? []);
        }
        unset($q);

        $this->loadPageTranslations('finances');
        $this->data += [
            'viewName' => 'pages/finances/fraction-accounts/show.html.twig',
            'page' => ['titulo' => 'Conta da fração ' . $fraction['identifier']],
            'condominium' => $condominium,
            'fraction' => $fraction,
            'balance' => $balance,
            'em_falta' => $emFalta,
            'situacao' => $balance - $emFalta,
            'fees_em_falta' => $feesEmFalta,
            'movements' => $movements,
            'is_admin' => $isAdmin,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        unset($_SESSION['error'], $_SESSION['success']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * JSON: dados do pagamento (fee_payment) para mostrar em modal na conta da fração.
     * Acesso: admin ou condómino com a fração.
     */
    public function getFractionAccountPaymentInfo(int $condominiumId, int $fractionId, int $paymentId)
    {
        header('Content-Type: application/json');
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            http_response_code(404);
            echo json_encode(['error' => 'Condomínio não encontrado']);
            exit;
        }
        $fractionModel = new \App\Models\Fraction();
        $fraction = $fractionModel->findById($fractionId);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            http_response_code(404);
            echo json_encode(['error' => 'Fração não encontrada']);
            exit;
        }
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        if (!$isAdmin) {
            $cuModel = new \App\Models\CondominiumUser();
            $ucs = $cuModel->getUserCondominiums($userId);
            $allowed = in_array($fractionId, array_filter(array_unique(array_column(
                array_filter($ucs, fn($u) => (int)($u['condominium_id'] ?? 0) === $condominiumId && !empty($u['fraction_id'])),
                'fraction_id'
            ))));
            if (!$allowed) {
                http_response_code(403);
                echo json_encode(['error' => 'Sem acesso a esta fração']);
                exit;
            }
        }

        $payment = $this->feePaymentModel->findById($paymentId);
        if (!$payment) {
            http_response_code(404);
            echo json_encode(['error' => 'Pagamento não encontrado']);
            exit;
        }
        $fee = $this->feeModel->findById($payment['fee_id']);
        if (!$fee || $fee['fraction_id'] != $fractionId || $fee['condominium_id'] != $condominiumId) {
            http_response_code(404);
            echo json_encode(['error' => 'Pagamento não encontrado']);
            exit;
        }

        $quotaLabel = 'Quota ';
        if (($fee['fee_type'] ?? '') === 'extra' && !empty($fee['reference'])) {
            $quotaLabel .= 'extra: ' . $fee['reference'];
        } elseif (!empty($fee['period_month']) && !empty($fee['period_year'])) {
            $quotaLabel .= sprintf('%02d/%d', $fee['period_month'], $fee['period_year']);
        } else {
            $quotaLabel .= $fee['period_year'] ?? '';
        }

        echo json_encode([
            'payment' => $payment,
            'fee' => $fee,
            'quota_label' => $quotaLabel
        ]);
        exit;
    }

    public function markFeeAsPaid(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        if ($this->feeModel->markAsPaid($id)) {
            $_SESSION['success'] = 'Quota marcada como paga!';
        } else {
            $_SESSION['error'] = 'Erro ao marcar quota como paga.';
        }

        header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
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
                AND COALESCE(f.is_historical, 0) = 1
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
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
            'is_admin' => $isAdmin,
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/finances/create-revenue.html.twig',
            'page' => ['titulo' => 'Registar Receita'],
            'condominium' => $condominium,
            'fractions' => $fractions,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/finances/edit-revenue.html.twig',
            'page' => ['titulo' => 'Editar Receita'],
            'condominium' => $condominium,
            'revenue' => $revenue,
            'fractions' => $fractions,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $error,
            'success' => $success
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $feeIds = $_POST['fee_ids'] ?? [];
        
        if (empty($feeIds)) {
            $_SESSION['error'] = 'Nenhuma quota selecionada.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $successCount = 0;
        $errorCount = 0;
        $userId = AuthMiddleware::userId();

        foreach ($feeIds as $feeId) {
            $fee = $this->feeModel->findById((int)$feeId);
            if ($fee && $fee['condominium_id'] == $condominiumId) {
                $oldStatus = $fee['status'] ?? 'pending';
                if ($this->feeModel->markAsPaid((int)$feeId)) {
                    // Log fee status change to paid
                    $this->auditService->logFinancial([
                        'condominium_id' => $condominiumId,
                        'entity_type' => 'fee',
                        'entity_id' => (int)$feeId,
                        'action' => 'fee_marked_as_paid_bulk',
                        'user_id' => $userId,
                        'amount' => $fee['amount'],
                        'old_status' => $oldStatus,
                        'new_status' => 'paid',
                        'description' => "Quota marcada como paga em lote. Quota ID: {$feeId}, Valor: €" . number_format($fee['amount'], 2, ',', '.') . ($fee['reference'] ? " - Referência: {$fee['reference']}" : '')
                    ]);
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

        header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
        exit;
    }

    /**
     * Show edit form for extra fee
     */
    public function editFee(int $condominiumId, int $feeId)
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

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Quota não encontrada.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Only allow editing extra fees
        if (($fee['fee_type'] ?? 'regular') !== 'extra') {
            $_SESSION['error'] = 'Apenas quotas extras podem ser editadas.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Check if fee has payments
        $totalPaid = $this->feePaymentModel->getTotalPaid($feeId);
        if ($totalPaid > 0) {
            $_SESSION['error'] = 'Não é possível editar uma quota que já possui pagamentos registados.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Get fraction info
        $fractionModel = new \App\Models\Fraction();
        $fraction = $fractionModel->findById($fee['fraction_id']);
        
        if (!$fraction) {
            $_SESSION['error'] = 'Fração não encontrada.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $this->loadPageTranslations('finances');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/finances/edit-fee.html.twig',
            'page' => ['titulo' => 'Editar Quota Extra'],
            'condominium' => $condominium,
            'fee' => $fee,
            'fraction' => $fraction,
            'csrf_token' => Security::generateCSRFToken(),
            'user' => AuthMiddleware::user(),
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Update extra fee
     */
    public function updateFee(int $condominiumId, int $feeId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Quota não encontrada.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Only allow editing extra fees
        if (($fee['fee_type'] ?? 'regular') !== 'extra') {
            $_SESSION['error'] = 'Apenas quotas extras podem ser editadas.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Check if fee has payments
        $totalPaid = $this->feePaymentModel->getTotalPaid($feeId);
        if ($totalPaid > 0) {
            $_SESSION['error'] = 'Não é possível editar uma quota que já possui pagamentos registados.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Get and validate input
        $amount = (float)($_POST['amount'] ?? 0);
        $description = Security::sanitize($_POST['description'] ?? '');
        $dueDate = $_POST['due_date'] ?? $fee['due_date'];

        if ($amount <= 0) {
            $_SESSION['error'] = 'O valor da quota deve ser maior que zero.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/fees/' . $feeId . '/edit');
            exit;
        }

        // Update fee
        global $db;
        $stmt = $db->prepare("
            UPDATE fees 
            SET amount = :amount, 
                base_amount = :base_amount,
                due_date = :due_date,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ");

        $success = $stmt->execute([
            ':amount' => round($amount, 2),
            ':base_amount' => round($amount, 2),
            ':due_date' => $dueDate,
            ':notes' => $description,
            ':id' => $feeId
        ]);

        if ($success) {
            $_SESSION['success'] = 'Quota extra atualizada com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao atualizar a quota extra.';
        }

        header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
        exit;
    }

    /**
     * Delete extra fee
     */
    public function deleteFee(int $condominiumId, int $feeId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        // Check if this is an AJAX request
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(405);
                echo json_encode(['error' => 'Método não permitido']);
                exit;
            }
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['error' => 'Token de segurança inválido.']);
                exit;
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(404);
                echo json_encode(['error' => 'Quota não encontrada.']);
                exit;
            }
            $_SESSION['error'] = 'Quota não encontrada.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Only allow deleting extra fees
        if (($fee['fee_type'] ?? 'regular') !== 'extra') {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['error' => 'Apenas quotas extras podem ser eliminadas.']);
                exit;
            }
            $_SESSION['error'] = 'Apenas quotas extras podem ser eliminadas.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Check if fee has payments
        $totalPaid = $this->feePaymentModel->getTotalPaid($feeId);
        if ($totalPaid > 0) {
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['error' => 'Não é possível eliminar uma quota que já possui pagamentos registados.']);
                exit;
            }
            $_SESSION['error'] = 'Não é possível eliminar uma quota que já possui pagamentos registados.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Delete fee
        global $db;
        $stmt = $db->prepare("DELETE FROM fees WHERE id = :id");
        $success = $stmt->execute([':id' => $feeId]);

        if ($isAjax) {
            header('Content-Type: application/json');
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Quota extra eliminada com sucesso!']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao eliminar a quota extra.']);
            }
            exit;
        }

        if ($success) {
            $_SESSION['success'] = 'Quota extra eliminada com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao eliminar a quota extra.';
        }

        header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
        exit;
    }

    /**
     * Get payment details for editing
     */
    public function getPayment(int $condominiumId, int $feeId, int $paymentId)
    {
        header('Content-Type: application/json');
        
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            http_response_code(404);
            echo json_encode(['error' => 'Quota não encontrada']);
            exit;
        }

        $payment = $this->feePaymentModel->findById($paymentId);
        if (!$payment || $payment['fee_id'] != $feeId) {
            http_response_code(404);
            echo json_encode(['error' => 'Pagamento não encontrado']);
            exit;
        }

        echo json_encode(['payment' => $payment]);
        exit;
    }

    /**
     * Update payment
     */
    public function updatePayment(int $condominiumId, int $feeId, int $paymentId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Quota não encontrada.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $payment = $this->feePaymentModel->findById($paymentId);
        if (!$payment || $payment['fee_id'] != $feeId) {
            $_SESSION['error'] = 'Pagamento não encontrado.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = Security::sanitize($_POST['payment_method'] ?? '');
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $notes = Security::sanitize($_POST['notes'] ?? '');

        if ($amount <= 0 || empty($paymentMethod)) {
            $_SESSION['error'] = 'Por favor, preencha todos os campos obrigatórios.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Get current total paid (excluding this payment)
        $totalPaid = $this->feePaymentModel->getTotalPaid($feeId) - $payment['amount'];
        $remainingAmount = (float)$fee['amount'] - $totalPaid;

        if ($amount > $remainingAmount) {
            $_SESSION['error'] = 'O valor do pagamento não pode ser superior ao valor pendente (€' . number_format($remainingAmount, 2, ',', '.') . ').';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Track changes for audit
        $changes = [];
        if ((float)$payment['amount'] != $amount) {
            $changes['amount'] = [
                'old' => '€' . number_format((float)$payment['amount'], 2, ',', '.'),
                'new' => '€' . number_format($amount, 2, ',', '.')
            ];
        }
        if ($payment['payment_method'] != $paymentMethod) {
            $changes['payment_method'] = [
                'old' => $payment['payment_method'],
                'new' => $paymentMethod
            ];
        }
        if ($payment['payment_date'] != $paymentDate) {
            $changes['payment_date'] = [
                'old' => $payment['payment_date'],
                'new' => $paymentDate
            ];
        }
        if (($payment['notes'] ?? '') != $notes) {
            $changes['notes'] = [
                'old' => $payment['notes'] ?? '',
                'new' => $notes
            ];
        }

        // Update payment
        $success = $this->feePaymentModel->update($paymentId, [
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'payment_date' => $paymentDate,
            'notes' => $notes
        ]);

        if ($success) {
            // Log changes to history
            if (!empty($changes)) {
                $historyModel = new \App\Models\FeePaymentHistory();
                $userId = AuthMiddleware::userId();
                $historyModel->logUpdate($paymentId, $feeId, $userId, $changes);
            }
            
            // Regenerate receipts for this fee
            $userId = AuthMiddleware::userId();
            $this->regenerateReceiptsForFee($feeId, $condominiumId, $userId);
            
            // Log payment update
            if (!empty($changes)) {
                $this->auditService->logFinancial([
                    'condominium_id' => $condominiumId,
                    'entity_type' => 'fee_payment',
                    'entity_id' => $paymentId,
                    'action' => 'fee_payment_updated',
                    'user_id' => $userId,
                    'amount' => $amount,
                    'old_amount' => $payment['amount'],
                    'description' => "Pagamento de quota atualizado. Quota ID: {$feeId}, Pagamento ID: {$paymentId}. Alterações: " . json_encode($changes)
                ]);
            }
            
            // Update fee status based on new total paid
            $oldFeeStatus = $fee['status'] ?? 'pending';
            $newTotalPaid = $this->feePaymentModel->getTotalPaid($feeId);
            $isFullyPaid = $newTotalPaid >= (float)$fee['amount'];
            if ($isFullyPaid) {
                $this->feeModel->markAsPaid($feeId);
                
                // Log fee status change to paid
                $this->auditService->logFinancial([
                    'condominium_id' => $condominiumId,
                    'entity_type' => 'fee',
                    'entity_id' => $feeId,
                    'action' => 'fee_marked_as_paid',
                    'user_id' => $userId,
                    'amount' => $fee['amount'],
                    'old_status' => $oldFeeStatus,
                    'new_status' => 'paid',
                    'description' => "Quota marcada como paga após atualização de pagamento. Quota ID: {$feeId}, Valor total: €" . number_format($fee['amount'], 2, ',', '.')
                ]);
            } else {
                // If not fully paid, mark as pending
                global $db;
                $stmt = $db->prepare("UPDATE fees SET status = 'pending', updated_at = NOW() WHERE id = :id");
                $stmt->execute([':id' => $feeId]);
                
                // Log status change back to pending if it was paid before
                if ($oldFeeStatus === 'paid') {
                    $this->auditService->logFinancial([
                        'condominium_id' => $condominiumId,
                        'entity_type' => 'fee',
                        'entity_id' => $feeId,
                        'action' => 'fee_status_changed',
                        'user_id' => $userId,
                        'amount' => $fee['amount'],
                        'old_status' => $oldFeeStatus,
                        'new_status' => 'pending',
                        'description' => "Status da quota alterado para pendente após atualização de pagamento. Quota ID: {$feeId}"
                    ]);
                }
            }
            
            $_SESSION['success'] = 'Pagamento atualizado com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao atualizar o pagamento.';
        }

        header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
        exit;
    }

    /**
     * Delete payment
     */
    public function deletePayment(int $condominiumId, int $feeId, int $paymentId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $fee = $this->feeModel->findById($feeId);
        if (!$fee || $fee['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Quota não encontrada.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        $payment = $this->feePaymentModel->findById($paymentId);
        if (!$payment || $payment['fee_id'] != $feeId) {
            $_SESSION['error'] = 'Pagamento não encontrado.';
            header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
            exit;
        }

        // Log deletion to history BEFORE deleting
        $historyModel = new \App\Models\FeePaymentHistory();
        $userId = AuthMiddleware::userId();
        $historyModel->logDeletion($paymentId, $feeId, $userId, $payment, 
            'Pagamento eliminado: €' . number_format((float)$payment['amount'], 2, ',', '.') . 
            ' (' . $payment['payment_method'] . ') - Referência: ' . ($payment['reference'] ?? 'N/A'));

        // Log payment deletion
        $oldFeeStatus = $fee['status'] ?? 'pending';
        $this->auditService->logFinancial([
            'condominium_id' => $condominiumId,
            'entity_type' => 'fee_payment',
            'entity_id' => $paymentId,
            'action' => 'fee_payment_deleted',
            'user_id' => $userId,
            'amount' => $payment['amount'],
            'old_status' => 'completed',
            'new_status' => 'deleted',
            'description' => "Pagamento de quota eliminado. Quota ID: {$feeId}, Pagamento ID: {$paymentId}, Valor: €" . number_format((float)$payment['amount'], 2, ',', '.') . ", Método: {$payment['payment_method']}"
        ]);

        // Delete payment
        $success = $this->feePaymentModel->delete($paymentId);

        if ($success) {
            // Regenerate receipts for this fee (will delete existing and create new if fully paid)
            $this->regenerateReceiptsForFee($feeId, $condominiumId, $userId);
            
            // Update fee status based on new total paid
            $newTotalPaid = $this->feePaymentModel->getTotalPaid($feeId);
            $isFullyPaid = $newTotalPaid >= (float)$fee['amount'];
            if ($isFullyPaid) {
                $this->feeModel->markAsPaid($feeId);
                
                // Log fee status change to paid
                $this->auditService->logFinancial([
                    'condominium_id' => $condominiumId,
                    'entity_type' => 'fee',
                    'entity_id' => $feeId,
                    'action' => 'fee_marked_as_paid',
                    'user_id' => $userId,
                    'amount' => $fee['amount'],
                    'old_status' => $oldFeeStatus,
                    'new_status' => 'paid',
                    'description' => "Quota marcada como paga após eliminação de outro pagamento. Quota ID: {$feeId}"
                ]);
            } else {
                // If not fully paid, mark as pending
                global $db;
                $stmt = $db->prepare("UPDATE fees SET status = 'pending', updated_at = NOW() WHERE id = :id");
                $stmt->execute([':id' => $feeId]);
                
                // Log status change back to pending if it was paid before
                if ($oldFeeStatus === 'paid') {
                    $this->auditService->logFinancial([
                        'condominium_id' => $condominiumId,
                        'entity_type' => 'fee',
                        'entity_id' => $feeId,
                        'action' => 'fee_status_changed',
                        'user_id' => $userId,
                        'amount' => $fee['amount'],
                        'old_status' => $oldFeeStatus,
                        'new_status' => 'pending',
                        'description' => "Status da quota alterado para pendente após eliminação de pagamento. Quota ID: {$feeId}"
                    ]);
                }
            }
            
            $_SESSION['success'] = 'Pagamento eliminado com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao eliminar o pagamento.';
        }

        header('Location: ' . $this->buildFeesRedirectUrl($condominiumId));
        exit;
    }
}

