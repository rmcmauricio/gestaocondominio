<?php

class CreateSubscriptionsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan_id INT NOT NULL,
            status ENUM('trial', 'active', 'suspended', 'canceled', 'expired') DEFAULT 'trial',
            trial_ends_at TIMESTAMP NULL,
            current_period_start TIMESTAMP NOT NULL,
            current_period_end TIMESTAMP NOT NULL,
            canceled_at TIMESTAMP NULL,
            payment_method VARCHAR(50) NULL,
            external_subscription_id VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (plan_id) REFERENCES plans(id),
            INDEX idx_user_id (user_id),
            INDEX idx_status (status),
            INDEX idx_current_period_end (current_period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS subscriptions");
    }
}






