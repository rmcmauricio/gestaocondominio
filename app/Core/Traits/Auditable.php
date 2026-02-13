<?php

namespace App\Core\Traits;

use App\Config\AuditConfig;
use App\Core\AuditManager;

trait Auditable
{
    /**
     * Log audit for CREATE operation
     */
    protected function auditCreate(int $id, array $data, ?string $tableName = null): void
    {
        if (AuditManager::isDisabled() || !$this->shouldAudit($tableName)) {
            return;
        }

        $tableName = $tableName ?? $this->getTableName();
        $filteredData = $this->filterSensitiveFields($tableName, $data);

        $this->logAudit([
            'user_id' => $this->getCurrentUserId(),
            'action' => 'insert',
            'model' => $tableName,
            'model_id' => $id,
            'old_data' => null,
            'new_data' => json_encode($filteredData),
            'table_name' => $tableName,
            'operation' => 'insert',
            'description' => $this->generateDescription('created', $tableName, $id, $filteredData)
        ]);
    }

    /**
     * Log audit for UPDATE operation
     */
    protected function auditUpdate(int $id, array $newData, ?array $oldData = null, ?string $tableName = null): void
    {
        if (AuditManager::isDisabled() || !$this->shouldAudit($tableName)) {
            return;
        }

        $tableName = $tableName ?? $this->getTableName();

        // If old data not provided, fetch it
        if ($oldData === null && $this->db) {
            $oldData = $this->getOldData($tableName, $id);
        }

        $filteredOldData = $oldData ? $this->filterSensitiveFields($tableName, $oldData) : null;
        $filteredNewData = $this->filterSensitiveFields($tableName, $newData);

        $this->logAudit([
            'user_id' => $this->getCurrentUserId(),
            'action' => 'update',
            'model' => $tableName,
            'model_id' => $id,
            'old_data' => $filteredOldData ? json_encode($filteredOldData) : null,
            'new_data' => json_encode($filteredNewData),
            'table_name' => $tableName,
            'operation' => 'update',
            'description' => $this->generateDescription('updated', $tableName, $id, $filteredNewData, $filteredOldData)
        ]);
    }

    /**
     * Log audit for DELETE operation
     */
    protected function auditDelete(int $id, ?array $oldData = null, ?string $tableName = null): void
    {
        if (AuditManager::isDisabled() || !$this->shouldAudit($tableName)) {
            return;
        }

        $tableName = $tableName ?? $this->getTableName();

        // If old data not provided, fetch it before deletion
        if ($oldData === null && $this->db) {
            $oldData = $this->getOldData($tableName, $id);
        }

        $filteredOldData = $oldData ? $this->filterSensitiveFields($tableName, $oldData) : null;

        $this->logAudit([
            'user_id' => $this->getCurrentUserId(),
            'action' => 'delete',
            'model' => $tableName,
            'model_id' => $id,
            'old_data' => $filteredOldData ? json_encode($filteredOldData) : null,
            'new_data' => null,
            'table_name' => $tableName,
            'operation' => 'delete',
            'description' => $this->generateDescription('deleted', $tableName, $id, null, $filteredOldData)
        ]);
    }

    /**
     * Log custom audit event
     */
    protected function auditCustom(string $action, string $description, ?array $data = null, ?string $tableName = null, ?int $modelId = null): void
    {
        if (AuditManager::isDisabled()) {
            return;
        }

        $tableName = $tableName ?? $this->getTableName();

        $this->logAudit([
            'user_id' => $this->getCurrentUserId(),
            'action' => $action,
            'model' => $tableName,
            'model_id' => $modelId,
            'old_data' => null,
            'new_data' => $data ? json_encode($this->filterSensitiveFields($tableName, $data)) : null,
            'table_name' => $tableName,
            'operation' => $action,
            'description' => $description
        ]);
    }

    /**
     * Check if table should be audited
     */
    protected function shouldAudit(?string $tableName = null): bool
    {
        $tableName = $tableName ?? $this->getTableName();
        return AuditConfig::isAudited($tableName);
    }

    /**
     * Get table name from model
     */
    protected function getTableName(): string
    {
        return $this->table ?? strtolower((new \ReflectionClass($this))->getShortName() . 's');
    }

