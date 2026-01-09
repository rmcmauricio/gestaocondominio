<?php

class CreateFractionAssociationsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS fraction_associations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fraction_id INT NOT NULL,
            association_type ENUM('garagem', 'arrecadacao', 'loja', 'outro') NOT NULL,
            identifier VARCHAR(50) NULL,
            area DECIMAL(10,2) NULL,
            permillage DECIMAL(8,4) DEFAULT 0,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            INDEX idx_fraction_id (fraction_id),
            INDEX idx_association_type (association_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS fraction_associations");
    }
}






