<?php

class CreateSubscriptionCondominiumsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS subscription_condominiums (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NOT NULL,
            condominium_id INT NOT NULL,
            status ENUM('active', 'detached') DEFAULT 'active',
            attached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            detached_at TIMESTAMP NULL,
            detached_by INT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (detached_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_subscription_id (subscription_id),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_status (status),
            INDEX idx_subscription_status (subscription_id, status),
            UNIQUE KEY unique_active_link (subscription_id, condominium_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS subscription_condominiums");
    }
}
