<?php

class AddAllowedOptionsToStandaloneVotes
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add allowed_options column to store JSON array of vote_option_ids
        $stmt = $this->db->query("SHOW COLUMNS FROM standalone_votes LIKE 'allowed_options'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE standalone_votes ADD COLUMN allowed_options JSON NULL AFTER description");
        }
    }

    public function down(): void
    {
        // Remove allowed_options column
        $stmt = $this->db->query("SHOW COLUMNS FROM standalone_votes LIKE 'allowed_options'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE standalone_votes DROP COLUMN allowed_options");
        }
    }
}
