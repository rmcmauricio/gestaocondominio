<?php

class AddPromotionFieldsToPromotions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if columns already exist
        $checkStmt = $this->db->query("SHOW COLUMNS FROM promotions LIKE 'is_visible'");
        if ($checkStmt->rowCount() > 0) {
            return; // Column already exists
        }

        $sql = "ALTER TABLE promotions 
                ADD COLUMN is_visible BOOLEAN DEFAULT FALSE AFTER is_active,
                ADD COLUMN duration_months INT NULL AFTER end_date,
                ADD INDEX idx_is_visible (is_visible)";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if columns exist before dropping
        $checkStmt = $this->db->query("SHOW COLUMNS FROM promotions LIKE 'is_visible'");
        if ($checkStmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE promotions DROP COLUMN is_visible");
        }
        
        $checkStmt = $this->db->query("SHOW COLUMNS FROM promotions LIKE 'duration_months'");
        if ($checkStmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE promotions DROP COLUMN duration_months");
        }
    }
}
