<?php
/**
 * License Monitoring Script
 * 
 * This script monitors license usage and sends alerts for:
 * - Subscriptions approaching license limits
 * - Condominiums locked for extended periods
 * - Generates weekly usage reports
 * 
 * Usage: php cli/monitor-licenses.php [--alerts] [--report] [--long-locked] [--threshold=0.8] [--days=30]
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Services\LicenseMonitoringService;

global $db;

if (!$db) {
    echo "ERROR: Database connection not available\n";
    exit(1);
}

// Parse command line arguments
$options = getopt('', ['alerts', 'report', 'long-locked', 'threshold:', 'days:']);

$monitoringService = new LicenseMonitoringService();

$alertsSent = 0;
$reportGenerated = false;
$longLockedAlerts = 0;

echo "=== License Monitoring Script ===\n\n";

// Send limit alerts
if (isset($options['alerts']) || (empty($options) && !isset($options['report']) && !isset($options['long-locked']))) {
    $threshold = isset($options['threshold']) ? (float)$options['threshold'] : 0.8;
    echo "1. Checking subscriptions near limit (threshold: " . ($threshold * 100) . "%)...\n";
    
    $subscriptions = $monitoringService->checkSubscriptionsNearLimit($threshold);
    echo "   Found " . count($subscriptions) . " subscriptions near limit\n";
    
    if (!empty($subscriptions)) {
        echo "   Sending alerts...\n";
        $alertsSent = $monitoringService->sendLimitAlerts($threshold);
        echo "   ✓ Sent {$alertsSent} alert(s)\n";
        
        // Show details
        foreach ($subscriptions as $sub) {
            echo "   - Subscription #{$sub['subscription_id']} ({$sub['plan_name']}): ";
            echo "{$sub['used_licenses']}/{$sub['license_limit']} ({$sub['usage_percentage']}%)\n";
        }
    } else {
        echo "   ✓ No subscriptions near limit\n";
    }
    echo "\n";
}

// Generate weekly report
if (isset($options['report'])) {
    echo "2. Generating weekly license usage report...\n";
    $report = $monitoringService->generateWeeklyReport();
    
    echo "   Report Period: {$report['period_start']} to {$report['period_end']}\n";
    echo "   Total Subscriptions: {$report['total_subscriptions']}\n";
    echo "   Active Subscriptions: {$report['active_subscriptions']}\n";
    echo "   Subscriptions Near Limit: {$report['subscriptions_near_limit']}\n";
    echo "   Total Licenses Used: {$report['total_licenses_used']}\n";
    echo "   Total License Limit: {$report['total_licenses_limit']}\n";
    echo "   Locked Condominiums: {$report['locked_condominiums']}\n";
    
    if (!empty($report['by_plan_type'])) {
        echo "   Breakdown by Plan Type:\n";
        foreach ($report['by_plan_type'] as $planType => $data) {
            echo "     - {$planType}: {$data['count']} subscriptions, {$data['total_licenses']} licenses\n";
        }
    }
    
    $reportGenerated = true;
    echo "   ✓ Report generated\n\n";
}

// Check long-locked condominiums
if (isset($options['long-locked'])) {
    $days = isset($options['days']) ? (int)$options['days'] : 30;
    echo "3. Checking condominiums locked for {$days}+ days...\n";
    
    $condominiums = $monitoringService->checkLongLockedCondominiums($days);
    echo "   Found " . count($condominiums) . " long-locked condominium(s)\n";
    
    if (!empty($condominiums)) {
        echo "   Sending alerts...\n";
        $longLockedAlerts = $monitoringService->sendLongLockedAlerts($days);
        echo "   ✓ Sent {$longLockedAlerts} alert(s)\n";
        
        // Show details
        foreach ($condominiums as $condo) {
            echo "   - Condominium #{$condo['id']} ({$condo['name']}): ";
            echo "Locked for {$condo['days_locked']} days\n";
            echo "     Reason: {$condo['locked_reason']}\n";
        }
    } else {
        echo "   ✓ No long-locked condominiums\n";
    }
    echo "\n";
}

// Summary
echo "=== Summary ===\n";
echo "Limit alerts sent: {$alertsSent}\n";
if ($reportGenerated) {
    echo "Weekly report: Generated\n";
}
echo "Long-locked alerts sent: {$longLockedAlerts}\n";
echo "\n";

if (empty($options)) {
    echo "Usage:\n";
    echo "  php cli/monitor-licenses.php --alerts [--threshold=0.8]\n";
    echo "  php cli/monitor-licenses.php --report\n";
    echo "  php cli/monitor-licenses.php --long-locked [--days=30]\n";
    echo "  php cli/monitor-licenses.php --alerts --report --long-locked\n";
}

exit(0);
