<?php

class AddPromotionFieldsToSubscriptions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if columns already exist
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'promotion_id'");
        if ($checkStmt->rowCount() > 0) {
            return; // Columns already exist
        }

        $sql = "ALTER TABLE subscriptions 
                ADD COLUMN promotion_id INT NULL AFTER plan_id,
                ADD COLUMN promotion_applied_at TIMESTAMP NULL AFTER promotion_id,
                ADD COLUMN promotion_ends_at TIMESTAMP NULL AFTER promotion_applied_at,
                ADD COLUMN original_price_monthly DECIMAL(10,2) NULL AFTER promotion_ends_at,
                ADD INDEX idx_promotion_id (promotion_id),
                ADD INDEX idx_promotion_ends_at (promotion_ends_at),
                ADD FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE SET NULL";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if columns exist before dropping
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'promotion_id'");
        if ($checkStmt->rowCount() > 0) {
            // Drop foreign key first
            try {
                $this->db->exec("ALTER TABLE subscriptions DROP FOREIGN KEY subscriptions_ibfk_3");
            } catch (\Exception $e) {
                // Try alternative constraint name
                try {
                    $this->db->exec("ALTER TABLE subscriptions DROP FOREIGN KEY fk_promotion_id");
                } catch (\Exception $e2) {
                    // Foreign key might not exist or have different name
                }
            }
            
            $this->db->exec("ALTER TABLE subscriptions DROP COLUMN promotion_id");
        }
        
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'promotion_applied_at'");
        if ($checkStmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE subscriptions DROP COLUMN promotion_applied_at");
        }
        
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'promotion_ends_at'");
        if ($checkStmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE subscriptions DROP COLUMN promotion_ends_at");
        }
        
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'original_price_monthly'");
        if ($checkStmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE subscriptions DROP COLUMN original_price_monthly");
        }
    }
}
