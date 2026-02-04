<?php

class MakeContractAmountNullable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Make amount column nullable
        $stmt = $this->db->query("SHOW COLUMNS FROM contracts LIKE 'amount'");
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch();
            if ($result['Null'] === 'NO') {
                $this->db->exec("ALTER TABLE contracts MODIFY COLUMN amount DECIMAL(12,2) NULL");
            }
        }

        // Make amount_type column nullable
        $stmt = $this->db->query("SHOW COLUMNS FROM contracts LIKE 'amount_type'");
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch();
            if ($result['Null'] === 'NO') {
                $this->db->exec("ALTER TABLE contracts MODIFY COLUMN amount_type ENUM('annual', 'monthly') NULL");
            }
        }
    }

    public function down(): void
    {
        // Revert amount column to NOT NULL (set default to 0 for existing NULL values)
        $this->db->exec("UPDATE contracts SET amount = 0 WHERE amount IS NULL");
        $this->db->exec("ALTER TABLE contracts MODIFY COLUMN amount DECIMAL(12,2) NOT NULL");
        
        // Revert amount_type column to NOT NULL (set default to 'annual' for existing NULL values)
        $this->db->exec("UPDATE contracts SET amount_type = 'annual' WHERE amount_type IS NULL");
        $this->db->exec("ALTER TABLE contracts MODIFY COLUMN amount_type ENUM('annual', 'monthly') NOT NULL DEFAULT 'annual'");
    }
}
