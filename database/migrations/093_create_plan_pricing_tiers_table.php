<?php

class CreatePlanPricingTiersTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS plan_pricing_tiers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            min_licenses INT NOT NULL,
            max_licenses INT NULL,
            price_per_license DECIMAL(10,2) NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
            INDEX idx_plan_id (plan_id),
            INDEX idx_active (is_active),
            INDEX idx_range (min_licenses, max_licenses),
            INDEX idx_plan_active (plan_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS plan_pricing_tiers");
    }
}
