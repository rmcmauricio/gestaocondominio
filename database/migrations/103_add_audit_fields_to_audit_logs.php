<?php

class AddAuditFieldsToAuditLogs
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if columns already exist to avoid errors on re-run
        $stmt = $this->db->query("SHOW COLUMNS FROM audit_logs LIKE 'old_data'");
        $hasOldData = $stmt->rowCount() > 0;

        if (!$hasOldData) {
            // Add old_data column
            $this->db->exec("
                ALTER TABLE audit_logs 
                ADD COLUMN old_data JSON NULL COMMENT 'Dados antes da alteração (para UPDATE/DELETE)' 
                AFTER description
            ");
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM audit_logs LIKE 'new_data'");
        $hasNewData = $stmt->rowCount() > 0;

        if (!$hasNewData) {
            // Add new_data column
            $this->db->exec("
                ALTER TABLE audit_logs 
                ADD COLUMN new_data JSON NULL COMMENT 'Dados após a alteração (para INSERT/UPDATE)' 
                AFTER old_data
            ");
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM audit_logs LIKE 'table_name'");
        $hasTableName = $stmt->rowCount() > 0;

        if (!$hasTableName) {
            // Add table_name column
            $this->db->exec("
                ALTER TABLE audit_logs 
                ADD COLUMN table_name VARCHAR(100) NULL COMMENT 'Nome da tabela afetada' 
                AFTER new_data
            ");
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM audit_logs LIKE 'operation'");
        $hasOperation = $stmt->rowCount() > 0;

        if (!$hasOperation) {
            // Add operation column
            $this->db->exec("
                ALTER TABLE audit_logs 
                ADD COLUMN operation VARCHAR(50) NULL COMMENT 'Tipo de operação: insert, update, delete' 
                AFTER table_name
            ");
        }

        // Add indexes for better query performance
        try {
            $stmt = $this->db->query("SHOW INDEX FROM audit_logs WHERE Key_name = 'idx_table_name'");
            if ($stmt->rowCount() == 0) {
                $this->db->exec("CREATE INDEX idx_table_name ON audit_logs(table_name)");
            }
        } catch (\Exception $e) {
            // Index might already exist, ignore
        }

        try {
            $stmt = $this->db->query("SHOW INDEX FROM audit_logs WHERE Key_name = 'idx_operation'");
            if ($stmt->rowCount() == 0) {
                $this->db->exec("CREATE INDEX idx_operation ON audit_logs(operation)");
            }
        } catch (\Exception $e) {
            // Index might already exist, ignore
        }

        try {
            $stmt = $this->db->query("SHOW INDEX FROM audit_logs WHERE Key_name = 'idx_table_operation'");
            if ($stmt->rowCount() == 0) {
                $this->db->exec("CREATE INDEX idx_table_operation ON audit_logs(table_name, operation)");
            }
        } catch (\Exception $e) {
            // Index might already exist, ignore
        }
    }

    public function down(): void
    {
        // Remove indexes first
        try {
            $this->db->exec("DROP INDEX idx_table_operation ON audit_logs");
        } catch (\Exception $e) {
            // Ignore if doesn't exist
        }

        try {
            $this->db->exec("DROP INDEX idx_operation ON audit_logs");
        } catch (\Exception $e) {
            // Ignore if doesn't exist
        }

        try {
            $this->db->exec("DROP INDEX idx_table_name ON audit_logs");
        } catch (\Exception $e) {
            // Ignore if doesn't exist
        }

        // Remove columns
        try {
            $this->db->exec("ALTER TABLE audit_logs DROP COLUMN operation");
        } catch (\Exception $e) {
            // Ignore if doesn't exist
        }

        try {
            $this->db->exec("ALTER TABLE audit_logs DROP COLUMN table_name");
        } catch (\Exception $e) {
            // Ignore if doesn't exist
        }

        try {
            $this->db->exec("ALTER TABLE audit_logs DROP COLUMN new_data");
        } catch (\Exception $e) {
            // Ignore if doesn't exist
        }

        try {
            $this->db->exec("ALTER TABLE audit_logs DROP COLUMN old_data");
        } catch (\Exception $e) {
            // Ignore if doesn't exist
        }
    }
}
