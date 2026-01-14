<?php

class UpdateOccurrencesDescriptionToMediumtext
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Change description column from TEXT to MEDIUMTEXT to support HTML content
        $this->db->exec("
            ALTER TABLE occurrences 
            MODIFY COLUMN description MEDIUMTEXT NOT NULL
        ");
    }

    public function down(): void
    {
        // Revert back to TEXT
        $this->db->exec("
            ALTER TABLE occurrences 
            MODIFY COLUMN description TEXT NOT NULL
        ");
    }
}
