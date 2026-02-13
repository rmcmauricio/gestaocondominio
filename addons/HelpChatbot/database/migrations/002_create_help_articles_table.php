<?php

class CreateHelpArticlesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS help_articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section_key VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body_text TEXT NULL,
            url_path VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_section (section_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS help_articles");
    }
}
