<?php

class UpdateAssemblyVotesForStandalone
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add standalone_vote_id column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'standalone_vote_id'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE assembly_votes ADD COLUMN standalone_vote_id INT NULL AFTER assembly_id");
            $this->db->exec("ALTER TABLE assembly_votes ADD FOREIGN KEY (standalone_vote_id) REFERENCES standalone_votes(id) ON DELETE CASCADE");
            $this->db->exec("ALTER TABLE assembly_votes ADD INDEX idx_standalone_vote_id (standalone_vote_id)");
        }
    }

    public function down(): void
    {
        // Remove standalone_vote_id column
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'standalone_vote_id'");
        if ($stmt->rowCount() > 0) {
            // Find and drop the foreign key constraint
            $stmt = $this->db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'assembly_votes' AND COLUMN_NAME = 'standalone_vote_id' AND CONSTRAINT_NAME != 'PRIMARY'");
            $fk = $stmt->fetch();
            if ($fk) {
                $this->db->exec("ALTER TABLE assembly_votes DROP FOREIGN KEY " . $fk['CONSTRAINT_NAME']);
            }
            $this->db->exec("ALTER TABLE assembly_votes DROP COLUMN standalone_vote_id");
        }
    }
}
