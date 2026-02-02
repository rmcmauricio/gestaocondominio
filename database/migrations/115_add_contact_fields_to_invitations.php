<?php

class AddContactFieldsToInvitations
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add contact fields to invitations table
        $sql = "ALTER TABLE invitations 
                ADD COLUMN IF NOT EXISTS nif VARCHAR(20) NULL AFTER role,
                ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER nif,
                ADD COLUMN IF NOT EXISTS alternative_address VARCHAR(500) NULL AFTER phone";
        
        try {
            $this->db->exec($sql);
        } catch (\PDOException $e) {
            // If columns already exist or error, try adding individually
            $checkSql = "SHOW COLUMNS FROM invitations LIKE 'nif'";
            $result = $this->db->query($checkSql);
            if ($result->rowCount() == 0) {
                $this->db->exec("ALTER TABLE invitations ADD COLUMN nif VARCHAR(20) NULL AFTER role");
            }
            
            $checkSql = "SHOW COLUMNS FROM invitations LIKE 'phone'";
            $result = $this->db->query($checkSql);
            if ($result->rowCount() == 0) {
                $this->db->exec("ALTER TABLE invitations ADD COLUMN phone VARCHAR(20) NULL AFTER nif");
            }
            
            $checkSql = "SHOW COLUMNS FROM invitations LIKE 'alternative_address'";
            $result = $this->db->query($checkSql);
            if ($result->rowCount() == 0) {
                $this->db->exec("ALTER TABLE invitations ADD COLUMN alternative_address VARCHAR(500) NULL AFTER phone");
            }
        }
    }

    public function down(): void
    {
        $this->db->exec("ALTER TABLE invitations DROP COLUMN IF EXISTS alternative_address");
        $this->db->exec("ALTER TABLE invitations DROP COLUMN IF EXISTS phone");
        $this->db->exec("ALTER TABLE invitations DROP COLUMN IF EXISTS nif");
    }
}
