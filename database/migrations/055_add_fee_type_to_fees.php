<?php

class AddFeeTypeToFees
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if column already exists
        $stmt = $this->db->query("SHOW COLUMNS FROM fees LIKE 'fee_type'");
        if ($stmt->rowCount() > 0) {
            return; // Column already exists
        }

        $sql = "ALTER TABLE fees 
                ADD COLUMN fee_type ENUM('regular', 'extra') DEFAULT 'regular' AFTER period_type,
                ADD INDEX idx_fee_type (fee_type)";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if column exists before dropping
        $stmt = $this->db->query("SHOW COLUMNS FROM fees LIKE 'fee_type'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE fees DROP INDEX idx_fee_type");
            $this->db->exec("ALTER TABLE fees DROP COLUMN fee_type");
        }
    }
}
