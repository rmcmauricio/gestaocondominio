<?php

class CreateStandaloneVoteResponsesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS standalone_vote_responses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            standalone_vote_id INT NOT NULL,
            fraction_id INT NOT NULL,
            user_id INT NULL,
            vote_option_id INT NOT NULL,
            weighted_value DECIMAL(12,4) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (standalone_vote_id) REFERENCES standalone_votes(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (vote_option_id) REFERENCES vote_options(id) ON DELETE RESTRICT,
            INDEX idx_standalone_vote_id (standalone_vote_id),
            INDEX idx_fraction_id (fraction_id),
            INDEX idx_vote_option_id (vote_option_id),
            UNIQUE KEY unique_vote_fraction (standalone_vote_id, fraction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS standalone_vote_responses");
    }
}
