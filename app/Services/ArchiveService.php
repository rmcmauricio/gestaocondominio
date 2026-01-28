<?php

namespace App\Services;

class ArchiveService
{
    protected $db;

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Archive old notifications (read notifications older than X days)
     * 
     * @param int $daysOld Number of days old (default: 90)
     * @param bool $dryRun If true, only show what would be archived without actually archiving
     * @return array Statistics about archived notifications
     */
    public function archiveNotifications(int $daysOld = 90, bool $dryRun = false): array
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));
        
        // Get notifications to archive (only read notifications)
        $stmt = $this->db->prepare("
            SELECT id, user_id, condominium_id, type, title, message, link, 
                   is_read, read_at, created_at
            FROM notifications
            WHERE is_read = TRUE
            AND read_at IS NOT NULL
            AND read_at <= :cutoff_date
            AND id NOT IN (SELECT id FROM notifications_archive)
            ORDER BY read_at ASC
            LIMIT 1000
        ");
        
        $stmt->execute([':cutoff_date' => $cutoffDate]);
        $notifications = $stmt->fetchAll() ?: [];

        if (empty($notifications)) {
            return [
                'archived' => 0,
                'skipped' => 0,
                'errors' => []
            ];
        }

        $archived = 0;
        $skipped = 0;
        $errors = [];

        if ($dryRun) {
            return [
                'archived' => count($notifications),
                'skipped' => 0,
                'errors' => [],
                'dry_run' => true
            ];
        }

        try {
            $this->db->beginTransaction();

            foreach ($notifications as $notification) {
                try {
                    // Insert into archive table
                    $insertStmt = $this->db->prepare("
                        INSERT INTO notifications_archive (
                            id, user_id, condominium_id, type, title, message, link,
                            is_read, read_at, created_at, archived_at
                        )
                        VALUES (
                            :id, :user_id, :condominium_id, :type, :title, :message, :link,
                            :is_read, :read_at, :created_at, NOW()
                        )
                    ");

                    $insertStmt->execute([
                        ':id' => $notification['id'],
                        ':user_id' => $notification['user_id'],
                        ':condominium_id' => $notification['condominium_id'],
                        ':type' => $notification['type'],
                        ':title' => $notification['title'],
                        ':message' => $notification['message'],
                        ':link' => $notification['link'],
                        ':is_read' => $notification['is_read'] ? 1 : 0,
                        ':read_at' => $notification['read_at'],
                        ':created_at' => $notification['created_at']
                    ]);

                    // Delete from original table
                    $deleteStmt = $this->db->prepare("DELETE FROM notifications WHERE id = :id");
                    $deleteStmt->execute([':id' => $notification['id']]);

                    $archived++;
                } catch (\Exception $e) {
                    $errors[] = "Error archiving notification ID {$notification['id']}: " . $e->getMessage();
                    $skipped++;
                }
            }

            $this->db->commit();
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'archived' => $archived,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Archive old audit logs (older than X years)
     * 
     * @param int $yearsOld Number of years old (default: 1)
     * @param bool $dryRun If true, only show what would be archived without actually archiving
     * @return array Statistics about archived logs
     */
    public function archiveAuditLogs(int $yearsOld = 1, bool $dryRun = false): array
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$yearsOld} years"));
        
        $stats = [
            'audit_logs' => 0,
            'audit_payments' => 0,
            'audit_subscriptions' => 0,
            'audit_financial' => 0,
            'audit_documents' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        // Archive known specialized audit tables
        $stats['audit_logs'] = $this->archiveTable(
            'audit_logs',
            'audit_logs_archive',
            $cutoffDate,
            $dryRun
        );

        $stats['audit_payments'] = $this->archiveTable(
            'audit_payments',
            'audit_payments_archive',
            $cutoffDate,
            $dryRun
        );

        $stats['audit_subscriptions'] = $this->archiveTable(
            'audit_subscriptions',
            'audit_subscriptions_archive',
            $cutoffDate,
            $dryRun
        );

        $stats['audit_financial'] = $this->archiveTable(
            'audit_financial',
            'audit_financial_archive',
            $cutoffDate,
            $dryRun
        );

        $stats['audit_documents'] = $this->archiveTable(
            'audit_documents',
            'audit_documents_archive',
            $cutoffDate,
            $dryRun
        );

        // Dynamically archive all other audit_* tables (separated audit tables)
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'audit_%'");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            // Exclude specialized tables that are already handled above
            $excludedTables = [
                'audit_logs',
                'audit_payments',
                'audit_subscriptions',
                'audit_financial',
                'audit_documents'
            ];
            
            foreach ($tables as $table) {
                if (!in_array($table, $excludedTables)) {
                    // Check if archive table exists, create if not
                    $archiveTable = $table . '_archive';
                    $this->ensureArchiveTableExists($table, $archiveTable);
                    
                    // Archive the table
                    try {
                        $archived = $this->archiveTable($table, $archiveTable, $cutoffDate, $dryRun);
                        // Store stats with table name as key
                        $stats[$table] = $archived;
                    } catch (\Exception $e) {
                        $stats['errors'][] = "Error archiving {$table}: " . $e->getMessage();
                        $stats['skipped']++;
                    }
                }
            }
        } catch (\Exception $e) {
            $stats['errors'][] = "Error detecting separated audit tables: " . $e->getMessage();
        }

        return $stats;
    }

    /**
     * Ensure archive table exists, create it if it doesn't
     * 
     * @param string $sourceTable Source table name
     * @param string $archiveTable Archive table name
     */
    protected function ensureArchiveTableExists(string $sourceTable, string $archiveTable): void
    {
        // Check if archive table exists
        $stmt = $this->db->query("SHOW TABLES LIKE '{$archiveTable}'");
        if ($stmt->rowCount() > 0) {
            return; // Archive table already exists
        }

        // Get source table structure
        $columnsStmt = $this->db->query("SHOW COLUMNS FROM `{$sourceTable}`");
        $columns = $columnsStmt->fetchAll();
        
        if (empty($columns)) {
            throw new \Exception("Source table {$sourceTable} does not exist or has no columns");
        }

        // Build CREATE TABLE statement
        $columnDefinitions = [];
        foreach ($columns as $column) {
            $field = $column['Field'];
            $type = $column['Type'];
            $null = $column['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
            $default = $column['Default'] !== null ? "DEFAULT '{$column['Default']}'" : '';
            $extra = $column['Extra'] ?? '';
            
            // Remove AUTO_INCREMENT from id column for archive table
            if ($field === 'id' && strpos($extra, 'auto_increment') !== false) {
                $extra = str_replace('auto_increment', '', $extra);
                $extra = trim($extra);
            }
            
            $columnDefinitions[] = "`{$field}` {$type} {$null} {$default} {$extra}";
        }
        
        // Add archived_at column
        $columnDefinitions[] = "`archived_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
        
        $createSql = "CREATE TABLE IF NOT EXISTS `{$archiveTable}` (\n" .
                     implode(",\n", $columnDefinitions) . "\n" .
                     ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($createSql);
        
        // Create indexes (copy from source table)
        try {
            $indexesStmt = $this->db->query("SHOW INDEXES FROM `{$sourceTable}`");
            $indexes = $indexesStmt->fetchAll();
            
            $indexGroups = [];
            foreach ($indexes as $index) {
                $keyName = $index['Key_name'];
                if ($keyName === 'PRIMARY') {
                    continue; // Skip primary key, already defined
                }
                
                if (!isset($indexGroups[$keyName])) {
                    $indexGroups[$keyName] = [
                        'unique' => $index['Non_unique'] == 0,
                        'columns' => []
                    ];
                }
                
                $indexGroups[$keyName]['columns'][] = $index['Column_name'];
            }
            
            foreach ($indexGroups as $keyName => $indexInfo) {
                $columns = implode(', ', $indexGroups[$keyName]['columns']);
                $unique = $indexInfo['unique'] ? 'UNIQUE ' : '';
                $this->db->exec("CREATE {$unique}INDEX `{$keyName}` ON `{$archiveTable}` ({$columns})");
            }
        } catch (\Exception $e) {
            // If index creation fails, continue (not critical)
            error_log("Warning: Could not copy indexes for {$archiveTable}: " . $e->getMessage());
        }
        
        // Add archived_at index
        try {
            $this->db->exec("CREATE INDEX idx_archived_at ON `{$archiveTable}` (archived_at)");
        } catch (\Exception $e) {
            // Index might already exist, ignore
        }
    }

    /**
     * Archive records from a table to its archive table
     * 
     * @param string $sourceTable Source table name
     * @param string $archiveTable Archive table name
     * @param string $cutoffDate Cutoff date (records older than this will be archived)
     * @param bool $dryRun If true, only count without archiving
     * @return int Number of records archived
     */
    protected function archiveTable(string $sourceTable, string $archiveTable, string $cutoffDate, bool $dryRun = false): int
    {
        // Whitelist of known allowed table names to prevent SQL injection
        $knownTables = [
            'audit_logs' => 'audit_logs_archive',
            'audit_payments' => 'audit_payments_archive',
            'audit_subscriptions' => 'audit_subscriptions_archive',
            'audit_financial' => 'audit_financial_archive',
            'audit_documents' => 'audit_documents_archive',
        ];
        
        // For known tables, validate against whitelist
        if (isset($knownTables[$sourceTable])) {
            if ($knownTables[$sourceTable] !== $archiveTable) {
                throw new \InvalidArgumentException("Invalid archive table name for {$sourceTable}: expected {$knownTables[$sourceTable]}, got {$archiveTable}");
            }
        } else {
            // For dynamic tables (separated audit tables), validate format
            // Archive table should be source table + '_archive'
            if ($archiveTable !== $sourceTable . '_archive') {
                throw new \InvalidArgumentException("Archive table name must be source table name + '_archive'");
            }
            
            // Verify source table exists and starts with 'audit_'
            if (strpos($sourceTable, 'audit_') !== 0) {
                throw new \InvalidArgumentException("Source table must start with 'audit_'");
            }
        }
        
        // Sanitize table names - only allow alphanumeric and underscore
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $sourceTable) || !preg_match('/^[a-zA-Z0-9_]+$/', $archiveTable)) {
            throw new \InvalidArgumentException("Invalid table name format");
        }
        
        // Use prepared statement with backticks for table names (PDO doesn't support table name parameters)
        // Since we've validated against whitelist, this is safe
        $columnsStmt = $this->db->query("SHOW COLUMNS FROM `{$sourceTable}`");
        $columns = $columnsStmt->fetchAll();
        
        $columnNames = array_column($columns, 'Field');
        $columnList = implode(', ', $columnNames);
        $placeholders = ':' . implode(', :', $columnNames);

        // Get records to archive
        // Table names are validated against whitelist above, so safe to use
        $stmt = $this->db->prepare("
            SELECT {$columnList}
            FROM `{$sourceTable}`
            WHERE created_at <= :cutoff_date
            AND id NOT IN (SELECT id FROM `{$archiveTable}`)
            ORDER BY created_at ASC
            LIMIT 1000
        ");
        
        $stmt->execute([':cutoff_date' => $cutoffDate]);
        $records = $stmt->fetchAll() ?: [];

        if (empty($records)) {
            return 0;
        }

        if ($dryRun) {
            return count($records);
        }

        $archived = 0;

        try {
            $this->db->beginTransaction();

            foreach ($records as $record) {
                try {
                    // Build insert statement with all columns + archived_at
                    // Table names are validated against whitelist above, so safe to use
                    $archiveColumns = $columnList . ', archived_at';
                    $archivePlaceholders = $placeholders . ', NOW()';
                    
                    $insertStmt = $this->db->prepare("
                        INSERT INTO `{$archiveTable}` ({$archiveColumns})
                        VALUES ({$archivePlaceholders})
                    ");

                    $params = [];
                    foreach ($columnNames as $col) {
                        $params[':' . $col] = $record[$col];
                    }
                    
                    $insertStmt->execute($params);

                    // Delete from original table
                    // Table name is validated against whitelist above, so safe to use
                    $deleteStmt = $this->db->prepare("DELETE FROM `{$sourceTable}` WHERE id = :id");
                    $deleteStmt->execute([':id' => $record['id']]);

                    $archived++;
                } catch (\Exception $e) {
                    error_log("Error archiving record ID {$record['id']} from {$sourceTable}: " . $e->getMessage());
                }
            }

            $this->db->commit();
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $archived;
    }

    /**
     * Get archive statistics
     * 
     * @return array Statistics about archived data
     */
    public function getArchiveStatistics(): array
    {
        if (!$this->db) {
            return [];
        }

        $stats = [];

        // Count archived notifications
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM `notifications_archive`");
            $stats['notifications'] = (int)$stmt->fetch()['count'];
        } catch (\Exception $e) {
            $stats['notifications'] = 0;
        }

        // Count archived audit logs (known tables)
        $knownAuditArchives = [
            'audit_logs',
            'audit_payments',
            'audit_subscriptions',
            'audit_financial',
            'audit_documents'
        ];
        
        foreach ($knownAuditArchives as $table) {
            try {
                $archiveTable = $table . '_archive';
                $stmt = $this->db->query("SELECT COUNT(*) as count FROM `{$archiveTable}`");
                $stats[$table] = (int)$stmt->fetch()['count'];
            } catch (\Exception $e) {
                $stats[$table] = 0;
            }
        }

        // Dynamically count all other audit archive tables
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'audit_%_archive'");
            $archiveTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            foreach ($archiveTables as $archiveTable) {
                // Skip known tables already counted
                $sourceTable = str_replace('_archive', '', $archiveTable);
                if (in_array($sourceTable, $knownAuditArchives)) {
                    continue;
                }
                
                try {
                    $stmt = $this->db->query("SELECT COUNT(*) as count FROM `{$archiveTable}`");
                    $count = (int)$stmt->fetch()['count'];
                    if ($count > 0) {
                        $stats[$sourceTable] = $count;
                    }
                } catch (\Exception $e) {
                    // Skip if error
                }
            }
        } catch (\Exception $e) {
            // If query fails, continue without dynamic tables
        }

        // Get oldest archived date
        try {
            $stmt = $this->db->query("SELECT MIN(archived_at) as oldest FROM `notifications_archive`");
            $oldest = $stmt->fetch()['oldest'];
            $stats['oldest_notification_archive'] = $oldest ?: null;
        } catch (\Exception $e) {
            $stats['oldest_notification_archive'] = null;
        }

        try {
            $stmt = $this->db->query("SELECT MIN(archived_at) as oldest FROM `audit_logs_archive`");
            $oldest = $stmt->fetch()['oldest'];
            $stats['oldest_audit_archive'] = $oldest ?: null;
        } catch (\Exception $e) {
            $stats['oldest_audit_archive'] = null;
        }

        return $stats;
    }
}
