<?php

class AddPriceMonthlyToSubscriptions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if column already exists
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'price_monthly'");
        if ($checkStmt->rowCount() > 0) {
            return; // Column already exists
        }

        $sql = "ALTER TABLE subscriptions 
                ADD COLUMN price_monthly DECIMAL(10,2) NULL AFTER original_price_monthly";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if column exists before dropping
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'price_monthly'");
        if ($checkStmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE subscriptions DROP COLUMN price_monthly");
        }
    }
}
