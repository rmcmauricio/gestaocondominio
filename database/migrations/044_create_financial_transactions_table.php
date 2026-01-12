<?php

class CreateFinancialTransactionsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS financial_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            bank_account_id INT NOT NULL,
            transaction_type ENUM('income', 'expense') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            transaction_date DATE NOT NULL,
            description TEXT NOT NULL,
            category VARCHAR(100) NULL,
            reference VARCHAR(255) NULL,
            related_type ENUM('fee_payment', 'expense', 'revenue', 'manual') DEFAULT 'manual',
            related_id INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_bank_account_id (bank_account_id),
            INDEX idx_transaction_date (transaction_date),
            INDEX idx_transaction_type (transaction_type),
            INDEX idx_related_type (related_type),
            INDEX idx_related_id (related_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS financial_transactions");
    }
}
