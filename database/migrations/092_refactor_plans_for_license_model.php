<?php

class RefactorPlansForLicenseModel
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add new columns for license-based model
        $sql = "ALTER TABLE plans 
                ADD COLUMN plan_type ENUM('condominio', 'professional', 'enterprise') NULL AFTER slug,
                ADD COLUMN license_min INT NULL AFTER plan_type,
                ADD COLUMN license_limit INT NULL AFTER license_min,
                ADD COLUMN allow_multiple_condos BOOLEAN DEFAULT FALSE AFTER license_limit,
                ADD COLUMN allow_overage BOOLEAN DEFAULT FALSE AFTER allow_multiple_condos,
                ADD COLUMN pricing_mode ENUM('flat', 'progressive') DEFAULT 'flat' AFTER allow_overage,
                ADD COLUMN annual_discount_percentage DECIMAL(5,2) DEFAULT 0 AFTER pricing_mode,
                ADD INDEX idx_plan_type (plan_type),
                ADD INDEX idx_license_min (license_min)";
        
        $this->db->exec($sql);
        
        // Note: limit_condominios and limit_fracoes are kept for backward compatibility
        // but will not be used in the new license-based model
    }

    public function down(): void
    {
        // Remove new columns
        $sql = "ALTER TABLE plans 
                DROP COLUMN plan_type,
                DROP COLUMN license_min,
                DROP COLUMN license_limit,
                DROP COLUMN allow_multiple_condos,
                DROP COLUMN allow_overage,
                DROP COLUMN pricing_mode,
                DROP COLUMN annual_discount_percentage";
        
        $this->db->exec($sql);
    }
}
