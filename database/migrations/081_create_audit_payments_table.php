<?php

class CreateAuditPaymentsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS audit_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_id INT NULL,
            subscription_id INT NULL,
            invoice_id INT NULL,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            payment_method VARCHAR(50) NULL,
            amount DECIMAL(10,2) NULL,
            status VARCHAR(50) NULL,
            external_payment_id VARCHAR(255) NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            description TEXT NULL,
            metadata JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
            FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_payment_id (payment_id),
            INDEX idx_subscription_id (subscription_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_status (status),
            INDEX idx_external_payment_id (external_payment_id),
            INDEX idx_created_at (created_at),
            INDEX idx_payment_method (payment_method),
            INDEX idx_created_at_action (created_at, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS audit_payments");
    }
}
