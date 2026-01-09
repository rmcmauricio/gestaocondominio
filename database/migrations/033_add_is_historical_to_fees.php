<?php

class AddIsHistoricalToFees
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "ALTER TABLE fees 
                ADD COLUMN is_historical BOOLEAN DEFAULT FALSE AFTER notes,
                ADD INDEX idx_is_historical (is_historical)";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("ALTER TABLE fees DROP COLUMN is_historical");
    }
}





