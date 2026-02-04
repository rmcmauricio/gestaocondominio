<?php

class CreateAssemblyAgendaPointsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS assembly_agenda_points (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assembly_id INT NOT NULL,
            order_index INT DEFAULT 0,
            title VARCHAR(255) NOT NULL,
            body TEXT NULL,
            vote_topic_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
            FOREIGN KEY (vote_topic_id) REFERENCES assembly_vote_topics(id) ON DELETE SET NULL,
            INDEX idx_assembly (assembly_id),
            INDEX idx_order (order_index)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS assembly_agenda_points");
    }
}
