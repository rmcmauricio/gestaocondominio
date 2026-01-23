<?php

class CreateArchiveTables
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Create notifications_archive table
        $sql = "CREATE TABLE IF NOT EXISTS notifications_archive (
            id INT NOT NULL,
            user_id INT NULL,
            condominium_id INT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(500) NULL,
            is_read BOOLEAN DEFAULT FALSE,
            read_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_is_read (is_read),
            INDEX idx_type (type),
            INDEX idx_created_at (created_at),
            INDEX idx_archived_at (archived_at),
            INDEX idx_user_archived (user_id, archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);

        // Create audit_logs_archive table
        $sql = "CREATE TABLE IF NOT EXISTS audit_logs_archive (
            id INT NOT NULL,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            model VARCHAR(100) NULL,
            model_id INT NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP NOT NULL,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_model (model, model_id),
            INDEX idx_created_at (created_at),
            INDEX idx_archived_at (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);

        // Create audit_payments_archive table
        $sql = "CREATE TABLE IF NOT EXISTS audit_payments_archive (
            id INT NOT NULL,
            payment_id INT NULL,
            subscription_id INT NULL,
            invoice_id INT NULL,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            payment_method VARCHAR(50) NULL,
            amount DECIMAL(10,2) NULL,
            status VARCHAR(50) NULL,
            external_payment_id VARCHAR(255) NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            description TEXT NULL,
            metadata JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP NOT NULL,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payment_id (payment_id),
            INDEX idx_subscription_id (subscription_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at),
            INDEX idx_archived_at (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);

        // Create audit_subscriptions_archive table
        $sql = "CREATE TABLE IF NOT EXISTS audit_subscriptions_archive (
            id INT NOT NULL,
            subscription_id INT NULL,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            old_plan_id INT NULL,
            new_plan_id INT NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            old_period_start TIMESTAMP NULL,
            new_period_start TIMESTAMP NULL,
            old_period_end TIMESTAMP NULL,
            new_period_end TIMESTAMP NULL,
            description TEXT NULL,
            metadata JSON NULL,
            performed_by INT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP NOT NULL,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_subscription_id (subscription_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at),
            INDEX idx_archived_at (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);

        // Create audit_financial_archive table
        $sql = "CREATE TABLE IF NOT EXISTS audit_financial_archive (
            id INT NOT NULL,
            condominium_id INT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id INT NULL,
            action VARCHAR(100) NOT NULL,
            user_id INT NULL,
            amount DECIMAL(12,2) NULL,
            old_amount DECIMAL(12,2) NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            description TEXT NULL,
            changes JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP NOT NULL,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at),
            INDEX idx_archived_at (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);

        // Create audit_documents_archive table
        $sql = "CREATE TABLE IF NOT EXISTS audit_documents_archive (
            id INT NOT NULL,
            condominium_id INT NULL,
            document_id INT NULL,
            document_type VARCHAR(50) NULL,
            action VARCHAR(100) NOT NULL,
            user_id INT NULL,
            assembly_id INT NULL,
            receipt_id INT NULL,
            fee_id INT NULL,
            file_path VARCHAR(500) NULL,
            file_name VARCHAR(255) NULL,
            file_size INT NULL,
            description TEXT NULL,
            metadata JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP NOT NULL,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_condominium_id (condominium_id),
            INDEX idx_document_id (document_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at),
            INDEX idx_archived_at (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS notifications_archive");
        $this->db->exec("DROP TABLE IF EXISTS audit_logs_archive");
        $this->db->exec("DROP TABLE IF EXISTS audit_payments_archive");
        $this->db->exec("DROP TABLE IF EXISTS audit_subscriptions_archive");
        $this->db->exec("DROP TABLE IF EXISTS audit_financial_archive");
        $this->db->exec("DROP TABLE IF EXISTS audit_documents_archive");
    }
}
