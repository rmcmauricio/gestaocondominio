<?php

class AddDefaultCondominiumToUsers
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if column already exists
        $stmt = $this->db->query("SHOW COLUMNS FROM users LIKE 'default_condominium_id'");
        if ($stmt->rowCount() > 0) {
            return; // Column already exists
        }

        $sql = "ALTER TABLE users ADD COLUMN default_condominium_id INT NULL AFTER id,
                 ADD FOREIGN KEY (default_condominium_id) REFERENCES condominiums(id) ON DELETE SET NULL,
                 ADD INDEX idx_default_condominium_id (default_condominium_id)";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Check if column exists before dropping
        $stmt = $this->db->query("SHOW COLUMNS FROM users LIKE 'default_condominium_id'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE users DROP FOREIGN KEY users_ibfk_default_condominium_id");
            $this->db->exec("ALTER TABLE users DROP INDEX idx_default_condominium_id");
            $this->db->exec("ALTER TABLE users DROP COLUMN default_condominium_id");
        }
    }
}
