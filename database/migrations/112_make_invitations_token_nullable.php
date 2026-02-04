<?php

class MakeInvitationsTokenNullable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Make token nullable to allow creating invitations without email (no token needed)
        $sql = "ALTER TABLE invitations 
                MODIFY COLUMN token VARCHAR(255) NULL";
        
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
        // Revert: make token NOT NULL again
        // First, generate tokens for NULL tokens (if any)
        $this->db->exec("UPDATE invitations SET token = CONCAT('temp_', UUID()) WHERE token IS NULL");
        
        // Then make it NOT NULL
        $sql = "ALTER TABLE invitations 
                MODIFY COLUMN token VARCHAR(255) NOT NULL";
        
        $this->db->exec($sql);
    }
}
