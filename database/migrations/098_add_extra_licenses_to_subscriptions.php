<?php

class AddExtraLicensesToSubscriptions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if column already exists
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'extra_licenses'");
        if ($checkStmt->rowCount() > 0) {
            return; // Column already exists
        }

        $sql = "ALTER TABLE subscriptions 
                ADD COLUMN extra_licenses INT DEFAULT 0 AFTER license_limit";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if column exists before dropping
        $checkStmt = $this->db->query("SHOW COLUMNS FROM subscriptions LIKE 'extra_licenses'");
        if ($checkStmt->rowCount() > 0) {
            $sql = "ALTER TABLE subscriptions DROP COLUMN extra_licenses";
            $this->db->exec($sql);
        }
    }
}
