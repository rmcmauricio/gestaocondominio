<?php

class CreateAuditSubscriptionsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS audit_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            old_plan_id INT NULL,
            new_plan_id INT NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            old_period_start DATETIME NULL,
            new_period_start DATETIME NULL,
            old_period_end DATETIME NULL,
            new_period_end DATETIME NULL,
            description TEXT NULL,
            metadata JSON NULL,
            performed_by INT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (old_plan_id) REFERENCES plans(id) ON DELETE SET NULL,
            FOREIGN KEY (new_plan_id) REFERENCES plans(id) ON DELETE SET NULL,
            FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_subscription_id (subscription_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_status_change (old_status, new_status),
            INDEX idx_created_at (created_at),
            INDEX idx_performed_by (performed_by),
            INDEX idx_created_at_action (created_at, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS audit_subscriptions");
    }
}
