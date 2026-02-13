<?php

namespace App\Models;

use App\Core\Model;

class FinancialTransaction extends Model
{
    protected $table = 'financial_transactions';

    /**
     * Get transactions by account
     */
    public function getByAccount(int $accountId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT ft.*, ba.name as account_name, ba.account_type 
                FROM financial_transactions ft
                LEFT JOIN bank_accounts ba ON ba.id = ft.bank_account_id
                WHERE ft.bank_account_id = :account_id";
        $params = [':account_id' => $accountId];

        if (isset($filters['transaction_type'])) {
            $sql .= " AND transaction_type = :transaction_type";
            $params[':transaction_type'] = $filters['transaction_type'];
        }

        if (isset($filters['from_date'])) {
            $sql .= " AND transaction_date >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }

        if (isset($filters['to_date'])) {
            $sql .= " AND transaction_date <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }

        $sql .= " ORDER BY transaction_date DESC, created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get transactions by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT ft.*, ba.name as account_name, ba.account_type, 
                       ba2.name as transfer_account_name, ba2.account_type as transfer_account_type,
                       u.name as created_by_name,
                       fr.identifier as fraction_identifier
                FROM financial_transactions ft
                LEFT JOIN bank_accounts ba ON ba.id = ft.bank_account_id
                LEFT JOIN bank_accounts ba2 ON ba2.id = ft.transfer_account_id
                LEFT JOIN users u ON u.id = ft.created_by
                LEFT JOIN fractions fr ON fr.id = ft.fraction_id
                WHERE ft.condominium_id = :condominium_id";
        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['bank_account_id'])) {
            $sql .= " AND ft.bank_account_id = :bank_account_id";
            $params[':bank_account_id'] = $filters['bank_account_id'];
        }

        if (isset($filters['transaction_type'])) {
            $sql .= " AND ft.transaction_type = :transaction_type";
            $params[':transaction_type'] = $filters['transaction_type'];
        }

        if (isset($filters['account_type'])) {
            $sql .= " AND ba.account_type = :account_type";
            $params[':account_type'] = $filters['account_type'];
        }

        if (isset($filters['from_date'])) {
            $sql .= " AND ft.transaction_date >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }

        if (isset($filters['to_date'])) {
            $sql .= " AND ft.transaction_date <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND ft.category = :category";
            $params[':category'] = $filters['category'];
        }

        if (isset($filters['has_category']) && $filters['has_category'] === false) {
            $sql .= " AND (ft.category IS NULL OR TRIM(ft.category) = '')";
        }

        if (!empty($filters['exclude_transfers'])) {
            $sql .= " AND (ft.related_type IS NULL OR ft.related_type != 'transfer')";
        }

        $sql .= " ORDER BY ft.transaction_date DESC, ft.created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (isset($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get count of transactions by condominium (for pagination)
     */
    public function getCountByCondominium(int $condominiumId, array $filters = []): int
    {
        if (!$this->db) {
            return 0;
        }

        $sql = "SELECT COUNT(*) as total
                FROM financial_transactions ft
                WHERE ft.condominium_id = :condominium_id";
        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['bank_account_id'])) {
            $sql .= " AND ft.bank_account_id = :bank_account_id";
            $params[':bank_account_id'] = $filters['bank_account_id'];
        }

        if (isset($filters['transaction_type'])) {
            $sql .= " AND ft.transaction_type = :transaction_type";
            $params[':transaction_type'] = $filters['transaction_type'];
        }

        if (isset($filters['from_date'])) {
            $sql .= " AND ft.transaction_date >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }

        if (isset($filters['to_date'])) {
            $sql .= " AND ft.transaction_date <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }

        if (isset($filters['has_category']) && $filters['has_category'] === false) {
            $sql .= " AND (ft.category IS NULL OR TRIM(ft.category) = '')";
        }

        if (!empty($filters['category'])) {
            $sql .= " AND ft.category = :category";
            $params[':category'] = $filters['category'];
        }

        if (!empty($filters['exclude_transfers'])) {
            $sql .= " AND (ft.related_type IS NULL OR ft.related_type != 'transfer')";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return (int)($result['total'] ?? 0);
    }

    /**
     * Get transactions by related entity
     */
    public function getByRelated(string $relatedType, int $relatedId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM financial_transactions 
            WHERE related_type = :related_type 
            AND related_id = :related_id
            LIMIT 1
        ");
        $stmt->execute([
            ':related_type' => $relatedType,
            ':related_id' => $relatedId
        ]);
        
        return $stmt->fetch() ?: null;
    }

    /**
     * Calculate account balance
     */
    public function calculateAccountBalance(int $accountId): float
    {
        if (!$this->db) {
            return 0.0;
        }

        // Get initial balance
        $stmt = $this->db->prepare("SELECT initial_balance FROM bank_accounts WHERE id = :id");
        $stmt->execute([':id' => $accountId]);
        $account = $stmt->fetch();
        
        if (!$account) {
            return 0.0;
        }

        $initialBalance = (float)$account['initial_balance'];

        // Calculate total income
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM financial_transactions 
            WHERE bank_account_id = :account_id 
            AND transaction_type = 'income'
        ");
        $stmt->execute([':account_id' => $accountId]);
        $income = $stmt->fetch();
        $totalIncome = (float)($income['total'] ?? 0);

        // Calculate total expense
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM financial_transactions 
            WHERE bank_account_id = :account_id 
            AND transaction_type = 'expense'
        ");
        $stmt->execute([':account_id' => $accountId]);
        $expense = $stmt->fetch();
        $totalExpense = (float)($expense['total'] ?? 0);

        return $initialBalance + $totalIncome - $totalExpense;
    }

    /**
     * Create transaction from fee payment
     */
    public function createFromFeePayment(array $feePayment, int $bankAccountId, int $userId): int
    {
        $description = "Pagamento de quota";
        if (!empty($feePayment['reference'])) {
            $description .= " - Ref: " . $feePayment['reference'];
        }
        if (!empty($feePayment['notes'])) {
            $description .= " - " . $feePayment['notes'];
        }

        return $this->create([
            'condominium_id' => $feePayment['condominium_id'],
            'bank_account_id' => $bankAccountId,
            'transaction_type' => 'income',
            'amount' => $feePayment['amount'],
            'transaction_date' => $feePayment['payment_date'],
            'description' => $description,
            'category' => 'Quotas',
            'reference' => $feePayment['reference'] ?? null,
            'related_type' => 'fee_payment',
            'related_id' => $feePayment['id'],
            'created_by' => $userId
        ]);
    }

    /**
     * Create transaction
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO financial_transactions (
                condominium_id, bank_account_id, fraction_id, transfer_account_id, transaction_type, amount, transaction_date,
                description, category, income_entry_type, reference, related_type, related_id, created_by
            )
            VALUES (
                :condominium_id, :bank_account_id, :fraction_id, :transfer_account_id, :transaction_type, :amount, :transaction_date,
                :description, :category, :income_entry_type, :reference, :related_type, :related_id, :created_by
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':bank_account_id' => $data['bank_account_id'],
            ':fraction_id' => $data['fraction_id'] ?? null,
            ':transfer_account_id' => $data['transfer_account_id'] ?? null,
            ':transaction_type' => $data['transaction_type'],
            ':amount' => $data['amount'],
            ':transaction_date' => $data['transaction_date'],
            ':description' => $data['description'],
            ':category' => $data['category'] ?? null,
            ':income_entry_type' => $data['income_entry_type'] ?? null,
            ':reference' => $data['reference'] ?? null,
            ':related_type' => $data['related_type'] ?? 'manual',
            ':related_id' => $data['related_id'] ?? null,
            ':created_by' => $data['created_by'] ?? null
        ]);

        $transactionId = (int)$this->db->lastInsertId();

        // Log audit
        $this->auditCreate($transactionId, $data);

        // Update account balance
        $bankAccountModel = new BankAccount();
        $bankAccountModel->updateBalance($data['bank_account_id']);
        
        // If it's a transfer, also update the counterpart account balance
        if (!empty($data['transfer_account_id'])) {
            $bankAccountModel->updateBalance($data['transfer_account_id']);
        }

        return $transactionId;
    }

    /**
     * Update transaction
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        // Get old transaction to update balance
        $oldTransaction = $this->findById($id);
        if (!$oldTransaction) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['bank_account_id', 'transaction_type', 'amount', 'transaction_date', 'description', 'category', 'reference', 'fraction_id', 'income_entry_type', 'related_type', 'related_id', 'transfer_account_id'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE financial_transactions SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            // Log audit
            $this->auditUpdate($id, $data, $oldTransaction);
            
            // Update balances for both old and new accounts
            $bankAccountModel = new BankAccount();
            $bankAccountModel->updateBalance($oldTransaction['bank_account_id']);
            
            if (isset($data['bank_account_id']) && $data['bank_account_id'] != $oldTransaction['bank_account_id']) {
                $bankAccountModel->updateBalance($data['bank_account_id']);
            }
        }

        return $result;
    }

    /**
     * Delete transaction
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Get transaction to update balance
        $transaction = $this->findById($id);
        if (!$transaction) {
            return false;
        }

        $transferAccountId = $transaction['transfer_account_id'] ?? null;
        
        $stmt = $this->db->prepare("DELETE FROM financial_transactions WHERE id = :id");
        $result = $stmt->execute([':id' => $id]);

        if ($result) {
            // Log audit
            $this->auditDelete($id, $transaction);
            
            // Update account balance
            $bankAccountModel = new BankAccount();
            $bankAccountModel->updateBalance($transaction['bank_account_id']);
            
            // If it was a transfer, also update the counterpart account balance
            if ($transferAccountId) {
                $bankAccountModel->updateBalance($transferAccountId);
            }
        }

        return $result;
    }

    /**
     * Get total amount by condominium, period and transaction type
     */
    public function getTotalByPeriodAndType(int $condominiumId, string $startDate, string $endDate, string $transactionType): float
    {
        if (!$this->db) {
            return 0.0;
        }

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM financial_transactions
            WHERE condominium_id = :condominium_id
            AND transaction_type = :transaction_type
            AND (related_type IS NULL OR related_type != 'transfer')
            AND transaction_date BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':transaction_type' => $transactionType,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        $result = $stmt->fetch();
        return (float)($result['total'] ?? 0);
    }

    /**
     * Get income transactions grouped by category
     */
    public function getIncomesByCategory(int $condominiumId, string $startDate, string $endDate): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT COALESCE(NULLIF(TRIM(category), ''), 'Sem categoria') as category, SUM(amount) as total, COUNT(*) as count
            FROM financial_transactions
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'income'
            AND (related_type IS NULL OR related_type != 'transfer')
            AND transaction_date BETWEEN :start_date AND :end_date
            GROUP BY COALESCE(NULLIF(TRIM(category), ''), 'Sem categoria')
            ORDER BY total DESC
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get expenses grouped by category (for reports)
     */
    public function getExpensesByCategory(int $condominiumId, string $startDate, string $endDate): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT COALESCE(NULLIF(TRIM(category), ''), 'Sem categoria') as category, SUM(amount) as total, COUNT(*) as count
            FROM financial_transactions
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'expense'
            AND (related_type IS NULL OR related_type != 'transfer')
            AND transaction_date BETWEEN :start_date AND :end_date
            GROUP BY COALESCE(NULLIF(TRIM(category), ''), 'Sem categoria')
            ORDER BY total DESC
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get expenses grouped by category and year (for evolution report).
     * Returns one row per (year, category) with total and count.
     */
    public function getExpensesByCategoryByYear(int $condominiumId, int $startYear, int $endYear): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT YEAR(transaction_date) as year,
                   COALESCE(NULLIF(TRIM(category), ''), 'Sem categoria') as category,
                   SUM(amount) as total,
                   COUNT(*) as count
            FROM financial_transactions
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'expense'
            AND (related_type IS NULL OR related_type != 'transfer')
            AND transaction_date >= :start_date
            AND transaction_date <= :end_date
            GROUP BY YEAR(transaction_date), COALESCE(NULLIF(TRIM(category), ''), 'Sem categoria')
            ORDER BY year ASC, total DESC
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => "{$startYear}-01-01",
            ':end_date' => "{$endYear}-12-31"
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get expenses totals by month for a year (for evolution chart)
     */
    public function getExpensesByMonth(int $condominiumId, string $startDate, string $endDate): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT MONTH(transaction_date) as month, SUM(amount) as total
            FROM financial_transactions
            WHERE condominium_id = :condominium_id
            AND transaction_type = 'expense'
            AND (related_type IS NULL OR related_type != 'transfer')
            AND transaction_date BETWEEN :start_date AND :end_date
            GROUP BY MONTH(transaction_date)
            ORDER BY month
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[(int)$r['month']] = (float)$r['total'];
        }
        return $byMonth;
    }

    /**
     * Find transaction by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM financial_transactions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get amount already used from this transaction for quota liquidation.
     * Used = fee_payments (direct association) + fraction_account_movements debits (via liquidate flow).
     */
    public function getAmountUsedForQuotas(int $transactionId): float
    {
        if (!$this->db) {
            return 0.0;
        }

        // From fee_payments (registar pagamento associando a movimento)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM fee_payments
            WHERE financial_transaction_id = :id
        ");
        $stmt->execute([':id' => $transactionId]);
        $fp = $stmt->fetch();
        $fromPayments = (float)($fp['total'] ?? 0);

        // From fraction_account_movements debits (liquidar quotas from transaction)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM fraction_account_movements
            WHERE source_financial_transaction_id = :id AND type = 'debit'
        ");
        $stmt->execute([':id' => $transactionId]);
        $fam = $stmt->fetch();
        $fromDebits = (float)($fam['total'] ?? 0);

        return $fromPayments + $fromDebits;
    }

    /**
     * Check if transaction is linked to fee payment
     */
    public function isLinkedToFeePayment(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM fee_payments 
            WHERE financial_transaction_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        
        return ($result['count'] ?? 0) > 0;
    }
}
