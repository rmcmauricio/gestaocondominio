<?php

class CreateFeesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS fees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            fraction_id INT NOT NULL,
            period_type ENUM('monthly', 'quarterly', 'yearly') DEFAULT 'monthly',
            period_year YEAR NOT NULL,
            period_month TINYINT NULL,
            period_quarter TINYINT NULL,
            amount DECIMAL(12,2) NOT NULL,
            base_amount DECIMAL(12,2) NOT NULL,
            status ENUM('pending', 'paid', 'overdue', 'canceled') DEFAULT 'pending',
            due_date DATE NOT NULL,
            paid_at TIMESTAMP NULL,
            reference VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_fraction_id (fraction_id),
            INDEX idx_period (period_year, period_month, period_quarter),
            INDEX idx_status (status),
            INDEX idx_due_date (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS fees");
    }
}





