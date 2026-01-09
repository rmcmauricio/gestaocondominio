<?php

class CreateCondominiumUsersTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS condominium_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            user_id INT NOT NULL,
            fraction_id INT NULL,
            role ENUM('admin', 'condomino', 'proprietario', 'arrendatario') DEFAULT 'condomino',
            can_view_finances BOOLEAN DEFAULT TRUE,
            can_vote BOOLEAN DEFAULT TRUE,
            is_primary BOOLEAN DEFAULT FALSE,
            started_at DATE NULL,
            ended_at DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE SET NULL,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_user_id (user_id),
            INDEX idx_fraction_id (fraction_id),
            UNIQUE KEY unique_user_fraction (user_id, fraction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS condominium_users");
    }
}






