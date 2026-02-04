<?php

class OptimizeAuditTablesIndexes
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Optimize audit_payments table
        // Add composite indexes for common filter combinations
        // These indexes optimize queries filtering by user_id + date, action + date, etc.
        try {
            $this->db->exec("
                CREATE INDEX idx_payments_user_created_at 
                ON audit_payments(user_id, created_at DESC)
            ");
        } catch (\Exception $e) {
            // Index might already exist, continue
        }
        
        try {
            $this->db->exec("
                CREATE INDEX idx_payments_action_created_at 
                ON audit_payments(action, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_payments_status_created_at 
                ON audit_payments(status, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_payments_user_action_created_at 
                ON audit_payments(user_id, action, created_at DESC)
            ");
        } catch (\Exception $e) {}

        // Optimize audit_financial table
        // Add composite indexes for common filter combinations
        try {
            $this->db->exec("
                CREATE INDEX idx_financial_user_created_at 
                ON audit_financial(user_id, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_financial_action_created_at 
                ON audit_financial(action, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_financial_entity_type_created_at 
                ON audit_financial(entity_type, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_financial_condominium_action_created_at 
                ON audit_financial(condominium_id, action, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_financial_user_action_created_at 
                ON audit_financial(user_id, action, created_at DESC)
            ");
        } catch (\Exception $e) {}

        // Optimize audit_subscriptions table
        // Add composite indexes for common filter combinations
        try {
            $this->db->exec("
                CREATE INDEX idx_subscriptions_user_created_at 
                ON audit_subscriptions(user_id, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_subscriptions_action_created_at 
                ON audit_subscriptions(action, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_subscriptions_status_created_at 
                ON audit_subscriptions(new_status, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_subscriptions_user_action_created_at 
                ON audit_subscriptions(user_id, action, created_at DESC)
            ");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("
                CREATE INDEX idx_subscriptions_performed_by_created_at 
                ON audit_subscriptions(performed_by, created_at DESC)
            ");
        } catch (\Exception $e) {}

        // Note: Full-text indexes would be better for LIKE searches on description,
        // but require different syntax. For now, we rely on date filters to limit
        // the scope before LIKE operations, which is more efficient.
    }

    public function down(): void
    {
        // Remove indexes from audit_payments
        try {
            $this->db->exec("DROP INDEX idx_payments_user_created_at ON audit_payments");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_payments_action_created_at ON audit_payments");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_payments_status_created_at ON audit_payments");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_payments_user_action_created_at ON audit_payments");
        } catch (\Exception $e) {}

        // Remove indexes from audit_financial
        try {
            $this->db->exec("DROP INDEX idx_financial_user_created_at ON audit_financial");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_financial_action_created_at ON audit_financial");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_financial_entity_type_created_at ON audit_financial");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_financial_condominium_action_created_at ON audit_financial");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_financial_user_action_created_at ON audit_financial");
        } catch (\Exception $e) {}

        // Remove indexes from audit_subscriptions
        try {
            $this->db->exec("DROP INDEX idx_subscriptions_user_created_at ON audit_subscriptions");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_subscriptions_action_created_at ON audit_subscriptions");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_subscriptions_status_created_at ON audit_subscriptions");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_subscriptions_user_action_created_at ON audit_subscriptions");
        } catch (\Exception $e) {}
        
        try {
            $this->db->exec("DROP INDEX idx_subscriptions_performed_by_created_at ON audit_subscriptions");
        } catch (\Exception $e) {}
    }
}
