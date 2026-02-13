<?php

/**
 * Drop expenses table.
 * Expenses have been unified into financial_transactions (transaction_type='expense').
 */
class DropExpensesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS expenses");
    }

    public function down(): void
    {
        // Recreate minimal structure for rollback (original from 017_create_expenses_table)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                condominium_id INT NOT NULL,
                fraction_id INT NULL,
                supplier_id INT NULL,
                category VARCHAR(100) NULL,
                description VARCHAR(500) NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                type ENUM('ordinaria','extraordinaria') DEFAULT 'ordinaria',
                expense_date DATE NOT NULL,
                invoice_number VARCHAR(50) NULL,
                invoice_date DATE NULL,
                payment_method VARCHAR(50) NULL,
                is_paid TINYINT(1) DEFAULT 0,
                notes TEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_condominium_id (condominium_id),
                INDEX idx_expense_date (expense_date),
                FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
