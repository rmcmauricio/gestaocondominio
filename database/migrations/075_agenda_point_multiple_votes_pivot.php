<?php

class AgendaPointMultipleVotesPivot
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS assembly_agenda_point_vote_topics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agenda_point_id INT NOT NULL,
                vote_topic_id INT NOT NULL,
                order_index INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_point_topic (agenda_point_id, vote_topic_id),
                FOREIGN KEY (agenda_point_id) REFERENCES assembly_agenda_points(id) ON DELETE CASCADE,
                FOREIGN KEY (vote_topic_id) REFERENCES assembly_vote_topics(id) ON DELETE CASCADE,
                INDEX idx_agenda_point (agenda_point_id),
                INDEX idx_vote_topic (vote_topic_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            INSERT INTO assembly_agenda_point_vote_topics (agenda_point_id, vote_topic_id, order_index)
            SELECT id, vote_topic_id, 0 FROM assembly_agenda_points WHERE vote_topic_id IS NOT NULL
        ");

        $stmt = $this->db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assembly_agenda_points' AND COLUMN_NAME = 'vote_topic_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
        $row = $stmt ? $stmt->fetch() : null;
        if ($row && !empty($row['CONSTRAINT_NAME'])) {
            $this->db->exec("ALTER TABLE assembly_agenda_points DROP FOREIGN KEY " . $row['CONSTRAINT_NAME']);
        }
        $this->db->exec("ALTER TABLE assembly_agenda_points DROP COLUMN vote_topic_id");
    }

    public function down(): void
    {
        $this->db->exec("ALTER TABLE assembly_agenda_points ADD COLUMN vote_topic_id INT NULL AFTER body");
        $this->db->exec("
            UPDATE assembly_agenda_points ap
            SET ap.vote_topic_id = (SELECT vote_topic_id FROM assembly_agenda_point_vote_topics v WHERE v.agenda_point_id = ap.id ORDER BY v.order_index ASC LIMIT 1)
        ");
        $this->db->exec("ALTER TABLE assembly_agenda_points ADD FOREIGN KEY (vote_topic_id) REFERENCES assembly_vote_topics(id) ON DELETE SET NULL");
        $this->db->exec("DROP TABLE IF EXISTS assembly_agenda_point_vote_topics");
    }
}
