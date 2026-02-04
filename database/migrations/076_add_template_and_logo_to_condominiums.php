<?php

class AddTemplateAndLogoToCondominiums
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if columns already exist
        $stmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'document_template'");
        $hasDocumentTemplate = $stmt->rowCount() > 0;
        
        $stmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'logo_path'");
        $hasLogoPath = $stmt->rowCount() > 0;

        // Add document_template column if it doesn't exist
        if (!$hasDocumentTemplate) {
            $this->db->exec("
                ALTER TABLE condominiums 
                ADD COLUMN document_template INT DEFAULT 1 NOT NULL 
                COMMENT 'Template ID (1-7) for documents and platform interface'
                AFTER settings
            ");
        }

        // Add logo_path column if it doesn't exist
        if (!$hasLogoPath) {
            $this->db->exec("
                ALTER TABLE condominiums 
                ADD COLUMN logo_path VARCHAR(255) NULL 
                COMMENT 'Relative path to condominium logo file'
                AFTER document_template
            ");
        }
    }

    public function down(): void
    {
        // Check if columns exist before dropping
        $stmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'logo_path'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE condominiums DROP COLUMN logo_path");
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'document_template'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE condominiums DROP COLUMN document_template");
        }
    }
}
