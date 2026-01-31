<?php

class CreateAdminTransferPendingTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS admin_transfer_pending (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            user_id INT NOT NULL,
            assigned_by_user_id INT NOT NULL,
            status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
            is_professional_transfer BOOLEAN DEFAULT FALSE,
            from_subscription_id INT NULL,
            to_subscription_id INT NULL,
            message TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            accepted_at TIMESTAMP NULL,
            rejected_at TIMESTAMP NULL,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (from_subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
            FOREIGN KEY (to_subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            UNIQUE KEY unique_pending_transfer (condominium_id, user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS admin_transfer_pending");
    }
}
