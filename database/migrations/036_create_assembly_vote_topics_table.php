<?php

class CreateAssemblyVoteTopicsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS assembly_vote_topics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assembly_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            options JSON NOT NULL,
            order_index INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            voting_started_at TIMESTAMP NULL,
            voting_ended_at TIMESTAMP NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_assembly_id (assembly_id),
            INDEX idx_order_index (order_index),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS assembly_vote_topics");
    }
}
