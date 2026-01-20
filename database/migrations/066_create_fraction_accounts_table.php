<?php

class CreateFractionAccountsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS fraction_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            fraction_id INT NOT NULL,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            UNIQUE KEY unique_fraction_account (fraction_id),
            INDEX idx_condominium_id (condominium_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS fraction_accounts");
    }
}
