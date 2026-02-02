<?php

class MakeInvitationsExpiresAtNullable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Make expires_at nullable to allow creating invitations without email (no expiration needed)
        $sql = "ALTER TABLE invitations 
                MODIFY COLUMN expires_at TIMESTAMP NULL";
        
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
        // Revert: make expires_at NOT NULL again
        // First, set NULL expires_at to a future date (if any)
        $this->db->exec("UPDATE invitations SET expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE expires_at IS NULL");
        
        // Then make it NOT NULL
        $sql = "ALTER TABLE invitations 
                MODIFY COLUMN expires_at TIMESTAMP NOT NULL";
        
        $this->db->exec($sql);
    }
}
