<?php

class CreateSuppliersTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NULL,
            name VARCHAR(255) NOT NULL,
            nif VARCHAR(20) NULL,
            address TEXT NULL,
            phone VARCHAR(20) NULL,
            email VARCHAR(255) NULL,
            website VARCHAR(255) NULL,
            area VARCHAR(100) NULL,
            notes TEXT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE SET NULL,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS suppliers");
    }
}

