<?php

namespace App\Services;

class AuditService
{
    protected $db;

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Log payment-related audit event
     */
    public function logPayment(array $data): void
    {
        if (!$this->db) {
            return;
        }

        $userId = \App\Middleware\AuthMiddleware::userId();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO audit_payments (
                payment_id, subscription_id, invoice_id, user_id, action,
                payment_method, amount, status, external_payment_id,
                old_status, new_status, description, metadata, ip_address, user_agent
            )
            VALUES (
                :payment_id, :subscription_id, :invoice_id, :user_id, :action,
                :payment_method, :amount, :status, :external_payment_id,
                :old_status, :new_status, :description, :metadata, :ip_address, :user_agent
            )
        ");

        $stmt->execute([
            ':payment_id' => $data['payment_id'] ?? null,
            ':subscription_id' => $data['subscription_id'] ?? null,
            ':invoice_id' => $data['invoice_id'] ?? null,
            ':user_id' => $data['user_id'] ?? $userId,
            ':action' => $data['action'],
            ':payment_method' => $data['payment_method'] ?? null,
            ':amount' => $data['amount'] ?? null,
            ':status' => $data['status'] ?? null,
            ':external_payment_id' => $data['external_payment_id'] ?? null,
            ':old_status' => $data['old_status'] ?? null,
            ':new_status' => $data['new_status'] ?? null,
            ':description' => $data['description'] ?? null,
            ':metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
    }

    /**
     * Log financial operation audit event
     */
    public function logFinancial(array $data): void
    {
        if (!$this->db) {
            return;
        }

        $userId = \App\Middleware\AuthMiddleware::userId();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO audit_financial (
                condominium_id, entity_type, entity_id, action, user_id,
                amount, old_amount, old_status, new_status, description,
                changes, ip_address, user_agent
            )
            VALUES (
                :condominium_id, :entity_type, :entity_id, :action, :user_id,
                :amount, :old_amount, :old_status, :new_status, :description,
                :changes, :ip_address, :user_agent
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':entity_type' => $data['entity_type'], // 'fee', 'fee_payment', 'expense', 'revenue', 'budget', 'financial_transaction'
            ':entity_id' => $data['entity_id'],
            ':action' => $data['action'],
            ':user_id' => $data['user_id'] ?? $userId,
            ':amount' => $data['amount'] ?? null,
            ':old_amount' => $data['old_amount'] ?? null,
            ':old_status' => $data['old_status'] ?? null,
            ':new_status' => $data['new_status'] ?? null,
            ':description' => $data['description'] ?? null,
            ':changes' => isset($data['changes']) ? json_encode($data['changes']) : null,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
    }

    /**
     * Log subscription-related audit event
     */
    public function logSubscription(array $data): void
    {
        if (!$this->db) {
            return;
        }

        $performedBy = \App\Middleware\AuthMiddleware::userId();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO audit_subscriptions (
                subscription_id, user_id, action, old_plan_id, new_plan_id,
                old_status, new_status, old_period_start, new_period_start,
                old_period_end, new_period_end, description, metadata,
                performed_by, ip_address, user_agent
            )
            VALUES (
                :subscription_id, :user_id, :action, :old_plan_id, :new_plan_id,
                :old_status, :new_status, :old_period_start, :new_period_start,
                :old_period_end, :new_period_end, :description, :metadata,
                :performed_by, :ip_address, :user_agent
            )
        ");

        $stmt->execute([
            ':subscription_id' => $data['subscription_id'],
            ':user_id' => $data['user_id'],
            ':action' => $data['action'],
            ':old_plan_id' => $data['old_plan_id'] ?? null,
            ':new_plan_id' => $data['new_plan_id'] ?? null,
            ':old_status' => $data['old_status'] ?? null,
            ':new_status' => $data['new_status'] ?? null,
            ':old_period_start' => $data['old_period_start'] ?? null,
            ':new_period_start' => $data['new_period_start'] ?? null,
            ':old_period_end' => $data['old_period_end'] ?? null,
            ':new_period_end' => $data['new_period_end'] ?? null,
            ':description' => $data['description'] ?? null,
            ':metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            ':performed_by' => $data['performed_by'] ?? $performedBy,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
    }

    /**
     * Log document generation audit event
     */
    public function logDocument(array $data): void
    {
        if (!$this->db) {
            return;
        }

        $userId = \App\Middleware\AuthMiddleware::userId();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO audit_documents (
                condominium_id, document_id, document_type, action, user_id,
                assembly_id, receipt_id, fee_id, file_path, file_name, file_size,
                description, metadata, ip_address, user_agent
            )
            VALUES (
                :condominium_id, :document_id, :document_type, :action, :user_id,
                :assembly_id, :receipt_id, :fee_id, :file_path, :file_name, :file_size,
                :description, :metadata, :ip_address, :user_agent
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'] ?? null,
            ':document_id' => $data['document_id'] ?? null,
            ':document_type' => $data['document_type'], // 'minutes', 'minutes_template', 'receipt', etc.
            ':action' => $data['action'], // 'generate', 'approve', 'regenerate', etc.
            ':user_id' => $data['user_id'] ?? $userId,
            ':assembly_id' => $data['assembly_id'] ?? null,
            ':receipt_id' => $data['receipt_id'] ?? null,
            ':fee_id' => $data['fee_id'] ?? null,
            ':file_path' => $data['file_path'] ?? null,
            ':file_name' => $data['file_name'] ?? null,
            ':file_size' => $data['file_size'] ?? null,
            ':description' => $data['description'] ?? null,
            ':metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
    }

    /**
     * Log general audit event (uses main audit_logs table)
     */
    public function log(array $data): void
    {
        if (!$this->db) {
            return;
        }

        $userId = \App\Middleware\AuthMiddleware::userId();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (
                user_id, action, model, model_id, description, ip_address, user_agent
            )
            VALUES (
                :user_id, :action, :model, :model_id, :description, :ip_address, :user_agent
            )
        ");

        $stmt->execute([
            ':user_id' => $data['user_id'] ?? $userId,
            ':action' => $data['action'],
            ':model' => $data['model'] ?? null,
            ':model_id' => $data['model_id'] ?? null,
            ':description' => $data['description'] ?? null,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
    }
}
