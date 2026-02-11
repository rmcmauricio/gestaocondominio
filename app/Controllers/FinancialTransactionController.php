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
use App\Models\FeePayment;
use App\Models\Fraction;
use App\Models\Assembly;
use App\Models\FractionAccount;
use App\Models\FractionAccountMovement;
use App\Services\LiquidationService;
use App\Services\ReceiptService;
use App\Services\AuditService;
use App\Services\FinancialTransactionImportService;

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

        // Pagination
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = 50; // Items per page
        $offset = ($page - 1) * $perPage;
        
        // Get total count for pagination
        $totalCount = $this->transactionModel->getCountByCondominium($condominiumId, $filters);
        $totalPages = ceil($totalCount / $perPage);
        
        // Apply pagination to filters
        $filters['limit'] = $perPage;
        $filters['offset'] = $offset;

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
        
        // Get liquidated fees for each transaction
        $liquidatedFeesByTransaction = [];
        $feePaymentModel = new FeePayment();
        $feeModel = new Fee();
        
        // Get liquidated fees for all income transactions (direct link OR via fraction_account_movements)
        foreach ($transactions as $transaction) {
            // Check all income transactions, regardless of related_type
            if ($transaction['transaction_type'] === 'income') {
                // Get fee payments: direct (fp.financial_transaction_id) OR via liquidation (fam.source_financial_transaction_id)
                global $db;
                $stmt = $db->prepare("
                    SELECT DISTINCT fp.id, fp.fee_id, fp.amount, f.period_year, f.period_month, f.fee_type, f.reference as fee_reference
                    FROM fee_payments fp
                    INNER JOIN fees f ON f.id = fp.fee_id
                    LEFT JOIN fraction_account_movements fam ON fam.source_reference_id = fp.id
                        AND fam.type = 'debit' AND fam.source_type = 'quota_application'
                    WHERE (fp.financial_transaction_id = :transaction_id
                       OR fam.source_financial_transaction_id = :transaction_id2)
                    ORDER BY f.period_year ASC, f.period_month ASC
                ");
                $stmt->execute([':transaction_id' => $transaction['id'], ':transaction_id2' => $transaction['id']]);
                $feePayments = $stmt->fetchAll() ?: [];
                
                if (!empty($feePayments)) {
                    $liquidatedFees = [];
                    foreach ($feePayments as $fp) {
                        $label = self::feeLabel([
                            'fee_type' => $fp['fee_type'],
                            'reference' => $fp['fee_reference'],
                            'period_year' => $fp['period_year'],
                            'period_month' => $fp['period_month']
                        ]);
                        $liquidatedFees[] = [
                            'label' => $label,
                            'amount' => (float)$fp['amount']
                        ];
                    }
                    $liquidatedFeesByTransaction[$transaction['id']] = $liquidatedFees;
                }
            }
        }
        
        $this->loadPageTranslations('finances');
        
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        // Get fractions for quota liquidation
        $fractionModel = new Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);
        
        // Build query string for pagination links (preserve filters)
        $queryParams = [];
        if (!empty($filters['bank_account_id'])) {
            $queryParams[] = 'bank_account_id=' . $filters['bank_account_id'];
        }
        if (!empty($filters['transaction_type'])) {
            $queryParams[] = 'transaction_type=' . urlencode($filters['transaction_type']);
        }
        if (!empty($filters['from_date'])) {
            $queryParams[] = 'from_date=' . urlencode($filters['from_date']);
        }
        if (!empty($filters['to_date'])) {
            $queryParams[] = 'to_date=' . urlencode($filters['to_date']);
        }
        $baseQuery = implode('&', $queryParams);
        
        $this->data += [
            'viewName' => 'pages/financial-transactions/index.html.twig',
            'page' => ['titulo' => 'Movimentos Financeiros'],
            'condominium' => $condominium,
            'transactions' => $transactions,
            'accounts' => $accounts,
            'runningBalance' => $runningBalance,
            'filters' => $filters,
            'fractions' => $fractions,
            'liquidatedFeesByTransaction' => $liquidatedFeesByTransaction,
            'is_admin' => $isAdmin,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalCount,
                'per_page' => $perPage,
                'base_query' => $baseQuery
            ],
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

        // Check if year has been approved
        $transactionYear = (int)date('Y', strtotime($transactionDate));
        $assemblyModel = new Assembly();
        if ($assemblyModel->hasApprovedAccountsForYear($condominiumId, $transactionYear)) {
            $_SESSION['error'] = 'Não é possível criar movimentos financeiros para o ano ' . $transactionYear . ' pois as contas deste ano já foram aprovadas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/create');
            exit;
        }

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
     * JSON: dados do movimento financeiro (para modal de detalhes).
     * Inclui conta, fração (se aplicável) e quotas associadas/liquidadas.
     */
    public function getTransactionInfo(int $condominiumId, int $id)
    {
        header('Content-Type: application/json');
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        global $db;
        $stmt = $db->prepare("
            SELECT ft.*, 
                   ba.name as account_name, ba.account_type,
                   ba2.name as transfer_to_account_name,
                   fr.identifier as fraction_identifier
            FROM financial_transactions ft
            LEFT JOIN bank_accounts ba ON ba.id = ft.bank_account_id
            LEFT JOIN bank_accounts ba2 ON ba2.id = ft.transfer_to_account_id
            LEFT JOIN fractions fr ON fr.id = ft.fraction_id
            WHERE ft.id = :id AND ft.condominium_id = :condominium_id
        ");
        $stmt->execute([':id' => $id, ':condominium_id' => $condominiumId]);
        $transaction = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Movimento não encontrado']);
            exit;
        }

        $liquidatedFees = [];
        if ($transaction['transaction_type'] === 'income') {
            $stmt = $db->prepare("
                SELECT DISTINCT fp.id, fp.amount, f.period_year, f.period_month, f.fee_type, f.reference as fee_reference
                FROM fee_payments fp
                INNER JOIN fees f ON f.id = fp.fee_id
                LEFT JOIN fraction_account_movements fam ON fam.source_reference_id = fp.id
                    AND fam.type = 'debit' AND fam.source_type = 'quota_application'
                WHERE (fp.financial_transaction_id = :tid OR fam.source_financial_transaction_id = :tid2)
                ORDER BY f.period_year ASC, f.period_month ASC
            ");
            $stmt->execute([':tid' => $id, ':tid2' => $id]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $liquidatedFees[] = [
                    'label' => self::feeLabel([
                        'fee_type' => $r['fee_type'],
                        'reference' => $r['fee_reference'],
                        'period_year' => $r['period_year'],
                        'period_month' => $r['period_month']
                    ]),
                    'amount' => (float)$r['amount']
                ];
            }
        }

        echo json_encode([
            'transaction' => $transaction,
            'liquidated_fees' => $liquidatedFees
        ]);
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

        // Check if year has been approved
        $transactionYear = (int)date('Y', strtotime($transaction['transaction_date']));
        $assemblyModel = new Assembly();
        if ($assemblyModel->hasApprovedAccountsForYear($condominiumId, $transactionYear)) {
            $_SESSION['error'] = 'Não é possível editar movimentos financeiros do ano ' . $transactionYear . ' pois as contas deste ano já foram aprovadas.';
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

        // Check if year has been approved (check both old and new dates)
        $oldTransactionYear = (int)date('Y', strtotime($transaction['transaction_date']));
        $newTransactionDate = $_POST['transaction_date'] ?? $transaction['transaction_date'];
        $newTransactionYear = (int)date('Y', strtotime($newTransactionDate));
        
        $assemblyModel = new Assembly();
        if ($assemblyModel->hasApprovedAccountsForYear($condominiumId, $oldTransactionYear)) {
            $_SESSION['error'] = 'Não é possível editar movimentos financeiros do ano ' . $oldTransactionYear . ' pois as contas deste ano já foram aprovadas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }
        if ($newTransactionYear !== $oldTransactionYear && $assemblyModel->hasApprovedAccountsForYear($condominiumId, $newTransactionYear)) {
            $_SESSION['error'] = 'Não é possível alterar a data para o ano ' . $newTransactionYear . ' pois as contas deste ano já foram aprovadas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/' . $id . '/edit');
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

        // Check if year has been approved
        $transactionYear = (int)date('Y', strtotime($transaction['transaction_date']));
        $assemblyModel = new Assembly();
        if ($assemblyModel->hasApprovedAccountsForYear($condominiumId, $transactionYear)) {
            $_SESSION['error'] = 'Não é possível eliminar movimentos financeiros do ano ' . $transactionYear . ' pois as contas deste ano já foram aprovadas.';
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

        $accounts = $this->bankAccountModel->getActiveAccounts($condominiumId);

        $this->loadPageTranslations('finances');
        
        $this->data += [
            'viewName' => 'pages/financial-transactions/import.html.twig',
            'page' => ['titulo' => 'Importar Movimentos Financeiros'],
            'condominium' => $condominium,
            'accounts' => $accounts,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];

        unset($_SESSION['error'], $_SESSION['success']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function uploadImport(int $condominiumId)
    {
        // Set JSON header first to prevent any HTML output
        header('Content-Type: application/json; charset=utf-8');
        
        // Disable error display to prevent HTML in JSON response
        $oldErrorReporting = error_reporting(E_ALL);
        $oldDisplayErrors = ini_get('display_errors');
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
                $errorMsg = 'Erro no upload do ficheiro';
                if (!empty($_FILES['file']['error'])) {
                    switch ($_FILES['file']['error']) {
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $errorMsg = 'Ficheiro muito grande';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $errorMsg = 'Upload parcial do ficheiro';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $errorMsg = 'Nenhum ficheiro foi enviado';
                            break;
                        case UPLOAD_ERR_NO_TMP_DIR:
                            $errorMsg = 'Pasta temporária não encontrada';
                            break;
                        case UPLOAD_ERR_CANT_WRITE:
                            $errorMsg = 'Erro ao escrever ficheiro';
                            break;
                        case UPLOAD_ERR_EXTENSION:
                            $errorMsg = 'Extensão do ficheiro não permitida';
                            break;
                    }
                }
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                exit;
            }
            
            // Validate file extension
            $originalName = $_FILES['file']['name'] ?? '';
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['xlsx', 'xls', 'csv'];
            
            if (!in_array($extension, $allowedExtensions)) {
                echo json_encode(['success' => false, 'error' => 'Formato de ficheiro não suportado. Use .xlsx, .xls ou .csv (ficheiro enviado: ' . htmlspecialchars($originalName) . ')']);
                exit;
            }

            $importService = new FinancialTransactionImportService();
            
            // Save uploaded file temporarily
            $tmpFile = $_FILES['file']['tmp_name'];
            if (!file_exists($tmpFile) || !is_readable($tmpFile)) {
                echo json_encode(['success' => false, 'error' => 'Ficheiro temporário não acessível']);
                exit;
            }
            
            $hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';
            
            // Read file (pass original filename for extension detection)
            $fileData = $importService->readFile($tmpFile, $hasHeader, $originalName);
            
            // Detect column mode
            $mode = 'single';
            if (!empty($fileData['headers'])) {
                $mode = $importService->detectColumnMode($fileData['headers']);
            }
            
            // Suggest mapping (pass mode to ensure correct field suggestions)
            $suggestedMapping = [];
            if (!empty($fileData['headers'])) {
                $suggestedMapping = $importService->suggestMapping($fileData['headers'], $mode);
            }

            echo json_encode([
                'success' => true,
                'headers' => $fileData['headers'],
                'rowCount' => $fileData['rowCount'],
                'columnCount' => $fileData['columnCount'],
                'mode' => $mode,
                'suggestedMapping' => $suggestedMapping
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            // Log error for debugging
            error_log('Import error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            
            $errorMessage = $e->getMessage();
            
            // Check if it's a zip extension error
            if (strpos($errorMessage, 'zip') !== false || strpos($errorMessage, 'ZipArchive') !== false) {
                $errorMessage = 'Extensão PHP "zip" não está ativada. ' . 
                               'Para importar ficheiros Excel, ative a extensão zip: ' .
                               '1) Abra C:\\xampp\\php\\php.ini, ' .
                               '2) Procure por "extension=zip" e remova o ponto e vírgula (;) no início, ' .
                               '3) Reinicie o Apache no XAMPP. ' .
                               'Ver ficheiro INSTALACAO_ZIP_EXTENSION.md para mais detalhes.';
            }
            
            echo json_encode([
                'success' => false, 
                'error' => 'Erro ao processar ficheiro: ' . $errorMessage,
                'debug' => (defined('DEBUG') && DEBUG ? [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ] : null)
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            // Catch any other errors
            error_log('Fatal import error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            
            $errorMessage = $e->getMessage();
            
            // Check if it's a zip extension error
            if (strpos($errorMessage, 'zip') !== false || strpos($errorMessage, 'ZipArchive') !== false) {
                $errorMessage = 'Extensão PHP "zip" não está ativada. ' . 
                               'Para importar ficheiros Excel, ative a extensão zip: ' .
                               '1) Abra C:\\xampp\\php\\php.ini, ' .
                               '2) Procure por "extension=zip" e remova o ponto e vírgula (;) no início, ' .
                               '3) Reinicie o Apache no XAMPP. ' .
                               'Ver ficheiro INSTALACAO_ZIP_EXTENSION.md para mais detalhes.';
            }
            
            echo json_encode([
                'success' => false, 
                'error' => 'Erro fatal ao processar ficheiro: ' . $errorMessage
            ], JSON_UNESCAPED_UNICODE);
        } finally {
            // Restore error settings
            error_reporting($oldErrorReporting);
            ini_set('display_errors', $oldDisplayErrors);
        }
        exit;
    }

    public function previewImport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        // Allow GET to restore from session (when coming back from preview)
        $restoreFromSession = ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['import_mapping_data']));
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$restoreFromSession) {
            $_SESSION['error'] = 'Método não permitido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
            exit;
        }
        
        // If restoring from session, use stored data
        if ($restoreFromSession) {
            $mappingData = $_SESSION['import_mapping_data'];
            $_POST = $mappingData;
            $_FILES = $mappingData['_files'] ?? [];
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
            exit;
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = 'Erro no upload do ficheiro.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
            exit;
        }

        try {
            // Get bank account ID from POST
            $bankAccountId = (int)($_POST['bank_account_id'] ?? 0);
            if ($bankAccountId <= 0) {
                $_SESSION['error'] = 'Por favor, selecione uma conta bancária.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
                exit;
            }
            
            // Validate account belongs to condominium
            $accounts = $this->bankAccountModel->getActiveAccounts($condominiumId);
            $selectedAccount = null;
            foreach ($accounts as $account) {
                if ($account['id'] == $bankAccountId) {
                    $selectedAccount = $account;
                    break;
                }
            }
            
            if (!$selectedAccount) {
                $_SESSION['error'] = 'Conta bancária inválida ou não pertence ao condomínio.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
                exit;
            }
            
            $importService = new FinancialTransactionImportService();
            $tmpFile = $_FILES['file']['tmp_name'];
            $originalName = $_FILES['file']['name'] ?? '';
            $hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';
            
            // Read file (pass original filename for extension detection)
            $fileData = $importService->readFile($tmpFile, $hasHeader, $originalName);
            
            // Get column mapping from POST - this is the EXACT mapping chosen by the user
            $columnMappingJson = $_POST['column_mapping'] ?? '{}';
            $columnMapping = json_decode($columnMappingJson, true);
            
            if (empty($columnMapping) || !is_array($columnMapping)) {
                $_SESSION['error'] = 'Mapeamento de colunas não fornecido ou inválido.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
                exit;
            }
            
            // Validate that transaction_date is mapped
            if (!in_array('transaction_date', $columnMapping)) {
                $_SESSION['error'] = 'Por favor, mapeie uma coluna para o campo "Data".';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
                exit;
            }

            // Determine mode - check if mapping contains both credit and debit columns
            $hasCredit = false;
            $hasDebit = false;
            foreach ($columnMapping as $field) {
                if ($field === 'amount_credit') $hasCredit = true;
                if ($field === 'amount_debit') $hasDebit = true;
            }
            $mode = ($hasCredit && $hasDebit) ? 'separate' : 'single';
            
            // Parse rows using EXACTLY the mapping provided by the user
            // The parseRows method will use ONLY the columns mapped by the user
            $parsedData = $importService->parseRows($fileData['rows'], $columnMapping, $mode);
            
            // Debug: log if no data parsed
            if (empty($parsedData)) {
                error_log('No data parsed. Row count: ' . count($fileData['rows']) . ', Mapping: ' . json_encode($columnMapping));
            }
            
            // Apply bank account to all rows
            foreach ($parsedData as &$rowData) {
                $rowData['bank_account_id'] = $bankAccountId;
            }
            unset($rowData);
            
            // Validate each row
            foreach ($parsedData as &$rowData) {
                $validation = $importService->validateRow($rowData, $accounts, $condominiumId);
                if (!$validation['valid']) {
                    $rowData['_errors'] = array_merge($rowData['_errors'] ?? [], $validation['errors']);
                    $rowData['_has_errors'] = true;
                }
                $rowData = $validation['rowData'];
            }
            
            // Check for duplicate transactions
            $duplicates = $importService->checkDuplicates($parsedData, $condominiumId, $bankAccountId);
            
            // Add duplicate information to parsed data
            foreach ($duplicates as $index => $duplicateList) {
                if (isset($parsedData[$index])) {
                    $parsedData[$index]['_duplicates'] = $duplicateList;
                    $parsedData[$index]['_has_duplicates'] = true;
                }
            }

            // Store parsed data and mapping info in session for processing and going back
            $_SESSION['import_data'] = [
                'parsed_data' => $parsedData,
                'column_mapping' => $columnMapping,
                'mode' => $mode,
                'condominium_id' => $condominiumId,
                'bank_account_id' => $bankAccountId,
                'has_header' => $hasHeader,
                'original_filename' => $originalName,
                'file_data' => $fileData // Store original file data for re-parsing if needed
            ];
            
            // Also store mapping data for going back
            $_SESSION['import_mapping_data'] = [
                'bank_account_id' => $bankAccountId,
                'has_header' => $hasHeader ? '1' : '0',
                'column_mapping' => json_encode($columnMapping),
                'csrf_token' => $csrfToken,
                '_files' => $_FILES // Store file info
            ];

            $condominium = $this->condominiumModel->findById($condominiumId);
            
            // Get fractions for quota liquidation
            $fractionModel = new Fraction();
            $fractions = $fractionModel->getByCondominiumId($condominiumId);
            
            $this->loadPageTranslations('finances');
            
            $this->data += [
                'viewName' => 'pages/financial-transactions/import-preview.html.twig',
                'page' => ['titulo' => 'Preview da Importação'],
                'condominium' => $condominium,
                'accounts' => $accounts,
                'fractions' => $fractions,
                'parsedData' => $parsedData,
                'mode' => $mode,
                'bank_account_id' => $bankAccountId,
                'csrf_token' => Security::generateCSRFToken(),
                'error' => $_SESSION['error'] ?? null,
                'success' => $_SESSION['success'] ?? null
            ];

            unset($_SESSION['error'], $_SESSION['success']);
            echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao processar ficheiro: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
            exit;
        }
    }

    public function processImport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = 'Método não permitido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        // Get data from session
        $importData = $_SESSION['import_data'] ?? null;
        if (!$importData || $importData['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Dados de importação não encontrados.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
            exit;
        }

        // Get bank account ID from POST (can be changed in preview)
        $bankAccountId = (int)($_POST['preview_bank_account_id'] ?? $importData['bank_account_id'] ?? 0);
        if ($bankAccountId <= 0) {
            $_SESSION['error'] = 'Por favor, selecione uma conta bancária.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
            exit;
        }
        
        // Validate account belongs to condominium
        $accounts = $this->bankAccountModel->getActiveAccounts($condominiumId);
        $selectedAccount = null;
        foreach ($accounts as $account) {
            if ($account['id'] == $bankAccountId) {
                $selectedAccount = $account;
                break;
            }
        }
        
        if (!$selectedAccount) {
            $_SESSION['error'] = 'Conta bancária inválida ou não pertence ao condomínio.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions/import');
            exit;
        }

        $parsedData = $importData['parsed_data'] ?? [];
        $userId = AuthMiddleware::userId();
        
        // Update bank account ID for all rows
        foreach ($parsedData as &$rowData) {
            $rowData['bank_account_id'] = $bankAccountId;
        }
        unset($rowData);

        // Get edited data and deleted rows from POST
        $editedData = json_decode($_POST['edited_data'] ?? '[]', true);
        $deletedRows = json_decode($_POST['deleted_rows'] ?? '[]', true);
        $mode = $importData['mode'] ?? 'single';
        
        // Create a mapping of old indices to new indices after deletion
        $indexMapping = [];
        $newIndex = 0;
        foreach ($parsedData as $oldIndex => $row) {
            if (!in_array($oldIndex, $deletedRows)) {
                $indexMapping[$oldIndex] = $newIndex;
                $newIndex++;
            }
        }
        
        // Remove deleted rows from parsed data
        foreach ($deletedRows as $deletedIndex) {
            unset($parsedData[$deletedIndex]);
        }
        
        // Re-index array after removing deleted rows
        $parsedData = array_values($parsedData);
        
        // Merge edited data with parsed data and reprocess amounts if needed
        // Map old indices to new indices
        $mappedEditedData = [];
        foreach ($editedData as $oldIndex => $editedRow) {
            // Skip if this row was deleted
            if (in_array($oldIndex, $deletedRows)) {
                continue;
            }
            
            // Map to new index
            if (isset($indexMapping[$oldIndex])) {
                $mappedEditedData[$indexMapping[$oldIndex]] = $editedRow;
            }
        }
        
        foreach ($mappedEditedData as $index => $editedRow) {
            if (isset($parsedData[$index])) {
                $parsedData[$index] = array_merge($parsedData[$index], $editedRow);
                
                // Reprocess transaction date if it was edited (ensure it's in Y-m-d format)
                if (isset($editedRow['transaction_date']) && !empty($editedRow['transaction_date'])) {
                    $importService = new FinancialTransactionImportService();
                    $dateResult = $importService->parseDate($editedRow['transaction_date']);
                    if ($dateResult['success']) {
                        $parsedData[$index]['transaction_date'] = $dateResult['date'];
                    } else {
                        // If parsing fails, try to use the original value
                        // But log the error
                        error_log('Failed to parse edited date: ' . $editedRow['transaction_date']);
                    }
                }
                
                // Reprocess amount if columns were edited
                if ($mode === 'separate') {
                    $importService = new FinancialTransactionImportService();
                    $amountResult = $importService->processSeparateAmounts(
                        $parsedData[$index]['amount_credit'] ?? null,
                        $parsedData[$index]['amount_debit'] ?? null
                    );
                    if ($amountResult['valid']) {
                        $parsedData[$index]['amount'] = $amountResult['amount'];
                        $parsedData[$index]['transaction_type'] = $amountResult['transaction_type'];
                    }
                } else {
                    $importService = new FinancialTransactionImportService();
                    $amountResult = $importService->processSingleAmount($parsedData[$index]['amount'] ?? null);
                    if ($amountResult['valid']) {
                        $parsedData[$index]['amount'] = $amountResult['amount'];
                        $parsedData[$index]['transaction_type'] = $amountResult['transaction_type'];
                    }
                }
            }
        }

        $successCount = 0;
        $errorCount = 0;
        $transferCount = 0;
        $errors = [];

        try {
            global $db;
            $db->beginTransaction();

            foreach ($parsedData as $index => $rowData) {
                // Skip rows with errors unless they were edited
                if (!empty($rowData['_has_errors']) && !isset($mappedEditedData[$index])) {
                    $errorCount++;
                    $errors[] = [
                        'row' => $rowData['_row_index'] ?? ($index + 1),
                        'errors' => $rowData['_errors'] ?? ['Erros de validação']
                    ];
                    continue;
                }

                // Validate row
                $importService = new FinancialTransactionImportService();
                $validation = $importService->validateRow($rowData, $accounts, $condominiumId);
                
                if (!$validation['valid']) {
                    $errorCount++;
                    $errors[] = [
                        'row' => $rowData['_row_index'] ?? ($index + 1),
                        'errors' => $validation['errors']
                    ];
                    continue;
                }

                $rowData = $validation['rowData'];

                // Check if year has been approved
                $transactionYear = (int)date('Y', strtotime($rowData['transaction_date']));
                $assemblyModel = new Assembly();
                if ($assemblyModel->hasApprovedAccountsForYear($condominiumId, $transactionYear)) {
                    $errorCount++;
                    $errors[] = [
                        'row' => $rowData['_row_index'] ?? ($index + 1),
                        'errors' => ['Não é possível criar movimentos para o ano ' . $transactionYear . ' pois as contas deste ano já foram aprovadas.']
                    ];
                    continue;
                }

                // Check if it's a transfer
                $isTransfer = !empty($rowData['is_transfer']) || !empty($_POST['transfer_' . $index]);
                $transferToAccountId = null;
                
                if ($isTransfer) {
                    $transferToAccountId = !empty($_POST['transfer_to_account_' . $index]) 
                        ? (int)$_POST['transfer_to_account_' . $index] 
                        : null;
                    
                    if (!$transferToAccountId) {
                        $errorCount++;
                        $errors[] = [
                            'row' => $rowData['_row_index'] ?? ($index + 1),
                            'errors' => ['Conta de destino não especificada para transferência']
                        ];
                        continue;
                    }

                    // Validate transfer account
                    $transferAccount = null;
                    foreach ($accounts as $account) {
                        if ($account['id'] == $transferToAccountId) {
                            $transferAccount = $account;
                            break;
                        }
                    }

                    if (!$transferAccount || $transferAccount['condominium_id'] != $condominiumId) {
                        $errorCount++;
                        $errors[] = [
                            'row' => $rowData['_row_index'] ?? ($index + 1),
                            'errors' => ['Conta de destino inválida']
                        ];
                        continue;
                    }

                    if ($transferToAccountId == $rowData['bank_account_id']) {
                        $errorCount++;
                        $errors[] = [
                            'row' => $rowData['_row_index'] ?? ($index + 1),
                            'errors' => ['Conta origem e destino não podem ser a mesma']
                        ];
                        continue;
                    }

                    // Check balance for transfer
                    $currentBalance = $this->transactionModel->calculateAccountBalance($rowData['bank_account_id']);
                    if ($rowData['amount'] > $currentBalance) {
                        $errorCount++;
                        $errors[] = [
                            'row' => $rowData['_row_index'] ?? ($index + 1),
                            'errors' => ['Saldo insuficiente na conta origem']
                        ];
                        continue;
                    }
                }

                // Check if it's quota liquidation
                $isQuotaLiquidation = !empty($_POST['quota_liquidation_' . $index]) && $_POST['quota_liquidation_' . $index] === '1';
                $quotaFractionId = null;
                $selectedFeeIds = [];
                
                if ($isQuotaLiquidation) {
                    $quotaFractionId = !empty($_POST['quota_fraction_' . $index]) 
                        ? (int)$_POST['quota_fraction_' . $index] 
                        : null;
                    
                    // Validate quota liquidation
                    if ($rowData['transaction_type'] !== 'income') {
                        $errorCount++;
                        $errors[] = [
                            'row' => $rowData['_row_index'] ?? ($index + 1),
                            'errors' => ['Apenas movimentos de entrada podem liquidar quotas']
                        ];
                        continue;
                    }
                    
                    if (!$quotaFractionId) {
                        $errorCount++;
                        $errors[] = [
                            'row' => $rowData['_row_index'] ?? ($index + 1),
                            'errors' => ['Fração não especificada para liquidação de quotas']
                        ];
                        continue;
                    }
                    
                    // Validate fraction belongs to condominium
                    $fractionModel = new Fraction();
                    $fraction = $fractionModel->findById($quotaFractionId);
                    if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
                        $errorCount++;
                        $errors[] = [
                            'row' => $rowData['_row_index'] ?? ($index + 1),
                            'errors' => ['Fração inválida ou não pertence ao condomínio']
                        ];
                        continue;
                    }
                    
                    // Get selected fee IDs from hidden input
                    $quotaSelectedInput = $_POST['quota_selected_' . $index] ?? '';
                    if (!empty($quotaSelectedInput)) {
                        $selectedFeeIds = array_map('intval', explode(',', $quotaSelectedInput));
                        $selectedFeeIds = array_filter($selectedFeeIds); // Remove empty values
                    }
                    $quotaRemainingDest = $_POST['quota_remaining_destination_' . $index] ?? 'oldest';
                    if (!in_array($quotaRemainingDest, ['oldest', 'unregistered', 'balance'])) {
                        $quotaRemainingDest = 'oldest';
                    }
                }

                if ($isTransfer) {
                    // Create transfer: expense in origin, income in destination
                    $transferDescription = $rowData['description'] ?? 'Movimento importado';
                    $fromTransactionId = $this->transactionModel->create([
                        'condominium_id' => $condominiumId,
                        'bank_account_id' => $rowData['bank_account_id'],
                        'transaction_type' => 'expense',
                        'amount' => $rowData['amount'],
                        'transaction_date' => $rowData['transaction_date'],
                        'description' => 'Transferência: ' . $transferDescription,
                        'category' => $rowData['category'] ?? 'Transferência',
                        'reference' => $rowData['reference'] ?? null,
                        'related_type' => 'transfer',
                        'related_id' => null, // Will be updated after creating destination transaction
                        'transfer_to_account_id' => $transferToAccountId,
                        'created_by' => $userId
                    ]);

                    $toAccount = $transferAccount;
                    $fromAccount = null;
                    foreach ($accounts as $acc) {
                        if ($acc['id'] == $rowData['bank_account_id']) {
                            $fromAccount = $acc;
                            break;
                        }
                    }
                    $fromAccountName = $fromAccount['name'] ?? 'Conta';
                    
                    $toTransactionId = $this->transactionModel->create([
                        'condominium_id' => $condominiumId,
                        'bank_account_id' => $transferToAccountId,
                        'transaction_type' => 'income',
                        'amount' => $rowData['amount'],
                        'transaction_date' => $rowData['transaction_date'],
                        'description' => 'Transferência recebida de ' . $fromAccountName . ': ' . $transferDescription,
                        'category' => $rowData['category'] ?? 'Transferência',
                        'reference' => $rowData['reference'] ?? null,
                        'related_type' => 'transfer',
                        'related_id' => $fromTransactionId,
                        'transfer_to_account_id' => null,
                        'created_by' => $userId
                    ]);

                    // Update related_id in from transaction (direct SQL update since it's not in allowed fields)
                    global $db;
                    $stmt = $db->prepare("UPDATE financial_transactions SET related_id = :related_id WHERE id = :id");
                    $stmt->execute([
                        ':related_id' => $toTransactionId,
                        ':id' => $fromTransactionId
                    ]);

                    $transferCount++;
                    $successCount++;
                } elseif ($isQuotaLiquidation) {
                    // Quota liquidation transaction
                    $faModel = new FractionAccount();
                    $account = $faModel->getOrCreate($quotaFractionId, $condominiumId);
                    $accountId = (int)$account['id'];

                    // Reference for quota payment
                    $ref = 'REF' . $condominiumId . $quotaFractionId . date('YmdHis') . $index;

                    $quotaDescription = $rowData['description'] ?? 'Movimento importado';
                    $transactionId = $this->transactionModel->create([
                        'condominium_id' => $condominiumId,
                        'bank_account_id' => $rowData['bank_account_id'],
                        'fraction_id' => $quotaFractionId,
                        'transaction_type' => 'income',
                        'amount' => $rowData['amount'],
                        'transaction_date' => $rowData['transaction_date'],
                        'description' => $quotaDescription,
                        'category' => 'Quotas',
                        'income_entry_type' => 'quota',
                        'reference' => $ref,
                        'related_type' => 'fraction_account',
                        'related_id' => $accountId,
                        'transfer_to_account_id' => null,
                        'created_by' => $userId
                    ]);

                    $movementId = $faModel->addCredit($accountId, $rowData['amount'], 'quota_payment', $transactionId, $quotaDescription);

                    // Liquidate quotas
                    $liquidationService = new LiquidationService();
                    $result = $liquidationService->liquidateSelectedFees(
                        $quotaFractionId,
                        $selectedFeeIds,
                        $userId,
                        $rowData['transaction_date'],
                        $transactionId,
                        $quotaRemainingDest
                    );

                    // Update description with liquidated fees
                    $parts = [];
                    foreach ($result['fully_paid'] ?? [] as $fid) {
                        $f = $this->feeModel->findById($fid);
                        $parts[] = $f ? self::feeLabel($f) : ('Quota #' . $fid);
                    }
                    foreach (array_keys($result['partially_paid'] ?? []) as $fid) {
                        $f = $this->feeModel->findById($fid);
                        $parts[] = ($f ? self::feeLabel($f) : ('Quota #' . $fid)) . ' (parcial)';
                    }
                    $creditRemaining = (float)($result['credit_remaining'] ?? 0);
                    if ($creditRemaining > 0 && $quotaRemainingDest !== 'oldest') {
                        $remainingLabels = ['unregistered' => 'Valor restante para quotas antigas não registadas', 'balance' => 'Valor restante em saldo'];
                        $parts[] = ($remainingLabels[$quotaRemainingDest] ?? '') . ': ' . number_format($creditRemaining, 2, ',', '.') . ' €';
                    }
                    $builtDesc = implode(', ', $parts);
                    if ($builtDesc !== '') {
                        $this->transactionModel->update($transactionId, ['description' => $builtDesc]);
                        (new FractionAccountMovement())->update($movementId, ['description' => $builtDesc]);
                    }

                    // Log automatic liquidation via import
                    $fullyPaidCount = count($result['fully_paid'] ?? []);
                    $partiallyPaidCount = count($result['partially_paid'] ?? []);
                    $this->auditService->logFinancial([
                        'condominium_id' => $condominiumId,
                        'entity_type' => 'financial_transaction',
                        'entity_id' => $transactionId,
                        'action' => 'quotas_liquidated_import',
                        'user_id' => $userId,
                        'amount' => $rowData['amount'],
                        'new_status' => 'completed',
                        'description' => "Liquidação de quotas via importação. Valor: €" . number_format($rowData['amount'], 2, ',', '.') . ". Quotas totalmente pagas: {$fullyPaidCount}, Pagamentos parciais: {$partiallyPaidCount}" . (!empty($selectedFeeIds) ? " (quotas selecionadas: " . count($selectedFeeIds) . ")" : "")
                    ]);

                    // Generate receipts for fully paid fees
                    $receiptSvc = new ReceiptService();
                    foreach ($result['fully_paid'] ?? [] as $fid) {
                        $receiptSvc->generateForFullyPaidFee($fid, $result['fully_paid_payments'][$fid] ?? null, $condominiumId, $userId);
                    }

                    $successCount++;
                } else {
                    // Regular transaction
                    $this->transactionModel->create([
                        'condominium_id' => $condominiumId,
                        'bank_account_id' => $rowData['bank_account_id'],
                        'transaction_type' => $rowData['transaction_type'],
                        'amount' => $rowData['amount'],
                        'transaction_date' => $rowData['transaction_date'],
                        'description' => $rowData['description'] ?? 'Movimento importado',
                        'category' => $rowData['category'] ?? null,
                        'reference' => $rowData['reference'] ?? null,
                        'related_type' => 'manual',
                        'related_id' => null,
                        'transfer_to_account_id' => null,
                        'created_by' => $userId
                    ]);

                    $successCount++;
                }
            }

            $db->commit();
            
            // Clear session data
            unset($_SESSION['import_data']);

            $message = "Importação concluída: {$successCount} movimento(s) criado(s)";
            if ($transferCount > 0) {
                $message .= " ({$transferCount} transferência(s) - " . ($transferCount * 2) . " movimentos)";
            }
            if ($errorCount > 0) {
                $message .= ", {$errorCount} erro(s)";
            }

            $_SESSION['success'] = $message;
            
            if ($errorCount > 0) {
                $_SESSION['import_errors'] = $errors;
            }
        } catch (\Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $_SESSION['error'] = 'Erro ao processar importação: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
        exit;
    }

    public function getPendingFees(int $condominiumId, int $fractionId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        header('Content-Type: application/json');

        try {
            // Validate fraction belongs to condominium
            $fractionModel = new Fraction();
            $fraction = $fractionModel->findById($fractionId);
            
            if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
                echo json_encode(['success' => false, 'error' => 'Fração não encontrada ou não pertence ao condomínio']);
                exit;
            }

            // Get pending fees for the fraction
            $feeModel = new Fee();
            $fees = $feeModel->getPendingOrderedForLiquidation($fractionId);
            
            // Get paid amounts for each fee
            $feePaymentModel = new FeePayment();
            $pendingFees = [];
            
            foreach ($fees as $fee) {
                $feeId = (int)$fee['id'];
                $totalAmount = (float)$fee['amount'];
                $paidAmount = $feePaymentModel->getTotalPaid($feeId);
                $pendingAmount = $totalAmount - $paidAmount;
                
                if ($pendingAmount > 0) {
                    // Format fee label
                    $label = self::feeLabel($fee);
                    
                    $pendingFees[] = [
                        'id' => $feeId,
                        'label' => $label,
                        'total_amount' => $totalAmount,
                        'paid_amount' => $paidAmount,
                        'pending_amount' => $pendingAmount,
                        'period_year' => $fee['period_year'] ?? null,
                        'period_month' => $fee['period_month'] ?? null,
                        'fee_type' => $fee['fee_type'] ?? 'regular',
                        'reference' => $fee['reference'] ?? null
                    ];
                }
            }

            echo json_encode([
                'success' => true,
                'fees' => $pendingFees
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function getFractionBalance(int $condominiumId, int $fractionId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        header('Content-Type: application/json');

        try {
            // Validate fraction belongs to condominium
            $fractionModel = new Fraction();
            $fraction = $fractionModel->findById($fractionId);
            
            if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
                echo json_encode(['success' => false, 'error' => 'Fração não encontrada ou não pertence ao condomínio']);
                exit;
            }

            // Get fraction account balance
            $faModel = new FractionAccount();
            $account = $faModel->getByFraction($fractionId);
            $balance = $account ? (float)$account['balance'] : 0.0;

            echo json_encode([
                'success' => true,
                'balance' => $balance,
                'has_balance' => $balance > 0
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
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

    public function liquidateQuotas(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = 'Método inválido.';
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

        // Validate transaction type
        if ($transaction['transaction_type'] !== 'income') {
            $_SESSION['error'] = 'Apenas movimentos de entrada podem liquidar quotas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        // Check if already linked to fee payment
        if ($this->transactionModel->isLinkedToFeePayment($id)) {
            $_SESSION['error'] = 'Este movimento já está associado a pagamentos de quotas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        $fractionId = !empty($_POST['fraction_id']) ? (int)$_POST['fraction_id'] : null;
        $selectedFeeIds = !empty($_POST['selected_fee_ids']) ? array_map('intval', $_POST['selected_fee_ids']) : [];
        $remainingDestination = $_POST['remaining_destination'] ?? 'oldest';
        if (!in_array($remainingDestination, ['oldest', 'unregistered', 'balance'])) {
            $remainingDestination = 'oldest';
        }

        if (!$fractionId) {
            $_SESSION['error'] = 'Por favor, selecione uma fração.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        // Validate fraction belongs to condominium
        $fractionModel = new Fraction();
        $fraction = $fractionModel->findById($fractionId);
        if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Fração inválida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }

        try {
            $userId = AuthMiddleware::userId();
            $transactionAmount = (float)$transaction['amount'];
            $paymentDate = $transaction['transaction_date'];
            $useFractionBalance = !empty($_POST['use_fraction_balance']);

            // Get fraction account
            $faModel = new FractionAccount();
            $account = $faModel->getOrCreate($fractionId, $condominiumId);
            $accountId = (int)$account['id'];
            
            // Get existing balance before adding credit (if using balance)
            $existingBalance = 0.0;
            if ($useFractionBalance) {
                $existingBalance = (float)($account['balance'] ?? 0.0);
            }

            // Add credit to fraction account from transaction
            $movementId = $faModel->addCredit(
                $accountId,
                $transactionAmount,
                'quota_payment',
                $id,
                'Crédito por liquidação de quotas - Movimento #' . $id
            );

            // Liquidate fees (will use existing balance + transaction credit)
            $liquidationService = new LiquidationService();
            $result = $liquidationService->liquidateSelectedFees(
                $fractionId,
                $selectedFeeIds,
                $userId,
                $paymentDate,
                $id,
                $remainingDestination
            );

            // Update transaction - complement description with fraction and set reference for receipts
            $fractionIdentifier = $fraction['identifier'] ?? '';
            $description = $transaction['description'];
            if ($fractionIdentifier) {
                $description .= ' | Fração ' . $fractionIdentifier;
            }
            $fullyPaidCount = is_array($result['fully_paid']) ? count($result['fully_paid']) : (int)$result['fully_paid'];
            $partiallyPaidCount = is_array($result['partially_paid']) ? count($result['partially_paid']) : (int)$result['partially_paid'];
            
            if ($fullyPaidCount > 0 || $partiallyPaidCount > 0) {
                $liquidationDetails = [];
                if ($fullyPaidCount > 0) {
                    $liquidationDetails[] = $fullyPaidCount . ' quota(s) totalmente paga(s)';
                }
                if ($partiallyPaidCount > 0) {
                    $liquidationDetails[] = $partiallyPaidCount . ' quota(s) parcialmente paga(s)';
                }
                $description .= ' | ' . implode(', ', $liquidationDetails);
            }
            $creditRemaining = (float)($result['credit_remaining'] ?? 0);
            if ($creditRemaining > 0 && $remainingDestination !== 'oldest') {
                $remainingLabels = [
                    'unregistered' => 'Valor restante para quotas antigas não registadas',
                    'balance' => 'Valor restante em saldo'
                ];
                $description .= ' | ' . ($remainingLabels[$remainingDestination] ?? '') . ': ' . number_format($creditRemaining, 2, ',', '.') . ' €';
            }

            $reference = 'REF' . $condominiumId . $fractionId . date('YmdHis') . $id;

            $this->transactionModel->update($id, [
                'fraction_id' => $fractionId,
                'income_entry_type' => 'quota',
                'category' => 'Quotas',
                'related_type' => 'fee_payment',
                'description' => $description,
                'reference' => $reference
            ]);

            // Update fraction account movement description
            if ($movementId) {
                $builtDesc = 'Crédito por liquidação de quotas - Fração ' . $fractionIdentifier . ' - Movimento #' . $id;
                if ($fullyPaidCount > 0 || $partiallyPaidCount > 0) {
                    $liquidationDetails = [];
                    if ($fullyPaidCount > 0) {
                        $liquidationDetails[] = $fullyPaidCount . ' quota(s) totalmente paga(s)';
                    }
                    if ($partiallyPaidCount > 0) {
                        $liquidationDetails[] = $partiallyPaidCount . ' quota(s) parcialmente paga(s)';
                    }
                    $builtDesc .= ' | ' . implode(', ', $liquidationDetails);
                }
                if ($creditRemaining > 0 && $remainingDestination !== 'oldest') {
                    $remainingLabels = ['unregistered' => 'Restante para quotas antigas não registadas', 'balance' => 'Restante em saldo'];
                    $builtDesc .= ' | ' . ($remainingLabels[$remainingDestination] ?? '') . ': ' . number_format($creditRemaining, 2, ',', '.') . ' €';
                }
                (new FractionAccountMovement())->update($movementId, ['description' => $builtDesc]);
            }

            // Generate receipts for fully paid fees
            if ($fullyPaidCount > 0 && is_array($result['fully_paid'])) {
                $receiptService = new ReceiptService();
                foreach ($result['fully_paid'] as $feeId) {
                    try {
                        $receiptService->generateForFullyPaidFee($feeId, null, $condominiumId, $userId);
                    } catch (\Exception $e) {
                        // Log error but don't fail the whole operation
                        error_log("Error generating receipt for fee {$feeId}: " . $e->getMessage());
                    }
                }
            }

            // Log audit
            $auditDescription = "Liquidação de quotas aplicada ao movimento #{$id} (Fração: {$fractionId}, Taxas selecionadas: " . count($selectedFeeIds) . ", Totalmente pagas: {$fullyPaidCount}, Parcialmente pagas: {$partiallyPaidCount}";
            if ($useFractionBalance && $existingBalance > 0) {
                $auditDescription .= ", Saldo da fração utilizado: " . number_format($existingBalance, 2, ',', '.') . "€";
            }
            $auditDescription .= ", Crédito restante: " . number_format($result['credit_remaining'] ?? 0, 2, ',', '.') . "€)";
            
            $this->auditService->log([
                'user_id' => $userId,
                'action' => 'quota_liquidation',
                'model' => 'financial_transaction',
                'model_id' => $id,
                'description' => $auditDescription
            ]);

            $_SESSION['success'] = 'Quotas liquidadas com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao liquidar quotas: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/financial-transactions');
            exit;
        }
    }
}
