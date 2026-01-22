<?php

class CreateAuditDocumentsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS audit_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NULL,
            document_id INT NULL,
            document_type VARCHAR(50) NOT NULL,
            action VARCHAR(100) NOT NULL,
            user_id INT NULL,
            assembly_id INT NULL,
            receipt_id INT NULL,
            fee_id INT NULL,
            file_path VARCHAR(500) NULL,
            file_name VARCHAR(255) NULL,
            file_size INT NULL,
            description TEXT NULL,
            metadata JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE SET NULL,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE SET NULL,
            FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE SET NULL,
            FOREIGN KEY (fee_id) REFERENCES fees(id) ON DELETE SET NULL,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_document_id (document_id),
            INDEX idx_document_type (document_type),
            INDEX idx_action (action),
            INDEX idx_user_id (user_id),
            INDEX idx_assembly_id (assembly_id),
            INDEX idx_receipt_id (receipt_id),
            INDEX idx_created_at (created_at),
            INDEX idx_created_at_action (created_at, action),
            INDEX idx_document_type_action (document_type, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS audit_documents");
    }
}
