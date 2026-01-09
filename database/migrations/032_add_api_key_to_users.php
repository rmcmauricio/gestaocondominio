<?php

class AddApiKeyToUsers
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add api_key column if it doesn't exist
        $sql = "ALTER TABLE users 
                ADD COLUMN IF NOT EXISTS api_key VARCHAR(64) NULL UNIQUE,
                ADD COLUMN IF NOT EXISTS api_key_created_at TIMESTAMP NULL,
                ADD COLUMN IF NOT EXISTS api_key_last_used_at TIMESTAMP NULL,
                ADD INDEX IF NOT EXISTS idx_api_key (api_key)";
        
        try {
            $this->db->exec($sql);
        } catch (\PDOException $e) {
            // Column might already exist, check and add individually
            $checkSql = "SHOW COLUMNS FROM users LIKE 'api_key'";
            $result = $this->db->query($checkSql);
            if ($result->rowCount() == 0) {
                $this->db->exec("ALTER TABLE users ADD COLUMN api_key VARCHAR(64) NULL UNIQUE");
                $this->db->exec("ALTER TABLE users ADD COLUMN api_key_created_at TIMESTAMP NULL");
                $this->db->exec("ALTER TABLE users ADD COLUMN api_key_last_used_at TIMESTAMP NULL");
                $this->db->exec("ALTER TABLE users ADD INDEX idx_api_key (api_key)");
            }
        }
    }

    public function down(): void
    {
        $this->db->exec("ALTER TABLE users DROP COLUMN IF EXISTS api_key");
        $this->db->exec("ALTER TABLE users DROP COLUMN IF EXISTS api_key_created_at");
        $this->db->exec("ALTER TABLE users DROP COLUMN IF EXISTS api_key_last_used_at");
    }
}





