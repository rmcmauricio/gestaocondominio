<?php

class CreateFractionAccountMovementsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS fraction_account_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fraction_account_id INT NOT NULL,
            type ENUM('credit', 'debit') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            source_type ENUM('quota_payment', 'space_reservation', 'other', 'quota_application') NOT NULL,
            source_reference_id INT NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (fraction_account_id) REFERENCES fraction_accounts(id) ON DELETE CASCADE,
            INDEX idx_fraction_account_id (fraction_account_id),
            INDEX idx_type (type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS fraction_account_movements");
    }
}
