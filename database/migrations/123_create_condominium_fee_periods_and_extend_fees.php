<?php

class CreateCondominiumFeePeriodsAndExtendFees
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // 1. Create condominium_fee_periods table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS condominium_fee_periods (
                condominium_id INT NOT NULL,
                year SMALLINT NOT NULL,
                period_type ENUM('monthly','bimonthly','quarterly','semiannual','annual') NOT NULL,
                PRIMARY KEY (condominium_id, year),
                FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2. Add period_index to fees (1-12 monthly, 1-6 bimonthly, 1-4 quarterly, 1-2 semiannual, 1 annual)
        $stmt = $this->db->query("SHOW COLUMNS FROM fees LIKE 'period_index'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE fees ADD COLUMN period_index TINYINT NULL AFTER period_quarter");
        }

        // 3. Extend period_type ENUM to include bimonthly, semiannual, annual
        $this->db->exec("
            ALTER TABLE fees MODIFY COLUMN period_type 
            ENUM('monthly','bimonthly','quarterly','semiannual','yearly','annual') DEFAULT 'monthly'
        ");

        // 4. Backfill period_index for existing fees (monthly: period_index = period_month)
        $this->db->exec("
            UPDATE fees
            SET period_index = period_month
            WHERE period_index IS NULL AND period_month BETWEEN 1 AND 12
        ");
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS condominium_fee_periods");

        $stmt = $this->db->query("SHOW COLUMNS FROM fees LIKE 'period_index'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE fees DROP COLUMN period_index");
        }

        $this->db->exec("
            ALTER TABLE fees MODIFY COLUMN period_type 
            ENUM('monthly', 'quarterly', 'yearly') DEFAULT 'monthly'
        ");
    }
}
