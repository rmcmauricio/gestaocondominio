<?php

class CreateOccurrenceHistoryTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS occurrence_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            occurrence_id INT NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            field_name VARCHAR(100) NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (occurrence_id) REFERENCES occurrences(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_occurrence_id (occurrence_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS occurrence_history");
    }
}
