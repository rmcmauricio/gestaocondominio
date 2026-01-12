<?php

class AddTransferSupportToFinancialTransactions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add transfer_to_account_id column to store destination account for transfers
        $stmt = $this->db->query("SHOW COLUMNS FROM financial_transactions LIKE 'transfer_to_account_id'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE financial_transactions ADD COLUMN transfer_to_account_id INT NULL AFTER bank_account_id");
            $this->db->exec("ALTER TABLE financial_transactions ADD CONSTRAINT fk_transfer_to_account FOREIGN KEY (transfer_to_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT");
            $this->db->exec("ALTER TABLE financial_transactions ADD INDEX idx_transfer_to_account_id (transfer_to_account_id)");
        }

        // Update related_type ENUM to include 'transfer'
        $this->db->exec("ALTER TABLE financial_transactions MODIFY COLUMN related_type ENUM('fee_payment', 'expense', 'revenue', 'manual', 'transfer') DEFAULT 'manual'");
    }

    public function down(): void
    {
        // Remove transfer_to_account_id column
        $stmt = $this->db->query("SHOW COLUMNS FROM financial_transactions LIKE 'transfer_to_account_id'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE financial_transactions DROP FOREIGN KEY fk_transfer_to_account");
            $this->db->exec("ALTER TABLE financial_transactions DROP INDEX idx_transfer_to_account_id");
            $this->db->exec("ALTER TABLE financial_transactions DROP COLUMN transfer_to_account_id");
        }

        // Revert related_type ENUM
        $this->db->exec("ALTER TABLE financial_transactions MODIFY COLUMN related_type ENUM('fee_payment', 'expense', 'revenue', 'manual') DEFAULT 'manual'");
    }
}
