<?php

class AddMinutesReviewToDocuments
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'review_deadline'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE documents ADD COLUMN review_deadline DATE NULL");
        }
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'review_sent_at'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE documents ADD COLUMN review_sent_at DATETIME NULL");
        }
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'status'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE documents MODIFY COLUMN status ENUM('draft','in_review','approved') DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        $this->db->exec("ALTER TABLE documents DROP COLUMN IF EXISTS review_deadline");
        $this->db->exec("ALTER TABLE documents DROP COLUMN IF EXISTS review_sent_at");
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'status'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE documents MODIFY COLUMN status ENUM('draft','approved') DEFAULT 'draft'");
        }
    }
}
