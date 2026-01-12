<?php

class UpdateAssembliesTableAddFields
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add description column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM assemblies LIKE 'description'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE assemblies ADD COLUMN description TEXT NULL AFTER title");
        }

        // Add convocation_sent_at column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM assemblies LIKE 'convocation_sent_at'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE assemblies ADD COLUMN convocation_sent_at TIMESTAMP NULL AFTER quorum_percentage");
        }

        // Update type enum to include 'ordinary' and 'extraordinary' (if needed)
        // Check current enum values
        $stmt = $this->db->query("SHOW COLUMNS FROM assemblies WHERE Field = 'type'");
        $column = $stmt->fetch();
        if ($column && strpos($column['Type'], 'ordinaria') !== false) {
            // Update enum to use English values
            $this->db->exec("ALTER TABLE assemblies MODIFY COLUMN type ENUM('ordinary', 'extraordinary', 'ordinaria', 'extraordinaria') DEFAULT 'ordinary'");
        }
    }

    public function down(): void
    {
        // Remove added columns
        $this->db->exec("ALTER TABLE assemblies DROP COLUMN IF EXISTS description");
        $this->db->exec("ALTER TABLE assemblies DROP COLUMN IF EXISTS convocation_sent_at");
    }
}
