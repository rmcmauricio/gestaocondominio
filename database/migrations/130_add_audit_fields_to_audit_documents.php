<?php

class AddAuditFieldsToAuditDocuments
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $stmt = $this->db->query("SHOW TABLES LIKE 'audit_documents'");
        if ($stmt->rowCount() === 0) {
            return;
        }

        $columns = [
            ['name' => 'model', 'sql' => "ADD COLUMN model VARCHAR(100) NULL COMMENT 'Model/entity name' AFTER user_agent"],
            ['name' => 'model_id', 'sql' => "ADD COLUMN model_id INT NULL COMMENT 'Model/entity ID' AFTER model"],
            ['name' => 'old_data', 'sql' => "ADD COLUMN old_data JSON NULL COMMENT 'Data before change (UPDATE/DELETE)' AFTER description"],
            ['name' => 'new_data', 'sql' => "ADD COLUMN new_data JSON NULL COMMENT 'Data after change (INSERT/UPDATE)' AFTER old_data"],
            ['name' => 'table_name', 'sql' => "ADD COLUMN table_name VARCHAR(100) NULL COMMENT 'Affected table name' AFTER new_data"],
            ['name' => 'operation', 'sql' => "ADD COLUMN operation VARCHAR(50) NULL COMMENT 'Operation: insert, update, delete' AFTER table_name"],
        ];

        foreach ($columns as $col) {
            $stmt = $this->db->query("SHOW COLUMNS FROM audit_documents LIKE '{$col['name']}'");
            if ($stmt->rowCount() === 0) {
                $this->db->exec("ALTER TABLE audit_documents {$col['sql']}");
            }
        }

        foreach (['table_name', 'operation'] as $idxCol) {
            try {
                $stmt = $this->db->query("SHOW INDEX FROM audit_documents WHERE Key_name = 'idx_audit_documents_{$idxCol}'");
                if ($stmt->rowCount() == 0) {
                    $this->db->exec("CREATE INDEX idx_audit_documents_{$idxCol} ON audit_documents({$idxCol})");
                }
            } catch (\Exception $e) {
                // Index might already exist
            }
        }
    }

    public function down(): void
    {
        $stmt = $this->db->query("SHOW TABLES LIKE 'audit_documents'");
        if ($stmt->rowCount() === 0) {
            return;
        }
        foreach (['idx_audit_documents_operation', 'idx_audit_documents_table_name'] as $idx) {
            try {
                $this->db->exec("DROP INDEX {$idx} ON audit_documents");
            } catch (\Exception $e) {
                // Ignore
            }
        }
        foreach (['operation', 'table_name', 'new_data', 'old_data', 'model_id', 'model'] as $col) {
            try {
                $this->db->exec("ALTER TABLE audit_documents DROP COLUMN {$col}");
            } catch (\Exception $e) {
                // Ignore
            }
        }
    }
}
