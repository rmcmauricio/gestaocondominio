<?php

class CreateCondominiumsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS condominiums (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            address TEXT NOT NULL,
            postal_code VARCHAR(20) NULL,
            city VARCHAR(100) NULL,
            country VARCHAR(100) DEFAULT 'Portugal',
            nif VARCHAR(20) NULL,
            iban VARCHAR(34) NULL,
            phone VARCHAR(20) NULL,
            email VARCHAR(255) NULL,
            type ENUM('habitacional', 'misto', 'parque', 'comercial', 'outro') DEFAULT 'habitacional',
            total_fractions INT DEFAULT 0,
            rules TEXT NULL,
            settings JSON NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS condominiums");
    }
}






