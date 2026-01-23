<?php

class AddLicenseFieldsToFractions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if columns already exist
        $checkStmt = $this->db->query("SHOW COLUMNS FROM fractions LIKE 'license_consumed'");
        if ($checkStmt->rowCount() > 0) {
            return; // Columns already exist
        }

        $sql = "ALTER TABLE fractions 
                ADD COLUMN license_consumed BOOLEAN DEFAULT TRUE AFTER is_active,
                ADD COLUMN archived_at TIMESTAMP NULL AFTER license_consumed,
                ADD INDEX idx_license_consumed (license_consumed),
                ADD INDEX idx_archived_at (archived_at)";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if columns exist before dropping
        $checkStmt = $this->db->query("SHOW COLUMNS FROM fractions LIKE 'license_consumed'");
        if ($checkStmt->rowCount() > 0) {
            $sql = "ALTER TABLE fractions 
                    DROP COLUMN license_consumed,
                    DROP COLUMN archived_at";
            
            $this->db->exec($sql);
        }
    }
}
