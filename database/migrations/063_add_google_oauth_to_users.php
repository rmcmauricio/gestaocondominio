<?php

class AddGoogleOAuthToUsers
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Make password nullable for OAuth users
        $this->db->exec("ALTER TABLE users MODIFY password VARCHAR(255) NULL");
        
        // Add google_id column
        $this->db->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL AFTER password");
        
        // Add auth_provider column
        $this->db->exec("ALTER TABLE users ADD COLUMN auth_provider ENUM('local', 'google') NOT NULL DEFAULT 'local' AFTER google_id");
        
        // Add index on google_id for faster lookups
        $this->db->exec("CREATE INDEX idx_google_id ON users(google_id)");
    }

    public function down(): void
    {
        try {
            // Remove index (try different syntaxes for compatibility)
            try {
                $this->db->exec("DROP INDEX idx_google_id ON users");
            } catch (\Exception $e) {
                try {
                    $this->db->exec("ALTER TABLE users DROP INDEX idx_google_id");
                } catch (\Exception $e2) {
                    // Index might not exist, continue
                }
            }
        } catch (\Exception $e) {
            // Ignore if index doesn't exist
        }
        
        // Remove columns
        try {
            $this->db->exec("ALTER TABLE users DROP COLUMN auth_provider");
        } catch (\Exception $e) {
            // Column might not exist
        }
        
        try {
            $this->db->exec("ALTER TABLE users DROP COLUMN google_id");
        } catch (\Exception $e) {
            // Column might not exist
        }
        
        // Make password NOT NULL again (this might fail if there are OAuth users, but that's expected in rollback)
        try {
            $this->db->exec("ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL");
        } catch (\Exception $e) {
            // Might fail if there are OAuth users with NULL passwords
        }
    }
}
