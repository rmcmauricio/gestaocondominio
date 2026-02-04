<?php

class CreateMinutesRevisionsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS minutes_revisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            assembly_id INT NOT NULL,
            fraction_id INT NOT NULL,
            user_id INT NOT NULL,
            accepted BOOLEAN DEFAULT FALSE,
            comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_document_fraction (document_id, fraction_id),
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_document_id (document_id),
            INDEX idx_assembly_id (assembly_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS minutes_revisions");
    }
}
