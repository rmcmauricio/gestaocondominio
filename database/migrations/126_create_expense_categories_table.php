<?php

class CreateExpenseCategoriesTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS expense_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            condominium_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE,
            INDEX idx_condominium_id (condominium_id),
            UNIQUE KEY unique_condominium_name (condominium_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);

        // Seed from existing distinct category values in financial_transactions
        $stmt = $this->db->query("
            SELECT DISTINCT condominium_id, TRIM(category) as name
            FROM financial_transactions
            WHERE transaction_type = 'expense'
            AND category IS NOT NULL
            AND TRIM(category) != ''
            ORDER BY condominium_id, name
        ");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $insertStmt = $this->db->prepare("
            INSERT IGNORE INTO expense_categories (condominium_id, name)
            VALUES (:condominium_id, :name)
        ");
        foreach ($rows as $row) {
            $insertStmt->execute([
                ':condominium_id' => $row['condominium_id'],
                ':name' => $row['name']
            ]);
        }
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS expense_categories");
    }
}
