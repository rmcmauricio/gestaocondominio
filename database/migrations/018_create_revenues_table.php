<?php

class CreateRevenuesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS revenues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            fraction_id INT NULL,
            description TEXT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            revenue_date DATE NOT NULL,
            payment_method VARCHAR(50) NULL,
            reference VARCHAR(255) NULL,
            notes TEXT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_fraction_id (fraction_id),
            INDEX idx_revenue_date (revenue_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS revenues");
    }
}

