<?php

class AddEntryDateToCondominiums
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'entry_date'");
        if ($stmt->rowCount() > 0) {
            return;
        }
        $this->db->exec("
            ALTER TABLE condominiums
            ADD COLUMN entry_date DATE NULL COMMENT 'Data de entrada no sistema' AFTER updated_at
        ");
    }

    public function down(): void
    {
        $this->db->exec("
            ALTER TABLE condominiums
            DROP COLUMN IF EXISTS entry_date
        ");
    }
}
