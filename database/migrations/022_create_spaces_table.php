<?php

class CreateSpacesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS spaces (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            type VARCHAR(100) NULL,
            capacity INT NULL,
            price_per_hour DECIMAL(10,2) DEFAULT 0,
            price_per_day DECIMAL(10,2) DEFAULT 0,
            deposit_required DECIMAL(10,2) DEFAULT 0,
            requires_approval BOOLEAN DEFAULT TRUE,
            rules TEXT NULL,
            available_hours JSON NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS spaces");
    }
}


