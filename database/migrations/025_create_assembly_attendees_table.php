<?php

class CreateAssemblyAttendeesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS assembly_attendees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assembly_id INT NOT NULL,
            user_id INT NULL,
            fraction_id INT NOT NULL,
            attendance_type ENUM('present', 'proxy', 'absent') DEFAULT 'absent',
            proxy_user_id INT NULL,
            proxy_document VARCHAR(255) NULL,
            signed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            FOREIGN KEY (proxy_user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_assembly_id (assembly_id),
            INDEX idx_user_id (user_id),
            INDEX idx_fraction_id (fraction_id),
            UNIQUE KEY unique_assembly_fraction (assembly_id, fraction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS assembly_attendees");
    }
}


