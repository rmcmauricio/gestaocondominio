<?php

class CreateBankAccountsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS bank_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            account_type ENUM('bank', 'cash') NOT NULL DEFAULT 'bank',
            bank_name VARCHAR(255) NULL,
            account_number VARCHAR(100) NULL,
            iban VARCHAR(34) NULL,
            swift VARCHAR(11) NULL,
            initial_balance DECIMAL(12,2) DEFAULT 0.00,
            current_balance DECIMAL(12,2) DEFAULT 0.00,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_account_type (account_type),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS bank_accounts");
    }
}
