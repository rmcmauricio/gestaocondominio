<?php

class AddIsPioneerToUsers
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if column already exists
        $stmt = $this->db->query("SHOW COLUMNS FROM users LIKE 'is_pioneer'");
        if ($stmt->rowCount() > 0) {
            return; // Column already exists
        }

        // Add is_pioneer column
        $this->db->exec("ALTER TABLE users ADD COLUMN is_pioneer BOOLEAN DEFAULT FALSE AFTER status");
        
        // Add index for faster queries
        $this->db->exec("CREATE INDEX idx_is_pioneer ON users(is_pioneer)");
    }

    public function down(): void
    {
        // Check if column exists before dropping
        $stmt = $this->db->query("SHOW COLUMNS FROM users LIKE 'is_pioneer'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE users DROP INDEX idx_is_pioneer");
            $this->db->exec("ALTER TABLE users DROP COLUMN is_pioneer");
        }
    }
}
