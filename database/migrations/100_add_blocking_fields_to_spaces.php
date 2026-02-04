<?php

class AddBlockingFieldsToSpaces
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "ALTER TABLE spaces 
                ADD COLUMN is_blocked BOOLEAN DEFAULT FALSE AFTER is_active,
                ADD COLUMN blocked_until DATETIME NULL AFTER is_blocked,
                ADD COLUMN block_reason TEXT NULL AFTER blocked_until,
                ADD INDEX idx_is_blocked (is_blocked),
                ADD INDEX idx_blocked_until (blocked_until)";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("ALTER TABLE spaces 
                         DROP COLUMN is_blocked,
                         DROP COLUMN blocked_until,
                         DROP COLUMN block_reason");
    }
}
