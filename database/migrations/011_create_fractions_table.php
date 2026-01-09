<?php

class CreateFractionsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS fractions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            identifier VARCHAR(50) NOT NULL,
            permillage DECIMAL(8,4) NOT NULL DEFAULT 0,
            floor VARCHAR(20) NULL,
            typology VARCHAR(50) NULL,
            area DECIMAL(10,2) NULL,
            notes TEXT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_identifier (identifier),
            UNIQUE KEY unique_condominium_fraction (condominium_id, identifier)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS fractions");
    }
}






