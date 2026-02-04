<?php

class AddContactFieldsToCondominiumUsers
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add contact fields to condominium_users table
        $sql = "ALTER TABLE condominium_users 
                ADD COLUMN IF NOT EXISTS nif VARCHAR(20) NULL AFTER role,
                ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER nif,
                ADD COLUMN IF NOT EXISTS alternative_address VARCHAR(500) NULL AFTER phone";
        
        try {
            $this->db->exec($sql);
        } catch (\PDOException $e) {
            // If columns already exist or error, try adding individually
            $checkSql = "SHOW COLUMNS FROM condominium_users LIKE 'nif'";
            $result = $this->db->query($checkSql);
            if ($result->rowCount() == 0) {
                $this->db->exec("ALTER TABLE condominium_users ADD COLUMN nif VARCHAR(20) NULL AFTER role");
            }
            
            $checkSql = "SHOW COLUMNS FROM condominium_users LIKE 'phone'";
            $result = $this->db->query($checkSql);
            if ($result->rowCount() == 0) {
                $this->db->exec("ALTER TABLE condominium_users ADD COLUMN phone VARCHAR(20) NULL AFTER nif");
            }
            
            $checkSql = "SHOW COLUMNS FROM condominium_users LIKE 'alternative_address'";
            $result = $this->db->query($checkSql);
            if ($result->rowCount() == 0) {
                $this->db->exec("ALTER TABLE condominium_users ADD COLUMN alternative_address VARCHAR(500) NULL AFTER phone");
            }
        }
    }

    public function down(): void
    {
        $this->db->exec("ALTER TABLE condominium_users DROP COLUMN IF EXISTS alternative_address");
        $this->db->exec("ALTER TABLE condominium_users DROP COLUMN IF EXISTS phone");
        $this->db->exec("ALTER TABLE condominium_users DROP COLUMN IF EXISTS nif");
    }
}
