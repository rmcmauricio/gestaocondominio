<?php

class RenameTransferToAccountToTransferAccount
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM financial_transactions LIKE 'transfer_to_account_id'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE financial_transactions DROP FOREIGN KEY fk_transfer_to_account");
            $this->db->exec("ALTER TABLE financial_transactions DROP INDEX idx_transfer_to_account_id");
            $this->db->exec("ALTER TABLE financial_transactions CHANGE COLUMN transfer_to_account_id transfer_account_id INT NULL");
            $this->db->exec("ALTER TABLE financial_transactions ADD CONSTRAINT fk_transfer_account FOREIGN KEY (transfer_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT");
            $this->db->exec("ALTER TABLE financial_transactions ADD INDEX idx_transfer_account_id (transfer_account_id)");
        }
    }

    public function down(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM financial_transactions LIKE 'transfer_account_id'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE financial_transactions DROP FOREIGN KEY fk_transfer_account");
            $this->db->exec("ALTER TABLE financial_transactions DROP INDEX idx_transfer_account_id");
            $this->db->exec("ALTER TABLE financial_transactions CHANGE COLUMN transfer_account_id transfer_to_account_id INT NULL");
            $this->db->exec("ALTER TABLE financial_transactions ADD CONSTRAINT fk_transfer_to_account FOREIGN KEY (transfer_to_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT");
            $this->db->exec("ALTER TABLE financial_transactions ADD INDEX idx_transfer_to_account_id (transfer_to_account_id)");
        }
    }
}
