<?php

class UpdateMessagesMessageToMediumtext
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Change message column from TEXT to MEDIUMTEXT to support HTML content
        $this->db->exec("
            ALTER TABLE messages 
            MODIFY COLUMN message MEDIUMTEXT NOT NULL
        ");
    }

    public function down(): void
    {
        // Revert back to TEXT
        $this->db->exec("
            ALTER TABLE messages 
            MODIFY COLUMN message TEXT NOT NULL
        ");
    }
}
