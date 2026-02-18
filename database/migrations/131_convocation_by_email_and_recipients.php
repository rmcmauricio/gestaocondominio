<?php

class ConvocationByEmailAndRecipients
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM fractions LIKE 'receives_convocation_by_email'");
        if ($stmt->rowCount() === 0) {
            $this->db->exec("ALTER TABLE fractions ADD COLUMN receives_convocation_by_email TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=recebe convocatória por email, 0=não' AFTER notes");
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS assembly_convocation_recipients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                assembly_id INT NOT NULL,
                fraction_id INT NOT NULL,
                sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_assembly_fraction (assembly_id, fraction_id),
                FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
                FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
                INDEX idx_assembly_id (assembly_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS assembly_convocation_recipients");
        try {
            $this->db->exec("ALTER TABLE fractions DROP COLUMN receives_convocation_by_email");
        } catch (\Exception $e) {
            // Column might not exist
        }
    }
}
