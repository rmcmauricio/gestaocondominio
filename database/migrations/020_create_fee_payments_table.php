<?php

class CreateFeePaymentsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS fee_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fee_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_method ENUM('multibanco', 'mbway', 'transfer', 'cash', 'card', 'sepa') NOT NULL,
            reference VARCHAR(255) NULL,
            payment_date DATE NOT NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (fee_id) REFERENCES fees(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_fee_id (fee_id),
            INDEX idx_payment_date (payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS fee_payments");
    }
}


