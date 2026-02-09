<?php

class AddAnnualFeesGeneratedToBudgets
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if column already exists
        $stmt = $this->db->query("SHOW COLUMNS FROM budgets LIKE 'annual_fees_generated'");
        if ($stmt->rowCount() > 0) {
            return; // Column already exists
        }

        $sql = "ALTER TABLE budgets 
                ADD COLUMN annual_fees_generated BOOLEAN DEFAULT FALSE AFTER status,
                ADD INDEX idx_annual_fees_generated (annual_fees_generated)";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if column exists before dropping
        $stmt = $this->db->query("SHOW COLUMNS FROM budgets LIKE 'annual_fees_generated'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE budgets DROP INDEX idx_annual_fees_generated");
            $this->db->exec("ALTER TABLE budgets DROP COLUMN annual_fees_generated");
        }
    }
}
