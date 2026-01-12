<?php

class AddNotesToAssemblyAttendees
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add notes column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_attendees LIKE 'notes'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE assembly_attendees ADD COLUMN notes TEXT NULL AFTER proxy_document");
        }
    }

    public function down(): void
    {
        // Remove notes column
        $this->db->exec("ALTER TABLE assembly_attendees DROP COLUMN IF EXISTS notes");
    }
}
