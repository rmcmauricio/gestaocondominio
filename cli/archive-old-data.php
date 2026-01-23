<?php
/**
 * Archive Old Data CLI
 * 
 * Moves old notifications and audit logs to archive tables instead of deleting them.
 * Also cleans up expired tokens and invitations.
 * 
 * Usage: php cli/archive-old-data.php [--days-notifications=X] [--years-audit=X] [--dry-run]
 * 
 * Options:
 *   --days-notifications=X    Days old for notifications (default: 90)
 *   --years-audit=X           Years old for audit logs (default: 1)
 *   --dry-run                 Show what would be archived without actually archiving
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../database.php';

use App\Services\ArchiveService;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

// Parse command line arguments
$daysNotifications = 90;
$yearsAudit = 1;
$dryRun = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--days-notifications=') === 0) {
        $daysNotifications = (int)substr($arg, strlen('--days-notifications='));
    } elseif (strpos($arg, '--years-audit=') === 0) {
        $yearsAudit = (int)substr($arg, strlen('--years-audit='));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

echo "========================================\n";
echo "Archive Old Data System\n";
echo "========================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no data will be archived)" : "LIVE") . "\n";
echo "Notifications older than: {$daysNotifications} days\n";
echo "Audit logs older than: {$yearsAudit} year(s)\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }
    
    $archiveService = new ArchiveService();
    
    // Archive notifications
    echo "Archiving notifications...\n";
    $notificationStats = $archiveService->archiveNotifications($daysNotifications, $dryRun);
    echo "  Archived: {$notificationStats['archived']}\n";
    if ($notificationStats['skipped'] > 0) {
        echo "  Skipped: {$notificationStats['skipped']}\n";
    }
    if (!empty($notificationStats['errors'])) {
        echo "  Errors: " . count($notificationStats['errors']) . "\n";
        foreach ($notificationStats['errors'] as $error) {
            echo "    - {$error}\n";
        }
    }
    echo "\n";
    
    // Archive audit logs
    echo "Archiving audit logs...\n";
    $auditStats = $archiveService->archiveAuditLogs($yearsAudit, $dryRun);
    echo "  audit_logs: {$auditStats['audit_logs']}\n";
    echo "  audit_payments: {$auditStats['audit_payments']}\n";
    echo "  audit_subscriptions: {$auditStats['audit_subscriptions']}\n";
    echo "  audit_financial: {$auditStats['audit_financial']}\n";
    echo "  audit_documents: {$auditStats['audit_documents']}\n";
    if ($auditStats['skipped'] > 0) {
        echo "  Skipped: {$auditStats['skipped']}\n";
    }
    if (!empty($auditStats['errors'])) {
        echo "  Errors: " . count($auditStats['errors']) . "\n";
        foreach ($auditStats['errors'] as $error) {
            echo "    - {$error}\n";
        }
    }
    echo "\n";
    
    // Clean up expired tokens and invitations (these can be deleted)
    if (!$dryRun) {
        echo "Cleaning up expired tokens and invitations...\n";
        
        // Delete expired password reset tokens
        $stmt = $db->prepare("DELETE FROM password_resets WHERE expires_at < NOW()");
        $stmt->execute();
        $deletedTokens = $stmt->rowCount();
        echo "  Deleted expired password reset tokens: {$deletedTokens}\n";
        
        // Delete expired invitations
        $stmt = $db->prepare("DELETE FROM invitations WHERE expires_at < NOW()");
        $stmt->execute();
        $deletedInvitations = $stmt->rowCount();
        echo "  Deleted expired invitations: {$deletedInvitations}\n";
        echo "\n";
    } else {
        // Count what would be deleted
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM password_resets WHERE expires_at < NOW()");
        $stmt->execute();
        $expiredTokens = $stmt->fetch()['count'];
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM invitations WHERE expires_at < NOW()");
        $stmt->execute();
        $expiredInvitations = $stmt->fetch()['count'];
        
        echo "Would delete:\n";
        echo "  Expired password reset tokens: {$expiredTokens}\n";
        echo "  Expired invitations: {$expiredInvitations}\n";
        echo "\n";
    }
    
    // Get archive statistics
    echo "Archive Statistics:\n";
    $stats = $archiveService->getArchiveStatistics();
    echo "  Total archived notifications: {$stats['notifications']}\n";
    echo "  Total archived audit_logs: {$stats['audit_logs']}\n";
    echo "  Total archived audit_payments: {$stats['audit_payments']}\n";
    echo "  Total archived audit_subscriptions: {$stats['audit_subscriptions']}\n";
    echo "  Total archived audit_financial: {$stats['audit_financial']}\n";
    echo "  Total archived audit_documents: {$stats['audit_documents']}\n";
    if ($stats['oldest_notification_archive']) {
        echo "  Oldest notification archive: {$stats['oldest_notification_archive']}\n";
    }
    if ($stats['oldest_audit_archive']) {
        echo "  Oldest audit archive: {$stats['oldest_audit_archive']}\n";
    }
    echo "\n";
    
    // Summary
    echo "========================================\n";
    echo "Summary\n";
    echo "========================================\n";
    echo "Notifications archived: {$notificationStats['archived']}\n";
    $totalAudit = $auditStats['audit_logs'] + $auditStats['audit_payments'] + 
                  $auditStats['audit_subscriptions'] + $auditStats['audit_financial'] + 
                  $auditStats['audit_documents'];
    echo "Audit logs archived: {$totalAudit}\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    
    if ($dryRun) {
        echo "\nThis was a DRY RUN. No data was actually archived.\n";
        echo "Run without --dry-run to perform the actual archiving.\n";
    } else {
        echo "\nArchive process completed successfully!\n";
    }
    
    exit(0);
    
} catch (\Exception $e) {
    echo "\nFatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
