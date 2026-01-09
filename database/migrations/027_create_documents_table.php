<?php

class CreateDocumentsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            fraction_id INT NULL,
            folder VARCHAR(255) NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size INT NULL,
            mime_type VARCHAR(100) NULL,
            visibility ENUM('admin', 'condominos', 'fraction') DEFAULT 'condominos',
            document_type VARCHAR(100) NULL,
            version INT DEFAULT 1,
            parent_document_id INT NULL,
            uploaded_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE SET NULL,
            FOREIGN KEY (parent_document_id) REFERENCES documents(id) ON DELETE SET NULL,
            FOREIGN KEY (uploaded_by) REFERENCES users(id),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_fraction_id (fraction_id),
            INDEX idx_folder (folder),
            INDEX idx_visibility (visibility),
            INDEX idx_document_type (document_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS documents");
    }
}


