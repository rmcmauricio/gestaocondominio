<?php

class CreateSeparatedAuditTables
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Get list of tables that should have their own audit tables
     * Excludes tables that already have specialized audit tables
     */
    protected function getAuditedTables(): array
    {
        return [
            'users',
            'condominiums',
            'fractions',
            'fees',
            'fee_payments',
            'expenses',
            'revenues',
            'budgets',
            'budget_items',
            'financial_transactions',
            'occurrences',
            'assemblies',
            'reservations',
            'folders',
            'contracts',
            'suppliers',
            'bank_accounts',
            'spaces',
            'messages',
            'assembly_attendees',
            'occurrence_comments',
            'occurrence_attachments',
            'message_attachments',
            'receipts',
            'subscriptions',
            'subscription_condominiums',
            'plans',
            'plan_pricing_tiers',
            'promotions',
            'invoices',
            'condominium_users',
            'fraction_accounts',
            'fraction_account_movements',
            'votes',
            'vote_options',
            'vote_topics',
            'standalone_votes',
            'standalone_vote_responses',
        ];
    }

    /**
     * Create audit table for a specific entity table
     */
    protected function createAuditTable(string $tableName): void
    {
        $auditTableName = 'audit_' . $tableName;
        
        // Skip if table already exists
        $stmt = $this->db->query("SHOW TABLES LIKE '{$auditTableName}'");
        if ($stmt->rowCount() > 0) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$auditTableName} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            model VARCHAR(100) NULL,
            model_id INT NULL,
            description TEXT NULL,
            old_data JSON NULL COMMENT 'Dados antes da alteração (para UPDATE/DELETE)',
            new_data JSON NULL COMMENT 'Dados após a alteração (para INSERT/UPDATE)',
            table_name VARCHAR(100) NOT NULL COMMENT 'Nome da tabela afetada',
            operation VARCHAR(50) NOT NULL COMMENT 'Tipo de operação: insert, update, delete',
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_model (model, model_id),
            INDEX idx_table_name (table_name),
            INDEX idx_operation (operation),
            INDEX idx_table_operation (table_name, operation),
            INDEX idx_model_id (model_id),
            INDEX idx_created_at (created_at),
            INDEX idx_created_at_action (created_at, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function up(): void
    {
        $tables = $this->getAuditedTables();
        
        foreach ($tables as $tableName) {
            try {
                $this->createAuditTable($tableName);
            } catch (\Exception $e) {
                // Log error but continue with other tables
                error_log("Error creating audit table for {$tableName}: " . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        $tables = $this->getAuditedTables();
        
        foreach ($tables as $tableName) {
            $auditTableName = 'audit_' . $tableName;
            try {
                $this->db->exec("DROP TABLE IF EXISTS {$auditTableName}");
            } catch (\Exception $e) {
                // Log error but continue
                error_log("Error dropping audit table {$auditTableName}: " . $e->getMessage());
            }
        }
    }
}
