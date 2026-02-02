<?php

class MakeInvitationsEmailNullable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Make email nullable to allow creating invitations without email
        $sql = "ALTER TABLE invitations 
                MODIFY COLUMN email VARCHAR(255) NULL";
        
        try {
            $this->db->exec($sql);
        } catch (\PDOException $e) {
            // If column doesn't exist or error, log it
            error_log("Migration error: " . $e->getMessage());
            throw $e;
        }
    }

    public function down(): void
    {
        // Revert: make email NOT NULL again
        // First, set NULL emails to empty string
        $this->db->exec("UPDATE invitations SET email = '' WHERE email IS NULL");
        
        // Then make it NOT NULL
        $sql = "ALTER TABLE invitations 
                MODIFY COLUMN email VARCHAR(255) NOT NULL";
        
        $this->db->exec($sql);
    }
}
