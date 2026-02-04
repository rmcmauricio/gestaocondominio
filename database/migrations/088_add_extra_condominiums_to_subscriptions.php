<?php

class AddExtraCondominiumsToSubscriptions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if column already exists
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'extra_condominiums'");
        if ($checkStmt->rowCount() > 0) {
            return; // Column already exists
        }

        $sql = "ALTER TABLE subscriptions 
                ADD COLUMN extra_condominiums INT DEFAULT 0 AFTER plan_id,
                ADD INDEX idx_extra_condominiums (extra_condominiums)";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if column exists before dropping
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'extra_condominiums'");
        if ($checkStmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE subscriptions DROP COLUMN extra_condominiums");
        }
    }
}
