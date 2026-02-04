<?php

class CreateStandaloneVotesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS standalone_votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status ENUM('draft', 'open', 'closed') DEFAULT 'draft',
            voting_started_at TIMESTAMP NULL,
            voting_ended_at TIMESTAMP NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_status (status),
            INDEX idx_voting_started_at (voting_started_at),
            INDEX idx_voting_ended_at (voting_ended_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS standalone_votes");
    }
}
