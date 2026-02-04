<?php

class OptimizeAuditLogsIndexes
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add composite indexes for common query patterns
        // These indexes will significantly improve performance for filtered queries
        
        // Index for date range queries (most common filter)
        // This allows efficient filtering by date range
        try {
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_created_at_action 
                ON audit_logs(created_at, action)
            ");
        } catch (\Exception $e) {
            // Index might already exist, continue
        }
        
        // Index for user + date queries
        try {
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_user_created_at 
                ON audit_logs(user_id, created_at)
            ");
        } catch (\Exception $e) {
            // Index might already exist, continue
        }
        
        // Index for model + date queries
        try {
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_model_created_at 
                ON audit_logs(model, created_at)
            ");
        } catch (\Exception $e) {
            // Index might already exist, continue
        }
        
        // Composite index for common filter combinations (date DESC for most recent first)
        try {
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_created_at_model_action 
                ON audit_logs(created_at DESC, model, action)
            ");
        } catch (\Exception $e) {
            // Index might already exist, continue
        }
    }

    public function down(): void
    {
        // Remove indexes
        try {
            $this->db->exec("DROP INDEX idx_created_at_action ON audit_logs");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_user_created_at ON audit_logs");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_model_created_at ON audit_logs");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_created_at_model_action ON audit_logs");
        } catch (\Exception $e) {}
    }
}
