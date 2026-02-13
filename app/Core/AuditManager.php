<?php

namespace App\Core;

/**
 * Global audit manager to control auditing state
 */
class AuditManager
{
    private static ?AuditManager $instance = null;
    private bool $auditingDisabled = false;

    private function __construct()
    {
        // Private constructor for singleton
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Disable auditing globally
     */
    public function disableAuditing(): void
    {
        $this->auditingDisabled = true;
    }

    /**
     * Enable auditing globally
     */
    public function enableAuditing(): void
    {
        $this->auditingDisabled = false;
    }

    /**
     * Check if auditing is currently disabled
     */
    public function isAuditingDisabled(): bool
    {
        return $this->auditingDisabled;
    }

    /**
     * Static convenience methods
     */
    public static function disable(): void
    {
        self::getInstance()->disableAuditing();
    }

    public static function enable(): void
    {
        self::getInstance()->enableAuditing();
    }

    public static function isDisabled(): bool
    {
        return self::getInstance()->isAuditingDisabled();
    }

    /**
     * Get the audit table name for a given table
     */
    protected static function getAuditTableName(string $tableName): string
    {
        global $db;
        if (!$db) {
            return 'audit_logs';
        }

        // Tables that have specialized audit tables (keep using their specific tables)
        $specializedTables = [
            'payments' => 'audit_payments',
            'financial' => 'audit_financial',
            'subscriptions' => 'audit_subscriptions',
            'documents' => 'audit_documents',
        ];

        // Check if this table has a specialized audit table
        foreach ($specializedTables as $key => $auditTable) {
            if (strpos($tableName, $key) !== false) {
                return $auditTable;
            }
        }

        // Check if a dedicated audit table exists for this table
        $dedicatedAuditTable = 'audit_' . $tableName;
        try {
            $stmt = $db->query("SHOW TABLES LIKE '{$dedicatedAuditTable}'");
            if ($stmt->rowCount() > 0) {
                return $dedicatedAuditTable;
            }
        } catch (\Exception $e) {
            // If check fails, fall back to audit_logs
        }

        // Fallback to general audit_logs table
        return 'audit_logs';
    }

    /**
     * Log audit manually (for cases where models are not used, e.g., direct SQL deletes)
     */
    public static function logAudit(array $data): void
    {
        if (self::isDisabled()) {
            return;
        }

        global $db;
        if (!$db) {
            return;
        }

        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $tableName = $data['table_name'] ?? null;
            
            if (!$tableName) {
                // Try to get from model
                $tableName = $data['model'] ?? 'unknown';
            }
            
            // Determine which audit table to use
            $auditTableName = self::getAuditTableName($tableName);

            // Check if new fields exist in audit table
            $stmt = $db->query("SHOW COLUMNS FROM {$auditTableName} LIKE 'old_data'");
            $hasNewFields = $stmt->rowCount() > 0;

            if ($hasNewFields) {
                // Use new format with old_data, new_data, table_name, operation
                $stmt = $db->prepare("
                    INSERT INTO {$auditTableName} (
                        user_id, action, model, model_id, description, 
                        ip_address, user_agent, old_data, new_data, table_name, operation
                    )
                    VALUES (
                        :user_id, :action, :model, :model_id, :description,
                        :ip_address, :user_agent, :old_data, :new_data, :table_name, :operation
                    )
                ");

                $stmt->execute([
                    ':user_id' => $data['user_id'] ?? null,
                    ':action' => $data['action'] ?? 'delete',
                    ':model' => $data['model'] ?? null,
                    ':model_id' => $data['model_id'] ?? null,
                    ':description' => $data['description'] ?? null,
                    ':ip_address' => $ipAddress,
                    ':user_agent' => $userAgent,
                    ':old_data' => isset($data['old_data']) ? (is_string($data['old_data']) ? $data['old_data'] : json_encode($data['old_data'])) : null,
                    ':new_data' => isset($data['new_data']) ? (is_string($data['new_data']) ? $data['new_data'] : json_encode($data['new_data'])) : null,
                    ':table_name' => $tableName,
                    ':operation' => $data['operation'] ?? 'delete'
                ]);
            } else {
                // Fallback to old format for compatibility (only for audit_logs)
                if ($auditTableName === 'audit_logs') {
                    $stmt = $db->prepare("
                        INSERT INTO audit_logs (
                            user_id, action, model, model_id, description, ip_address, user_agent
                        )
                        VALUES (
                            :user_id, :action, :model, :model_id, :description, :ip_address, :user_agent
                        )
                    ");

                    $stmt->execute([
                        ':user_id' => $data['user_id'] ?? null,
                        ':action' => $data['action'] ?? 'delete',
                        ':model' => $data['model'] ?? null,
                        ':model_id' => $data['model_id'] ?? null,
                        ':description' => $data['description'] ?? null,
                        ':ip_address' => $ipAddress,
                        ':user_agent' => $userAgent
                    ]);
                } else {
                    // Fallback for audit_payments (and other specialized tables) without new fields
                    self::insertLegacyAudit($db, $auditTableName, $data, $ipAddress, $userAgent, $tableName);
                }
            }
        } catch (\Exception $e) {
            // Log error but don't break the flow
            error_log("Audit log error: " . $e->getMessage());
        }
    }

    /**
     * Insert into specialized audit tables that don't have old_data/new_data/table_name/operation.
     * Uses only columns that exist (e.g. audit_payments: user_id, action, description, ip_address, user_agent).
     */
    public static function insertLegacyAudit(\PDO $db, string $auditTableName, array $data, ?string $ipAddress, ?string $userAgent, string $tableName): void
    {
        if ($auditTableName === 'audit_payments') {
            $paymentId = ($tableName === 'payments' && isset($data['model_id'])) ? $data['model_id'] : null;
            $stmt = $db->prepare("
                INSERT INTO audit_payments (
                    user_id, action, description, ip_address, user_agent, payment_id
                )
                VALUES (
                    :user_id, :action, :description, :ip_address, :user_agent, :payment_id
                )
            ");
            $stmt->execute([
                ':user_id' => $data['user_id'] ?? null,
                ':action' => $data['action'] ?? 'update',
                ':description' => $data['description'] ?? null,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
                ':payment_id' => $paymentId
            ]);
            return;
        }
        // Other specialized tables: log to avoid silent skip (run migration to add new fields)
        error_log("Audit table {$auditTableName} does not have new fields and is not audit_logs. Skipping audit log. Run migration to add old_data/new_data/table_name/operation.");
    }
}
