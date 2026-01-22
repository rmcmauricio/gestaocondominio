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
use App\Models\Fraction;
use App\Models\FractionAccount;
use App\Models\FractionAccountMovement;
use App\Services\LiquidationService;
use App\Services\ReceiptService;
use App\Services\AuditService;

class FinancialTransactionController extends Controller
{
    protected $transactionModel;
    protected $bankAccountModel;
    protected $condominiumModel;
    protected $feeModel;
    protected $auditService;

    public function __construct()
    {
        parent::__construct();
        $this->transactionModel = new FinancialTransaction();
        $this->bankAccountModel = new BankAccount();
        $this->condominiumModel = new Condominium();
        $this->feeModel = new Fee();
        $this->auditService = new AuditService();
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
        // We need to calculate balance chronologically for each account
        $runningBalance = [];
        
        // Group transactions by account and calculate running balance for each account
        $transactionsByAccount = [];
        foreach ($transactions as $transaction) {
            $accountId = $transaction['bank_account_id'];
            if (!isset($transactionsByAccount[$accountId])) {
                $transactionsByAccount[$accountId] = [];
            }
            $transactionsByAccount[$accountId][] = $transaction;
        }
        
        // For each account, get all transactions and calculate running balance
        foreach ($transactionsByAccount as $accountId => $accountTransactions) {
            // Get account initial balance
            $account = null;
            foreach ($accounts as $acc) {
                if ($acc['id'] == $accountId) {
                    $account = $acc;
                    break;
                }
            }
            
            if (!$account) {
                continue;
            }
            
            $initialBalance = (float)($account['initial_balance'] ?? 0);
            
            // Get ALL transactions for this account (not filtered) to calculate correct running balance
            // We need them in chronological order (oldest first) for correct balance calculation
            $allAccountTransactions = $this->transactionModel->getByAccount($accountId, []);
            
            // Sort by date and time (chronological order - oldest first)
            // Use multiple criteria to ensure consistent ordering
            usort($allAccountTransactions, function($a, $b) {
                // Compare dates (ASC - oldest first)
                $dateA = strtotime($a['transaction_date']);
                $dateB = strtotime($b['transaction_date']);
                if ($dateA != $dateB) {
                    return $dateA <=> $dateB;
                }
                // If same date, compare by created_at (ASC - oldest first)
                $timeA = strtotime($a['created_at'] ?? $a['transaction_date'] . ' 00:00:00');
                $timeB = strtotime($b['created_at'] ?? $b['transaction_date'] . ' 00:00:00');
                if ($timeA != $timeB) {
                    return $timeA <=> $timeB;
                }
                // If same date and time, use ID as final tiebreaker (ASC - oldest first)
                return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
            });
            
            // Calculate running balance chronologically
            $currentBalance = $initialBalance;
            foreach ($allAccountTransactions as $trans) {
                if ($trans['transaction_type'] === 'income') {
                    $currentBalance += (float)$trans['amount'];
                } else {
                    $currentBalance -= (float)$trans['amount'];
                }
                
                // Store balance for this transaction if it's in the filtered results
                $runningBalance[$trans['id']] = $currentBalance;
            }
        }
        
        // For any transactions that weren't processed (shouldn't happen, but safety)
        foreach ($transactions as $transaction) {
            if (!isset($runningBalance[$transaction['id']])) {
                // Fallback: use account's current balance
                $accountId = $transaction['bank_account_id'];
                $account = $this->bankAccountModel->findById($accountId);
                if ($account) {
                    $this->bankAccountModel->updateBalance($accountId);
                    $account = $this->bankAccountModel->findById($accountId);
                    $runningBalance[$transaction['id']] = (float)($account['current_balance'] ?? 0);
                } else {
                    $runningBalance[$transaction['id']] = 0;
                }
            }
        }

        // Sort transactions for display (newest first, but with consistent ordering for same-day transactions)
        // The balance is already calculated correctly above, we just need to ensure display order is consistent
        usort($transactions, function($a, $b) {
            // Compare dates (DESC - newest first)
            $dateA = strtotime($a['transaction_date']);
            $dateB = strtotime($b['transaction_date']);
            if ($dateA != $dateB) {
                return $dateB <=> $dateA; // DESC - newest first
            }
            // If same date, compare by created_at (DESC - newest first)
            $timeA = strtotime($a['created_at'] ?? $a['transaction_date'] . ' 00:00:00');
            $timeB = strtotime($b['created_at'] ?? $b['transaction_date'] . ' 00:00:00');
            if ($timeA != $timeB) {
                return $timeB <=> $timeA; // DESC - newest first
            }
            // If same date and time, use ID as final tiebreaker (DESC - newest first)
            // This ensures consistent ordering even when transactions are created in bulk
            return ($b['id'] ?? 0) <=> ($a['id'] ?? 0); // DESC - newest first
        });
        
        $this->loadPageTranslations('finances');
        
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        $this->data += [
            'viewName' => 'pages/financial-transactions/index.html.twig',
            'page' => ['titulo' => 'Movimentos Financeiros'],
            'condominium' => $condominium,
            'transactions' => $transactions,
            'accounts' => $accounts,
            'runningBalance' => $runningBalance,
            'filters' => $filters,
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $accounts = $this->bankAccountModel->getActiveAccounts($condominiumId);
        $pendingFees = $this->feeModel->getByCondominium($condominiumId, ['status' => 'pending']);

        $fractionModel = new Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);
        
        // Get preselected account from query parameter
        $preselectedAccountId = !empty($_GET['bank_account_id']) ? (int)$_GET['bank_account_id'] : null;

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/financial-transactions/create.html.twig',
            'page' => ['titulo' => 'Criar Movimento Financeiro'],
            'condominium' => $condominium,
            'accounts' => $accounts,
            'fractions' => $fractions,
            'pendingFees' => $pendingFees,
            'preselected_account_id' => $preselectedAccountId,
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        $incomeEntryType = Security::sanitize($_POST['income_entry_type'] ?? '');
        $fractionId = !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null;

        // When income_entry_type is set, fraction_id is required (and vice versa)
        if (in_array($incomeEntryType, ['quota', 'reserva_espaco', 'outros']) && (!$fractionId || $fractionId <= 0)) {
            $_SESSION['error'] = 'Selecione a fração para este tipo de entrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }
        if ($fractionId && $fractionId > 0 && !in_array($incomeEntryType, ['quota', 'reserva_espaco', 'outros'])) {
            $_SESSION['error'] = 'Selecione o tipo de entrada (Quotas, Reservas de espaço ou Outros) quando atribui uma fração.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }

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

        if (!in_array($transactionType, ['income', 'expense', 'transfer'])) {
            $_SESSION['error'] = 'Tipo de transação inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }

        // Handle transfer
        $transferToAccountId = null;
        if ($transactionType === 'transfer') {
            $transferToAccountId = (int)($_POST['transfer_to_account_id'] ?? 0);
            
            if ($transferToAccountId <= 0) {
                $_SESSION['error'] = 'Por favor, selecione a conta de destino.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
                exit;
            }

            if ($transferToAccountId === $bankAccountId) {
                $_SESSION['error'] = 'A conta de origem e destino não podem ser a mesma.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
                exit;
            }

            $toAccount = $this->bankAccountModel->findById($transferToAccountId);
            if (!$toAccount || $toAccount['condominium_id'] != $condominiumId) {
                $_SESSION['error'] = 'Conta de destino inválida.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
                exit;
            }
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

        // Check balance for expenses and transfers
        if ($transactionType === 'expense' || $transactionType === 'transfer') {
            $currentBalance = $this->transactionModel->calculateAccountBalance($bankAccountId);
            if ($amount > $currentBalance) {
                $_SESSION['error'] = 'Saldo insuficiente. Saldo atual: €' . number_format($currentBalance, 2, ',', '.');
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
                exit;
            }
        }

        try {
            global $db;
            $db->beginTransaction();
            
            $userId = AuthMiddleware::userId();
            
            if ($transactionType === 'transfer') {
                // Create expense transaction (from account)
                $fromTransactionId = $this->transactionModel->create([
                    'condominium_id' => $condominiumId,
                    'bank_account_id' => $bankAccountId,
                    'transaction_type' => 'expense',
                    'amount' => $amount,
                    'transaction_date' => $transactionDate,
                    'description' => 'Transferência: ' . $description,
                    'category' => $category ?: 'Transferência',
                    'reference' => $reference,
                    'related_type' => 'transfer',
                    'related_id' => $transferToAccountId,
                    'transfer_to_account_id' => $transferToAccountId,
                    'created_by' => $userId
                ]);

                // Create income transaction (to account)
                $toAccount = $this->bankAccountModel->findById($transferToAccountId);
                $toAccountName = $toAccount['name'] ?? 'Conta';
                $fromAccountName = $account['name'] ?? 'Conta';
                
                $this->transactionModel->create([
                    'condominium_id' => $condominiumId,
                    'bank_account_id' => $transferToAccountId,
                    'transaction_type' => 'income',
                    'amount' => $amount,
                    'transaction_date' => $transactionDate,
                    'description' => 'Transferência recebida de ' . $fromAccountName . ': ' . $description,
                    'category' => $category ?: 'Transferência',
                    'reference' => $reference,
                    'related_type' => 'transfer',
                    'related_id' => $fromTransactionId,
                    'transfer_to_account_id' => null,
                    'created_by' => $userId
                ]);

                $db->commit();
                $_SESSION['success'] = 'Transferência realizada com sucesso!';
            } elseif ($transactionType === 'income' && $fractionId && in_array($incomeEntryType, ['quota', 'reserva_espaco', 'outros'])) {
                // Entrada atribuída a fração: conta da fração + liquidação se Quotas
                $faModel = new FractionAccount();
                $account = $faModel->getOrCreate($fractionId, $condominiumId);
                $accountId = (int)$account['id'];

                $categoryMap = ['quota' => 'Quotas', 'reserva_espaco' => 'Reservas de espaço', 'outros' => 'Outros'];
                $sourceTypeMap = ['quota' => 'quota_payment', 'reserva_espaco' => 'space_reservation', 'outros' => 'other'];

                // Referência automática para Quotas
                $ref = ($incomeEntryType === 'quota') ? ('REF' . $condominiumId . $fractionId . date('YmdHis')) : $reference;

                $transactionId = $this->transactionModel->create([
                    'condominium_id' => $condominiumId,
                    'bank_account_id' => $bankAccountId,
                    'fraction_id' => $fractionId,
                    'transaction_type' => 'income',
                    'amount' => $amount,
                    'transaction_date' => $transactionDate,
                    'description' => $description,
                    'category' => $categoryMap[$incomeEntryType],
                    'income_entry_type' => $incomeEntryType,
                    'reference' => $ref,
                    'related_type' => 'fraction_account',
                    'related_id' => $accountId,
                    'transfer_to_account_id' => null,
                    'created_by' => $userId
                ]);

                $movementId = $faModel->addCredit($accountId, $amount, $sourceTypeMap[$incomeEntryType], $transactionId, $description);

                if ($incomeEntryType === 'quota') {
                    $result = (new LiquidationService())->liquidate($fractionId, $userId, $transactionDate, $transactionId);
                    $parts = [];
                    foreach ($result['fully_paid'] ?? [] as $fid) {
                        $f = $this->feeModel->findById($fid);
                        $parts[] = $f ? self::feeLabel($f) : ('Quota #' . $fid);
                    }
                    foreach (array_keys($result['partially_paid'] ?? []) as $fid) {
                        $f = $this->feeModel->findById($fid);
                        $parts[] = ($f ? self::feeLabel($f) : ('Quota #' . $fid)) . ' (parcial)';
                    }
                    $builtDesc = implode(', ', $parts);
                    if ($builtDesc !== '') {
                        $this->transactionModel->update($transactionId, ['description' => $builtDesc]);
                        (new FractionAccountMovement())->update($movementId, ['description' => $builtDesc]);
                    }
                    
                    // Log automatic liquidation via financial transaction
                    $fullyPaidCount = count($result['fully_paid'] ?? []);
                    $partiallyPaidCount = count($result['partially_paid'] ?? []);
                    $this->auditService->logFinancial([
                        'condominium_id' => $condominiumId,
                        'entity_type' => 'financial_transaction',
                        'entity_id' => $transactionId,
                        'action' => 'quotas_liquidated_auto',
                        'user_id' => $userId,
                        'amount' => $amount,
                        'new_status' => 'completed',
                        'description' => "Liquidação automática de quotas via movimento financeiro. Valor: €" . number_format($amount, 2, ',', '.') . ". Quotas totalmente pagas: {$fullyPaidCount}, Pagamentos parciais: {$partiallyPaidCount}"
                    ]);
                    
                    $receiptSvc = new ReceiptService();
                    foreach ($result['fully_paid'] ?? [] as $fid) {
                        $receiptSvc->generateForFullyPaidFee($fid, $result['fully_paid_payments'][$fid] ?? null, $condominiumId, $userId);
                    }
                }

                $db->commit();
                $_SESSION['success'] = 'Movimento financeiro criado com sucesso. O valor foi atribuído à fração e ' . ($incomeEntryType === 'quota' ? 'as quotas em atraso foram liquidadas automaticamente.' : 'registado na conta da fração.');
            } else {
                // Regular transaction (income sem fração, ou expense)
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
                    'transfer_to_account_id' => null,
                    'created_by' => $userId
                ]);

                $db->commit();
                $_SESSION['success'] = 'Movimento financeiro criado com sucesso!';
            }
            
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        } catch (\Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $_SESSION['error'] = 'Erro ao criar movimento: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }
    }

    /**
     * JSON: dados do movimento financeiro (para modal na conta da fração).
     */
    public function getTransactionInfo(int $condominiumId, int $id)
    {
        header('Content-Type: application/json');
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $transaction = $this->transactionModel->findById($id);
        if (!$transaction || (int)($transaction['condominium_id'] ?? 0) != $condominiumId) {
            http_response_code(404);
            echo json_encode(['error' => 'Movimento não encontrado']);
            exit;
        }

        echo json_encode(['transaction' => $transaction]);
        exit;
    }

    public function edit(int $condominiumId, int $id)
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

        $transaction = $this->transactionModel->findById($id);
        if (!$transaction || $transaction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Movimento não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        // Don't allow editing transactions linked to fee payments or fraction account flow
        if ($this->transactionModel->isLinkedToFeePayment($id)) {
            $_SESSION['error'] = 'Não é possível editar movimentos associados a pagamentos de quotas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }
        if (($transaction['related_type'] ?? '') === 'fraction_account') {
            $_SESSION['error'] = 'Não é possível editar movimentos de liquidação de quotas (conta da fração).';
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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

        // Don't allow editing transactions linked to fee payments or fraction account flow
        if ($this->transactionModel->isLinkedToFeePayment($id)) {
            $_SESSION['error'] = 'Não é possível editar movimentos associados a pagamentos de quotas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }
        if (($transaction['related_type'] ?? '') === 'fraction_account') {
            $_SESSION['error'] = 'Não é possível editar movimentos de liquidação de quotas (conta da fração).';
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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

        // Don't allow deleting transactions linked to fee payments or fraction account flow
        if ($this->transactionModel->isLinkedToFeePayment($id)) {
            $_SESSION['error'] = 'Não é possível eliminar movimentos associados a pagamentos de quotas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }
        if (($transaction['related_type'] ?? '') === 'fraction_account') {
            $_SESSION['error'] = 'Não é possível eliminar movimentos de liquidação de quotas (conta da fração).';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        try {
            global $db;
            $db->beginTransaction();
            
            // If it's a transfer, delete both transactions
            if ($transaction['related_type'] === 'transfer') {
                $relatedId = $transaction['related_id'];
                $transferToAccountId = $transaction['transfer_to_account_id'];
                
                // Find the paired transaction
                if ($transaction['transaction_type'] === 'expense' && $relatedId) {
                    // This is the "from" transaction, find the "to" transaction
                    $pairedTransaction = $this->transactionModel->findById($relatedId);
                } else {
                    // This is the "to" transaction, find the "from" transaction
                    $stmt = $db->prepare("SELECT id FROM financial_transactions WHERE related_id = :id AND related_type = 'transfer' AND transaction_type = 'expense' LIMIT 1");
                    $stmt->execute([':id' => $id]);
                    $pairedTransaction = $stmt->fetch();
                }
                
                // Delete both transactions
                $this->transactionModel->delete($id);
                if ($pairedTransaction) {
                    $this->transactionModel->delete($pairedTransaction['id']);
                }
                
                $db->commit();
                $_SESSION['success'] = 'Transferência eliminada com sucesso!';
            } else {
                // Regular transaction
                $this->transactionModel->delete($id);
                $db->commit();
                $_SESSION['success'] = 'Movimento financeiro eliminado com sucesso!';
            }
        } catch (\Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
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

    private static function feeLabel(array $f): string
    {
        if (($f['fee_type'] ?? '') === 'extra' && !empty($f['reference'])) {
            return 'Quota extra: ' . $f['reference'];
        }
        if (!empty($f['period_month']) && !empty($f['period_year'])) {
            return 'Quota ' . sprintf('%02d/%d', $f['period_month'], $f['period_year']);
        }
        return 'Quota ' . ($f['period_year'] ?? '');
    }
}
