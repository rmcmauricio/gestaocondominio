<?php

class CreateCondominiumSetupWizardProgressTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS condominium_setup_wizard_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            current_step TINYINT UNSIGNED NOT NULL DEFAULT 0,
            completed_steps JSON NULL,
            skipped_steps JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_condominium (condominium_id),
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            INDEX idx_current_step (current_step)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS condominium_setup_wizard_progress");
    }
}
