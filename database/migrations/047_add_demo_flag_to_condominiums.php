<?php

class AddDemoFlagToCondominiums
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add is_demo column
        $stmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'is_demo'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE condominiums ADD COLUMN is_demo BOOLEAN DEFAULT FALSE AFTER is_active");
            $this->db->exec("ALTER TABLE condominiums ADD INDEX idx_is_demo (is_demo)");
        }
    }

    public function down(): void
    {
        // Remove demo flag from demo condominiums
        $this->db->exec("UPDATE condominiums SET is_demo = FALSE WHERE is_demo = TRUE");
        
        // Remove column
        $this->db->exec("ALTER TABLE condominiums DROP COLUMN IF EXISTS is_demo");
    }
}
