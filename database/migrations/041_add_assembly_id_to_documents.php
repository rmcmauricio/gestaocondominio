<?php

class AddAssemblyIdToDocuments
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add assembly_id column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'assembly_id'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE documents ADD COLUMN assembly_id INT NULL AFTER condominium_id");
            $this->db->exec("ALTER TABLE documents ADD INDEX idx_assembly_id (assembly_id)");
            $this->db->exec("ALTER TABLE documents ADD CONSTRAINT fk_documents_assembly FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE SET NULL");
        }

        // Add status column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'status'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE documents ADD COLUMN status ENUM('draft', 'approved') DEFAULT 'draft' AFTER document_type");
        }
    }

    public function down(): void
    {
        // Remove foreign key constraint
        $this->db->exec("ALTER TABLE documents DROP FOREIGN KEY IF EXISTS fk_documents_assembly");
        
        // Remove assembly_id column
        $this->db->exec("ALTER TABLE documents DROP COLUMN IF EXISTS assembly_id");
        
        // Remove status column
        $this->db->exec("ALTER TABLE documents DROP COLUMN IF EXISTS status");
    }
}
