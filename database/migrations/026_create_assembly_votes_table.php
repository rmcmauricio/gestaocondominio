<?php

class CreateAssemblyVotesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS assembly_votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assembly_id INT NOT NULL,
            fraction_id INT NOT NULL,
            user_id INT NULL,
            vote_item VARCHAR(255) NOT NULL,
            vote_value ENUM('yes', 'no', 'abstain') NOT NULL,
            weighted_value DECIMAL(12,4) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_assembly_id (assembly_id),
            INDEX idx_fraction_id (fraction_id),
            INDEX idx_vote_item (vote_item),
            UNIQUE KEY unique_assembly_fraction_item (assembly_id, fraction_id, vote_item)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS assembly_votes");
    }
}


