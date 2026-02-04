<?php

class CreateUserEmailPreferencesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Create user_email_preferences table
        $sql = "CREATE TABLE IF NOT EXISTS user_email_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            email_notifications_enabled BOOLEAN DEFAULT TRUE,
            email_messages_enabled BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);

        // Fix: Mark all users associated with demo condominiums as demo users
        // This ensures all demo users (not just the admin) have is_demo = TRUE
        $fixDemoUsersSql = "
            UPDATE users u 
            INNER JOIN condominium_users cu ON u.id = cu.user_id 
            INNER JOIN condominiums c ON cu.condominium_id = c.id 
            SET u.is_demo = TRUE 
            WHERE c.is_demo = TRUE 
            AND (u.is_demo = FALSE OR u.is_demo IS NULL)
        ";
        
        try {
            $this->db->exec($fixDemoUsersSql);
            $affectedRows = $this->db->query("SELECT ROW_COUNT()")->fetchColumn();
            if ($affectedRows > 0) {
                error_log("Fixed {$affectedRows} demo users - marked with is_demo = TRUE");
            }
        } catch (\Exception $e) {
            error_log("Error fixing demo users flag: " . $e->getMessage());
        }
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS user_email_preferences");
    }
}
