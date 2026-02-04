<?php

class AddFractionAndIncomeEntryToFinancialTransactions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add fraction_id (for income assigned to a fraction's account)
        $stmt = $this->db->query("SHOW COLUMNS FROM financial_transactions LIKE 'fraction_id'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE financial_transactions ADD COLUMN fraction_id INT NULL AFTER bank_account_id");
            $this->db->exec("ALTER TABLE financial_transactions ADD CONSTRAINT fk_financial_transaction_fraction FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE SET NULL");
            $this->db->exec("ALTER TABLE financial_transactions ADD INDEX idx_financial_transaction_fraction (fraction_id)");
        }

        // Add income_entry_type (quota, reserva_espaco, outros)
        $stmt = $this->db->query("SHOW COLUMNS FROM financial_transactions LIKE 'income_entry_type'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE financial_transactions ADD COLUMN income_entry_type ENUM('quota', 'reserva_espaco', 'outros') NULL AFTER category");
        }

        // Add 'fraction_account' to related_type ENUM
        $this->db->exec("ALTER TABLE financial_transactions MODIFY COLUMN related_type ENUM('fee_payment', 'expense', 'revenue', 'manual', 'transfer', 'fraction_account') DEFAULT 'manual'");
    }

    public function down(): void
    {
        // Remove fraction_id
        $stmt = $this->db->query("SHOW COLUMNS FROM financial_transactions LIKE 'fraction_id'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE financial_transactions DROP FOREIGN KEY fk_financial_transaction_fraction");
            $this->db->exec("ALTER TABLE financial_transactions DROP INDEX idx_financial_transaction_fraction");
            $this->db->exec("ALTER TABLE financial_transactions DROP COLUMN fraction_id");
        }

        // Remove income_entry_type
        $stmt = $this->db->query("SHOW COLUMNS FROM financial_transactions LIKE 'income_entry_type'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE financial_transactions DROP COLUMN income_entry_type");
        }

        // Revert related_type ENUM (remove fraction_account)
        $this->db->exec("ALTER TABLE financial_transactions MODIFY COLUMN related_type ENUM('fee_payment', 'expense', 'revenue', 'manual', 'transfer') DEFAULT 'manual'");
    }
}
