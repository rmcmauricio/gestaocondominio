<?php

class AllowNullDocumentTemplate
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if column exists
        $stmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'document_template'");
        if ($stmt->rowCount() > 0) {
            // Alter column to allow NULL
            $this->db->exec("
                ALTER TABLE condominiums 
                MODIFY COLUMN document_template INT NULL 
                COMMENT 'Template ID (1-7) for documents and platform interface, NULL for default template'
            ");
        }
    }

    public function down(): void
    {
        // Revert to NOT NULL with default value
        $stmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'document_template'");
        if ($stmt->rowCount() > 0) {
            // First set all NULL values to 1 (default)
            $this->db->exec("UPDATE condominiums SET document_template = 1 WHERE document_template IS NULL");
            
            // Then alter column back to NOT NULL
            $this->db->exec("
                ALTER TABLE condominiums 
                MODIFY COLUMN document_template INT DEFAULT 1 NOT NULL 
                COMMENT 'Template ID (1-7) for documents and platform interface'
            ");
        }
    }
}
