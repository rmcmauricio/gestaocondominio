<?php

class CreateAssemblyAccountApprovalEventsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS assembly_account_approval_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assembly_id INT NOT NULL,
            condominium_id INT NOT NULL,
            approved_year YEAR NOT NULL,
            action ENUM('approval','reopening') NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id INT NOT NULL,
            notes TEXT NULL,
            FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_assembly_id (assembly_id),
            INDEX idx_condominium_year (condominium_id, approved_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS assembly_account_approval_events");
    }
}
