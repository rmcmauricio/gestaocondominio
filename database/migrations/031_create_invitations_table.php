<?php

class CreateInvitationsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            fraction_id INT NULL,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            role ENUM('condomino', 'proprietario', 'arrendatario') DEFAULT 'condomino',
            token VARCHAR(255) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            accepted_at TIMESTAMP NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_token (token),
            INDEX idx_email (email),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS invitations");
    }
}





