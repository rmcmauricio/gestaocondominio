<?php

class AddSubscriptionFieldsToCondominiums
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if columns already exist
        $checkStmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'subscription_id'");
        if ($checkStmt->rowCount() > 0) {
            return; // Columns already exist
        }

        $sql = "ALTER TABLE condominiums 
                ADD COLUMN subscription_id INT NULL AFTER user_id,
                ADD COLUMN subscription_status ENUM('active', 'locked', 'read_only') DEFAULT 'active' AFTER subscription_id,
                ADD COLUMN locked_at TIMESTAMP NULL AFTER subscription_status,
                ADD COLUMN locked_reason TEXT NULL AFTER locked_at,
                ADD INDEX idx_subscription_id (subscription_id),
                ADD INDEX idx_subscription_status (subscription_status),
                ADD FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if columns exist before dropping
        $checkStmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'subscription_id'");
        if ($checkStmt->rowCount() > 0) {
            $sql = "ALTER TABLE condominiums 
                    DROP FOREIGN KEY condominiums_ibfk_subscription_id,
                    DROP COLUMN subscription_id,
                    DROP COLUMN subscription_status,
                    DROP COLUMN locked_at,
                    DROP COLUMN locked_reason";
            
            $this->db->exec($sql);
        }
    }
}
