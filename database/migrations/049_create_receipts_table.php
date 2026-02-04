<?php

class CreateReceiptsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if table already exists
        $stmt = $this->db->query("SHOW TABLES LIKE 'receipts'");
        if ($stmt->rowCount() > 0) {
            return; // Table already exists
        }

        $this->db->exec("
            CREATE TABLE receipts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fee_id INT NOT NULL,
                fee_payment_id INT NULL,
                condominium_id INT NOT NULL,
                fraction_id INT NOT NULL,
                receipt_number VARCHAR(50) NOT NULL UNIQUE,
                receipt_type ENUM('partial', 'final') NOT NULL DEFAULT 'partial',
                amount DECIMAL(10, 2) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_size INT NOT NULL DEFAULT 0,
                generated_at DATETIME NOT NULL,
                generated_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_fee_id (fee_id),
                INDEX idx_fee_payment_id (fee_payment_id),
                INDEX idx_condominium_id (condominium_id),
                INDEX idx_fraction_id (fraction_id),
                INDEX idx_receipt_number (receipt_number),
                INDEX idx_receipt_type (receipt_type),
                INDEX idx_generated_at (generated_at),
                FOREIGN KEY (fee_id) REFERENCES fees(id) ON DELETE CASCADE,
                FOREIGN KEY (fee_payment_id) REFERENCES fee_payments(id) ON DELETE SET NULL,
                FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
                FOREIGN KEY (fraction_id) REFERENCES fractions(id) ON DELETE CASCADE,
                FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        // Drop table if exists
        $stmt = $this->db->query("SHOW TABLES LIKE 'receipts'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("DROP TABLE receipts");
        }
    }
}
