<?php

class AddSourceFinancialTransactionToFractionAccountMovements
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM fraction_account_movements LIKE 'source_financial_transaction_id'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE fraction_account_movements ADD COLUMN source_financial_transaction_id INT NULL AFTER source_reference_id");
            $this->db->exec("ALTER TABLE fraction_account_movements ADD CONSTRAINT fk_fam_source_ft FOREIGN KEY (source_financial_transaction_id) REFERENCES financial_transactions(id) ON DELETE SET NULL");
            $this->db->exec("ALTER TABLE fraction_account_movements ADD INDEX idx_fam_source_ft (source_financial_transaction_id)");
        }
    }

    public function down(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM fraction_account_movements LIKE 'source_financial_transaction_id'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE fraction_account_movements DROP FOREIGN KEY fk_fam_source_ft");
            $this->db->exec("ALTER TABLE fraction_account_movements DROP INDEX idx_fam_source_ft");
            $this->db->exec("ALTER TABLE fraction_account_movements DROP COLUMN source_financial_transaction_id");
        }
    }
}
