<?php

class AddDemoFlagToUsers
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add is_demo column
        $stmt = $this->db->query("SHOW COLUMNS FROM users LIKE 'is_demo'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE users ADD COLUMN is_demo BOOLEAN DEFAULT FALSE AFTER status");
            $this->db->exec("ALTER TABLE users ADD INDEX idx_is_demo (is_demo)");
        }

        // Create demo user if it doesn't exist
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = 'demo@predio.pt' LIMIT 1");
        $stmt->execute();
        $existingDemo = $stmt->fetch();

        if (!$existingDemo) {
            $password = password_hash('Demo@2024', PASSWORD_ARGON2ID);
            
            $stmt = $this->db->prepare("
                INSERT INTO users (email, password, name, role, status, is_demo, email_verified_at)
                VALUES (:email, :password, :name, 'admin', 'active', TRUE, NOW())
            ");
            
            $stmt->execute([
                'email' => 'demo@predio.pt',
                'password' => $password,
                'name' => 'Utilizador Demo'
            ]);
        } else {
            // Update existing demo user
            $stmt = $this->db->prepare("UPDATE users SET is_demo = TRUE WHERE email = 'demo@predio.pt'");
            $stmt->execute();
        }
    }

    public function down(): void
    {
        // Remove demo flag from demo user
        $this->db->exec("UPDATE users SET is_demo = FALSE WHERE email = 'demo@predio.pt'");
        
        // Remove column
        $this->db->exec("ALTER TABLE users DROP COLUMN IF EXISTS is_demo");
    }
}
