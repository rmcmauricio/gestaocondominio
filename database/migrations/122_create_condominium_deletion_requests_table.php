<?php

class CreateCondominiumDeletionRequestsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $checkStmt = $this->db->query("SHOW TABLES LIKE 'condominium_deletion_requests'");
        if ($checkStmt->rowCount() > 0) {
            return;
        }

        $sql = "CREATE TABLE condominium_deletion_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            condominium_id INT NOT NULL,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_user_id (user_id),
            INDEX idx_expires_at (expires_at),
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS condominium_deletion_requests");
    }
}