    /**
     * Get current user ID
     */
    protected function getCurrentUserId(): ?int
    {
        try {
            return \App\Middleware\AuthMiddleware::userId();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Filter sensitive fields from data
     */
    protected function filterSensitiveFields(string $tableName, array $data): array
    {
        $sensitiveFields = AuditConfig::getSensitiveFields($tableName);

        $filtered = $data;
        foreach ($sensitiveFields as $field) {
            if (isset($filtered[$field])) {
                $filtered[$field] = '[REDACTED]';
            }
        }

        return $filtered;
    }

    /**
     * Get old data before update/delete
     */
    protected function getOldData(string $tableName, int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM `{$tableName}` WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate description for audit log
     */
    protected function generateDescription(string $action, string $tableName, int $id, ?array $newData = null, ?array $oldData = null): string
    {
        $actionMap = [
            'created' => 'criado',
            'updated' => 'atualizado',
            'deleted' => 'excluído'
        ];

        $actionPt = $actionMap[$action] ?? $action;
        $description = ucfirst($tableName) . " #{$id} {$actionPt}";

        // Add relevant field info if available
        if ($newData) {
            $keyFields = $this->getKeyFields($tableName);
            $fields = [];
            foreach ($keyFields as $field) {
                if (isset($newData[$field])) {
                    $fields[] = "{$field}: " . (is_array($newData[$field]) ? json_encode($newData[$field]) : $newData[$field]);
                }
            }
            if (!empty($fields)) {
                $description .= " (" . implode(", ", array_slice($fields, 0, 3)) . ")";
            }
        }

        return $description;
    }

    /**
     * Get key fields for a table (for description generation)
     */
    protected function getKeyFields(string $tableName): array
    {
        $keyFieldsMap = [
            'users' => ['email', 'name', 'role'],
            'condominiums' => ['name'],
            'fractions' => ['identifier'],
            'fees' => ['reference', 'amount'],
            'documents' => ['title', 'file_name'],
            'folders' => ['name', 'path'],
        ];

        return $keyFieldsMap[$tableName] ?? ['id'];
    }

    /**
     * Get the audit table name for a given table
     * Returns the specific audit table if it exists, otherwise falls back to audit_logs
     */
    protected function getAuditTableName(string $tableName): string
    {
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
            $stmt = $this->db->query("SHOW TABLES LIKE '{$dedicatedAuditTable}'");
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
     * Log audit to database
     */
    protected function logAudit(array $data): void
    {
        if (!$this->db) {
            return;
        }

        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $tableName = $data['table_name'] ?? $this->getTableName();

            // Determine which audit table to use
            $auditTableName = $this->getAuditTableName($tableName);

            // Check if the audit table has the new fields (old_data, new_data, etc.)
            $stmt = $this->db->query("SHOW COLUMNS FROM {$auditTableName} LIKE 'old_data'");
            $hasNewFields = $stmt->rowCount() > 0;

            if ($hasNewFields) {
                // Use new format with old_data, new_data, table_name, operation
                $stmt = $this->db->prepare("
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
                    ':user_id' => $data['user_id'],
                    ':action' => $data['action'],
                    ':model' => $data['model'] ?? null,
                    ':model_id' => $data['model_id'] ?? null,
                    ':description' => $data['description'] ?? null,
                    ':ip_address' => $ipAddress,
                    ':user_agent' => $userAgent,
                    ':old_data' => isset($data['old_data']) ? (is_string($data['old_data']) ? $data['old_data'] : json_encode($data['old_data'])) : null,
                    ':new_data' => isset($data['new_data']) ? (is_string($data['new_data']) ? $data['new_data'] : json_encode($data['new_data'])) : null,
                    ':table_name' => $tableName,
                    ':operation' => $data['operation'] ?? null
                ]);
            } else {
                // Fallback to old format for compatibility (only for audit_logs)
                if ($auditTableName === 'audit_logs') {
                    $stmt = $this->db->prepare("
                        INSERT INTO audit_logs (
                            user_id, action, model, model_id, description, ip_address, user_agent
                        )
                        VALUES (
                            :user_id, :action, :model, :model_id, :description, :ip_address, :user_agent
                        )
                    ");

                    $stmt->execute([
                        ':user_id' => $data['user_id'],
                        ':action' => $data['action'],
                        ':model' => $data['model'] ?? null,
                        ':model_id' => $data['model_id'] ?? null,
                        ':description' => $data['description'] ?? null,
                        ':ip_address' => $ipAddress,
                        ':user_agent' => $userAgent
                    ]);
                } else {
                    // Fallback for audit_payments (and other specialized tables) without new fields
                    \App\Core\AuditManager::insertLegacyAudit($this->db, $auditTableName, $data, $ipAddress, $userAgent, $tableName);
                }
            }
        } catch (\Exception $e) {
            // Log error but don't break the flow
            error_log("Audit log error: " . $e->getMessage());
        }
    }
}
