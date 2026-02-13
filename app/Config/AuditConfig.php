<?php

namespace App\Config;

class AuditConfig
{
    /**
     * List of tables that should be audited
     */
    protected static array $auditedTables = [
        'users',
        'condominiums',
        'fractions',
        'fees',
        'fee_payments',
        'revenues',
        'budgets',
        'budget_items',
        'expense_categories',
        'revenue_categories',
        'financial_transactions',
        'occurrences',
        'assemblies',
        'reservations',
        'documents',
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
        'payments',
        'condominium_users',
        'fraction_accounts',
        'fraction_account_movements',
        'votes',
        'vote_options',
        'vote_topics',
        'standalone_votes',
        'standalone_vote_responses',
    ];

    /**
     * Sensitive fields that should be redacted from audit logs
     * Format: 'table_name' => ['field1', 'field2', ...]
     */
    protected static array $sensitiveFields = [
        'users' => ['password', 'two_factor_secret'],
        'password_resets' => ['token'],
        'sessions' => ['data'],
    ];

    /**
     * Check if a table should be audited
     */
    public static function isAudited(string $tableName): bool
    {
        return in_array($tableName, self::$auditedTables, true);
    }

    /**
     * Get sensitive fields for a table
     */
    public static function getSensitiveFields(string $tableName): array
    {
        return self::$sensitiveFields[$tableName] ?? [];
    }

    /**
     * Add a table to the audited list
     */
    public static function addAuditedTable(string $tableName): void
    {
        if (!in_array($tableName, self::$auditedTables, true)) {
            self::$auditedTables[] = $tableName;
        }
    }

    /**
     * Remove a table from the audited list
     */
    public static function removeAuditedTable(string $tableName): void
    {
        $key = array_search($tableName, self::$auditedTables, true);
        if ($key !== false) {
            unset(self::$auditedTables[$key]);
            self::$auditedTables = array_values(self::$auditedTables);
        }
    }

    /**
     * Add sensitive fields for a table
     */
    public static function addSensitiveFields(string $tableName, array $fields): void
    {
        if (!isset(self::$sensitiveFields[$tableName])) {
            self::$sensitiveFields[$tableName] = [];
        }
        self::$sensitiveFields[$tableName] = array_unique(
            array_merge(self::$sensitiveFields[$tableName], $fields)
        );
    }
}
