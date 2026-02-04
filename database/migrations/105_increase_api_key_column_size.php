<?php

class IncreaseApiKeyColumnSize
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Increase api_key column size from VARCHAR(64) to VARCHAR(128)
        // API keys are generated as 'pk_' + 64 hex characters = 67 characters total
        // Using 128 to provide buffer for future changes
        $sql = "ALTER TABLE users MODIFY COLUMN api_key VARCHAR(128) NULL UNIQUE";
        
        try {
            $this->db->exec($sql);
        } catch (\PDOException $e) {
            // If column doesn't exist or error, try to add it
            $checkSql = "SHOW COLUMNS FROM users LIKE 'api_key'";
            $result = $this->db->query($checkSql);
            if ($result->rowCount() > 0) {
                // Column exists, try to modify it
                throw $e;
            }
        }
    }

    public function down(): void
    {
        // Revert to original size
        $this->db->exec("ALTER TABLE users MODIFY COLUMN api_key VARCHAR(64) NULL UNIQUE");
    }
}
