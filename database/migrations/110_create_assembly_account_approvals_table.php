<?php

class CreateAssemblyAccountApprovalsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS assembly_account_approvals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assembly_id INT NOT NULL,
            condominium_id INT NOT NULL,
            approved_year YEAR NOT NULL,
            approved_at DATETIME NOT NULL,
            approved_by INT NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_condominium_year (condominium_id, approved_year),
            INDEX idx_assembly_id (assembly_id),
            INDEX idx_approved_year (approved_year),
            INDEX idx_condominium_id (condominium_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS assembly_account_approvals");
    }
}
