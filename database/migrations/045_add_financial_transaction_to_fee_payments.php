<?php

class AddFinancialTransactionToFeePayments
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add financial_transaction_id column
        $stmt = $this->db->query("SHOW COLUMNS FROM fee_payments LIKE 'financial_transaction_id'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE fee_payments ADD COLUMN financial_transaction_id INT NULL AFTER id");
            $this->db->exec("ALTER TABLE fee_payments ADD CONSTRAINT fk_fee_payments_transaction FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions(id) ON DELETE RESTRICT");
            $this->db->exec("ALTER TABLE fee_payments ADD INDEX idx_financial_transaction_id (financial_transaction_id)");
        }

        // Create default cash account for each condominium if it doesn't exist
        $condominiums = $this->db->query("SELECT id FROM condominiums")->fetchAll();
        
        foreach ($condominiums as $condominium) {
            $condominiumId = $condominium['id'];
            
            // Check if cash account exists
            $stmt = $this->db->prepare("SELECT id FROM bank_accounts WHERE condominium_id = :condominium_id AND account_type = 'cash' LIMIT 1");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $cashAccount = $stmt->fetch();
            
            if (!$cashAccount) {
                // Create cash account
                $stmt = $this->db->prepare("
                    INSERT INTO bank_accounts (condominium_id, name, account_type, initial_balance, current_balance, is_active)
                    VALUES (:condominium_id, 'Caixa', 'cash', 0.00, 0.00, TRUE)
                ");
                $stmt->execute([':condominium_id' => $condominiumId]);
            }
        }

        // Create retroactive financial transactions for existing fee_payments
        $payments = $this->db->query("
            SELECT fp.id, fp.fee_id, fp.amount, fp.payment_date, fp.payment_method, fp.reference, fp.notes, fp.created_by,
                   f.condominium_id
            FROM fee_payments fp
            INNER JOIN fees f ON f.id = fp.fee_id
            WHERE fp.financial_transaction_id IS NULL
        ")->fetchAll();

        foreach ($payments as $payment) {
            // Get cash account for this condominium
            $stmt = $this->db->prepare("SELECT id FROM bank_accounts WHERE condominium_id = :condominium_id AND account_type = 'cash' LIMIT 1");
            $stmt->execute([':condominium_id' => $payment['condominium_id']]);
            $cashAccount = $stmt->fetch();
            
            if (!$cashAccount) {
                continue; // Skip if no cash account found
            }

            // Create financial transaction
            $description = "Pagamento de quota";
            if ($payment['reference']) {
                $description .= " - Ref: " . $payment['reference'];
            }
            if ($payment['notes']) {
                $description .= " - " . $payment['notes'];
            }

            $stmt = $this->db->prepare("
                INSERT INTO financial_transactions (
                    condominium_id, bank_account_id, transaction_type, amount, transaction_date,
                    description, category, reference, related_type, related_id, created_by
                )
                VALUES (
                    :condominium_id, :bank_account_id, 'income', :amount, :transaction_date,
                    :description, :category, :reference, 'fee_payment', :related_id, :created_by
                )
            ");

            $stmt->execute([
                ':condominium_id' => $payment['condominium_id'],
                ':bank_account_id' => $cashAccount['id'],
                ':amount' => $payment['amount'],
                ':transaction_date' => $payment['payment_date'],
                ':description' => $description,
                ':category' => 'Quotas',
                ':reference' => $payment['reference'],
                ':related_id' => $payment['id'],
                ':created_by' => $payment['created_by']
            ]);

            $transactionId = $this->db->lastInsertId();

            // Update fee_payment with transaction_id
            $stmt = $this->db->prepare("UPDATE fee_payments SET financial_transaction_id = :transaction_id WHERE id = :id");
            $stmt->execute([
                ':transaction_id' => $transactionId,
                ':id' => $payment['id']
            ]);
        }

        // Update current_balance for all accounts
        $accounts = $this->db->query("SELECT id FROM bank_accounts")->fetchAll();
        foreach ($accounts as $account) {
            $this->updateAccountBalance($account['id']);
        }
    }

    protected function updateAccountBalance(int $accountId): void
    {
        // Get initial balance
        $stmt = $this->db->prepare("SELECT initial_balance FROM bank_accounts WHERE id = :id");
        $stmt->execute([':id' => $accountId]);
        $account = $stmt->fetch();
        
        if (!$account) {
            return;
        }

        $initialBalance = (float)$account['initial_balance'];

        // Calculate total income
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE bank_account_id = :account_id AND transaction_type = 'income'");
        $stmt->execute([':account_id' => $accountId]);
        $income = $stmt->fetch();
        $totalIncome = (float)($income['total'] ?? 0);

        // Calculate total expense
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE bank_account_id = :account_id AND transaction_type = 'expense'");
        $stmt->execute([':account_id' => $accountId]);
        $expense = $stmt->fetch();
        $totalExpense = (float)($expense['total'] ?? 0);

        // Update current balance
        $currentBalance = $initialBalance + $totalIncome - $totalExpense;
        
        $stmt = $this->db->prepare("UPDATE bank_accounts SET current_balance = :balance WHERE id = :id");
        $stmt->execute([
            ':balance' => $currentBalance,
            ':id' => $accountId
        ]);
    }

    public function down(): void
    {
        // Remove foreign key constraint
        $this->db->exec("ALTER TABLE fee_payments DROP FOREIGN KEY IF EXISTS fk_fee_payments_transaction");
        
        // Remove column
        $this->db->exec("ALTER TABLE fee_payments DROP COLUMN IF EXISTS financial_transaction_id");
    }
}
