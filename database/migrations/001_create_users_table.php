<?php

class CreateUsersTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            role ENUM('super_admin', 'admin', 'condomino', 'fornecedor') NOT NULL DEFAULT 'condomino',
            phone VARCHAR(20) NULL,
            nif VARCHAR(20) NULL,
            two_factor_secret VARCHAR(255) NULL,
            two_factor_enabled BOOLEAN DEFAULT FALSE,
            email_verified_at TIMESTAMP NULL,
            last_login_at TIMESTAMP NULL,
            status ENUM('active', 'suspended', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_role (role),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS users");
    }
}






