<?php

class CreateMessageAttachmentsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if table already exists
        $stmt = $this->db->query("SHOW TABLES LIKE 'message_attachments'");
        if ($stmt->rowCount() > 0) {
            return; // Table already exists
        }

        $this->db->exec("
            CREATE TABLE message_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                condominium_id INT NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_size INT NOT NULL DEFAULT 0,
                mime_type VARCHAR(100) NULL,
                uploaded_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_message_id (message_id),
                INDEX idx_condominium_id (condominium_id),
                INDEX idx_uploaded_by (uploaded_by),
                FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
                FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
                FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        // Drop table if exists
        $stmt = $this->db->query("SHOW TABLES LIKE 'message_attachments'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("DROP TABLE message_attachments");
        }
    }
}
