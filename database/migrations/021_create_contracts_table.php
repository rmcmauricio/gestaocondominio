<?php

class CreateContractsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS contracts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            supplier_id INT NOT NULL,
            contract_number VARCHAR(100) NULL,
            description TEXT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            renewal_alert_days INT DEFAULT 30,
            is_active BOOLEAN DEFAULT TRUE,
            auto_renew BOOLEAN DEFAULT FALSE,
            attachments JSON NULL,
            notes TEXT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_end_date (end_date),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS contracts");
    }
}






