<?php

class CreateExpensesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            fraction_id INT NULL,
            supplier_id INT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            type ENUM('ordinaria', 'extraordinaria', 'fundo_reserva') DEFAULT 'ordinaria',
            expense_date DATE NOT NULL,
            invoice_number VARCHAR(100) NULL,
            invoice_date DATE NULL,
            payment_method VARCHAR(50) NULL,
            is_paid BOOLEAN DEFAULT FALSE,
            paid_at TIMESTAMP NULL,
            attachments JSON NULL,
            notes TEXT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE SET NULL,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_fraction_id (fraction_id),
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_category (category),
            INDEX idx_type (type),
            INDEX idx_expense_date (expense_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS expenses");
    }
}





