<?php

class DropMinutesSignaturesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS minutes_signatures");
    }

    public function down(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS minutes_signatures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            assembly_id INT NOT NULL,
            user_id INT NOT NULL,
            fraction_id INT NOT NULL,
            signature_type ENUM('digital', 'manual') DEFAULT 'digital',
            signature_data TEXT NULL,
            signed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_valid BOOLEAN DEFAULT TRUE,
            invalidated_at TIMESTAMP NULL,
            invalidation_reason VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            INDEX idx_document_id (document_id),
            INDEX idx_assembly_id (assembly_id),
            INDEX idx_user_id (user_id),
            INDEX idx_fraction_id (fraction_id),
            INDEX idx_is_valid (is_valid),
            INDEX idx_document_fraction (document_id, fraction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->exec($sql);
    }
}
