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

        // Archive audit_logs
        $stats['audit_logs'] = $this->archiveTable(
            'audit_logs',
            'audit_logs_archive',
            $cutoffDate,
            $dryRun
        );

        // Archive audit_payments
        $stats['audit_payments'] = $this->archiveTable(
            'audit_payments',
            'audit_payments_archive',
            $cutoffDate,
            $dryRun
        );

        // Archive audit_subscriptions
        $stats['audit_subscriptions'] = $this->archiveTable(
            'audit_subscriptions',
            'audit_subscriptions_archive',
            $cutoffDate,
            $dryRun
        );

        // Archive audit_financial
        $stats['audit_financial'] = $this->archiveTable(
            'audit_financial',
            'audit_financial_archive',
            $cutoffDate,
            $dryRun
        );

        // Archive audit_documents
        $stats['audit_documents'] = $this->archiveTable(
            'audit_documents',
            'audit_documents_archive',
            $cutoffDate,
            $dryRun
        );

        return $stats;
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
        // Get all columns from source table
        $columnsStmt = $this->db->query("SHOW COLUMNS FROM {$sourceTable}");
        $columns = $columnsStmt->fetchAll();
        
        $columnNames = array_column($columns, 'Field');
        $columnList = implode(', ', $columnNames);
        $placeholders = ':' . implode(', :', $columnNames);

        // Get records to archive
        $stmt = $this->db->prepare("
            SELECT {$columnList}
            FROM {$sourceTable}
            WHERE created_at <= :cutoff_date
            AND id NOT IN (SELECT id FROM {$archiveTable})
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
                    $archiveColumns = $columnList . ', archived_at';
                    $archivePlaceholders = $placeholders . ', NOW()';
                    
                    $insertStmt = $this->db->prepare("
                        INSERT INTO {$archiveTable} ({$archiveColumns})
                        VALUES ({$archivePlaceholders})
                    ");

                    $params = [];
                    foreach ($columnNames as $col) {
                        $params[':' . $col] = $record[$col];
                    }
                    
                    $insertStmt->execute($params);

                    // Delete from original table
                    $deleteStmt = $this->db->prepare("DELETE FROM {$sourceTable} WHERE id = :id");
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
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM notifications_archive");
        $stats['notifications'] = (int)$stmt->fetch()['count'];

        // Count archived audit logs
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM audit_logs_archive");
        $stats['audit_logs'] = (int)$stmt->fetch()['count'];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM audit_payments_archive");
        $stats['audit_payments'] = (int)$stmt->fetch()['count'];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM audit_subscriptions_archive");
        $stats['audit_subscriptions'] = (int)$stmt->fetch()['count'];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM audit_financial_archive");
        $stats['audit_financial'] = (int)$stmt->fetch()['count'];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM audit_documents_archive");
        $stats['audit_documents'] = (int)$stmt->fetch()['count'];

        // Get oldest archived date
        $stmt = $this->db->query("SELECT MIN(archived_at) as oldest FROM notifications_archive");
        $stats['oldest_notification_archive'] = $stmt->fetch()['oldest'];

        $stmt = $this->db->query("SELECT MIN(archived_at) as oldest FROM audit_logs_archive");
        $stats['oldest_audit_archive'] = $stmt->fetch()['oldest'];

        return $stats;
    }
}
