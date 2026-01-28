<?php

class AddAmountTypeAndDocumentToContracts
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add amount_type column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM contracts LIKE 'amount_type'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN amount_type ENUM('annual', 'monthly') DEFAULT 'annual' AFTER amount");
        }

        // Add document_id column if it doesn't exist
        $stmt = $this->db->query("SHOW COLUMNS FROM contracts LIKE 'document_id'");
        if ($stmt->rowCount() == 0) {
            $this->db->exec("ALTER TABLE contracts ADD COLUMN document_id INT NULL AFTER attachments");
            $this->db->exec("ALTER TABLE contracts ADD INDEX idx_document_id (document_id)");
            $this->db->exec("ALTER TABLE contracts ADD CONSTRAINT fk_contracts_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL");
        }
    }

    public function down(): void
    {
        // Remove foreign key constraint
        $this->db->exec("ALTER TABLE contracts DROP FOREIGN KEY IF EXISTS fk_contracts_document");
        
        // Remove document_id column
        $this->db->exec("ALTER TABLE contracts DROP COLUMN IF EXISTS document_id");
        
        // Remove amount_type column
        $this->db->exec("ALTER TABLE contracts DROP COLUMN IF EXISTS amount_type");
    }
}
