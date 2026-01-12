<?php

class UpdateAssemblyVotesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add topic_id column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'topic_id'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE assembly_votes ADD COLUMN topic_id INT NULL AFTER assembly_id");
            $this->db->exec("ALTER TABLE assembly_votes ADD FOREIGN KEY (topic_id) REFERENCES assembly_vote_topics(id) ON DELETE CASCADE");
            $this->db->exec("ALTER TABLE assembly_votes ADD INDEX idx_topic_id (topic_id)");
        }

        // Add vote_option column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'vote_option'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE assembly_votes ADD COLUMN vote_option VARCHAR(255) NULL AFTER topic_id");
        }

        // Add notes column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'notes'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE assembly_votes ADD COLUMN notes TEXT NULL AFTER vote_option");
        }

        // Update weighted_value to be calculated automatically
        // Keep existing vote_item and vote_value for backward compatibility
    }

    public function down(): void
    {
        // Remove added columns
        $this->db->exec("ALTER TABLE assembly_votes DROP FOREIGN KEY assembly_votes_ibfk_4");
        $this->db->exec("ALTER TABLE assembly_votes DROP COLUMN topic_id");
        $this->db->exec("ALTER TABLE assembly_votes DROP COLUMN vote_option");
        $this->db->exec("ALTER TABLE assembly_votes DROP COLUMN notes");
    }
}
