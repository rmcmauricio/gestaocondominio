<?php

class CreateNewsletterSubscribersTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if table already exists
        $checkStmt = $this->db->query("SHOW TABLES LIKE 'newsletter_subscribers'");
        if ($checkStmt->rowCount() > 0) {
            // Table exists, skip creation
            return;
        }

        $sql = "CREATE TABLE newsletter_subscribers (
            id INT PRIMARY KEY AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL UNIQUE,
            subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            unsubscribed_at DATETIME NULL,
            source VARCHAR(50) DEFAULT 'demo_access',
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS newsletter_subscribers");
    }
}
