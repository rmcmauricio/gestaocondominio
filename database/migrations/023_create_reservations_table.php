<?php

class CreateReservationsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            space_id INT NOT NULL,
            fraction_id INT NOT NULL,
            user_id INT NOT NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'completed', 'canceled') DEFAULT 'pending',
            price DECIMAL(10,2) DEFAULT 0,
            deposit DECIMAL(10,2) DEFAULT 0,
            deposit_paid BOOLEAN DEFAULT FALSE,
            notes TEXT NULL,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
            FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_space_id (space_id),
            INDEX idx_fraction_id (fraction_id),
            INDEX idx_user_id (user_id),
            INDEX idx_status (status),
            INDEX idx_start_date (start_date),
            INDEX idx_end_date (end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS reservations");
    }
}


