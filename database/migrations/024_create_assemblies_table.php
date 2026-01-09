<?php

class CreateAssembliesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS assemblies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            type ENUM('ordinaria', 'extraordinaria') DEFAULT 'ordinaria',
            scheduled_date DATETIME NOT NULL,
            location VARCHAR(255) NULL,
            agenda TEXT NULL,
            status ENUM('scheduled', 'in_progress', 'completed', 'canceled') DEFAULT 'scheduled',
            quorum_percentage DECIMAL(5,2) NULL,
            quorum_achieved BOOLEAN DEFAULT FALSE,
            minutes TEXT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_scheduled_date (scheduled_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS assemblies");
    }
}


