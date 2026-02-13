<?php

class CreateRevenueCategoriesAndAddCategoryToRevenues
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS revenue_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            INDEX idx_condominium_id (condominium_id),
            UNIQUE KEY unique_condominium_name (condominium_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);

        // Add category column to revenues if not exists
        $cols = $this->db->query("SHOW COLUMNS FROM revenues LIKE 'category'")->fetchAll();
        if (empty($cols)) {
            $this->db->exec("ALTER TABLE revenues ADD COLUMN category VARCHAR(100) NULL AFTER reference");
        }
    }

    public function down(): void
    {
        $cols = $this->db->query("SHOW COLUMNS FROM revenues LIKE 'category'")->fetchAll();
        if (!empty($cols)) {
            $this->db->exec("ALTER TABLE revenues DROP COLUMN category");
        }
        $this->db->exec("DROP TABLE IF EXISTS revenue_categories");
    }
}
