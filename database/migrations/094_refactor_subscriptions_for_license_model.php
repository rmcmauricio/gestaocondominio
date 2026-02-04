<?php

class RefactorSubscriptionsForLicenseModel
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add new columns for license-based model
        $sql = "ALTER TABLE subscriptions 
                ADD COLUMN condominium_id INT NULL AFTER plan_id,
                ADD COLUMN used_licenses INT DEFAULT 0 AFTER condominium_id,
                ADD COLUMN license_limit INT NULL AFTER used_licenses,
                ADD COLUMN allow_overage BOOLEAN DEFAULT FALSE AFTER license_limit,
                ADD COLUMN proration_mode ENUM('none', 'prorated') DEFAULT 'none' AFTER allow_overage,
                ADD COLUMN charge_minimum BOOLEAN DEFAULT TRUE AFTER proration_mode,
                ADD INDEX idx_condominium_id (condominium_id),
                ADD INDEX idx_used_licenses (used_licenses),
                ADD FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE SET NULL";
        
        $this->db->exec($sql);
        
        // Note: extra_condominiums column is kept for backward compatibility
        // but will not be used in the new license-based model
    }

    public function down(): void
    {
        // Get foreign key name first
        $fkCheck = $this->db->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'subscriptions' 
            AND COLUMN_NAME = 'condominium_id' 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        
        $fkName = null;
        if ($fkCheck && $row = $fkCheck->fetch()) {
            $fkName = $row['CONSTRAINT_NAME'];
        }
        
        // Remove foreign key if exists
        if ($fkName) {
            $this->db->exec("ALTER TABLE subscriptions DROP FOREIGN KEY {$fkName}");
        }
        
        // Remove new columns
        $sql = "ALTER TABLE subscriptions 
                DROP COLUMN condominium_id,
                DROP COLUMN used_licenses,
                DROP COLUMN license_limit,
                DROP COLUMN allow_overage,
                DROP COLUMN proration_mode,
                DROP COLUMN charge_minimum";
        
        $this->db->exec($sql);
    }
}
