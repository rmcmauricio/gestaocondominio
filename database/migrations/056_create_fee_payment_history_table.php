<?php

class CreateFeePaymentHistoryTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS fee_payment_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fee_payment_id INT NOT NULL,
            fee_id INT NOT NULL,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            field_name VARCHAR(100) NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (fee_payment_id) REFERENCES fee_payments(id) ON DELETE CASCADE,
            FOREIGN KEY (fee_id) REFERENCES fees(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_fee_payment_id (fee_payment_id),
            INDEX idx_fee_id (fee_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS fee_payment_history");
    }
}
