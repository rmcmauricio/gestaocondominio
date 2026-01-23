<?php
/**
 * Subscription Renewal Reminder CLI
 * 
 * Sends email reminders to users whose subscriptions are expiring soon.
 * 
 * Usage: php cli/subscription-renewal-reminder.php [--days=X] [--dry-run]
 * 
 * Options:
 *   --days=X    Days before expiration to send reminder (default: 7)
 *   --dry-run   Show what would be sent without actually sending emails
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../database.php';

use App\Services\SubscriptionService;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

// Parse command line arguments
$daysBefore = 7;
$dryRun = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--days=') === 0) {
        $daysBefore = (int)substr($arg, strlen('--days='));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

echo "========================================\n";
echo "Subscription Renewal Reminder System\n";
echo "========================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no emails will be sent)" : "LIVE") . "\n";
echo "Days before expiration: {$daysBefore}\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }
    
    $subscriptionService = new SubscriptionService();
    
    // Get subscriptions expiring soon
    $subscriptions = $subscriptionService->getSubscriptionsExpiringInDays($daysBefore);
    
    if (empty($subscriptions)) {
        echo "No subscriptions expiring in {$daysBefore} days.\n";
        exit(0);
    }
    
    echo "Found " . count($subscriptions) . " subscription(s) expiring soon.\n\n";
    
    $totalSent = 0;
    $totalSkipped = 0;
    $errors = [];
    
    foreach ($subscriptions as $subscription) {
        $subscriptionId = $subscription['id'];
        $userName = $subscription['user_name'];
        $userEmail = $subscription['user_email'];
        $planName = $subscription['plan_name'];
        $expirationDate = date('d/m/Y', strtotime($subscription['current_period_end']));
        $daysLeft = ceil((strtotime($subscription['current_period_end']) - time()) / 86400);
        
        echo "Processing: {$userName} ({$userEmail})\n";
        echo "  Plan: {$planName}\n";
        echo "  Expires: {$expirationDate} ({$daysLeft} day(s))\n";
        
        if (empty($userEmail)) {
            echo "  ⚠ Skipped: No email address\n";
            $totalSkipped++;
            continue;
        }
        
        if ($dryRun) {
            echo "  [DRY RUN] Would send renewal reminder email\n";
            $totalSent++;
        } else {
            try {
                $success = $subscriptionService->sendRenewalReminder($subscriptionId, $daysBefore);
                
                if ($success) {
                    echo "  ✓ Reminder sent successfully\n";
                    $totalSent++;
                } else {
                    echo "  ✗ Failed to send reminder\n";
                    $errors[] = "Failed to send reminder to {$userEmail}";
                    $totalSkipped++;
                }
            } catch (\Exception $e) {
                $errorMsg = "Error sending reminder to {$userEmail}: " . $e->getMessage();
                echo "  ✗ {$errorMsg}\n";
                $errors[] = $errorMsg;
                $totalSkipped++;
            }
        }
        
        echo "\n";
    }
    
    // Summary
    echo "========================================\n";
    echo "Summary\n";
    echo "========================================\n";
    echo "Reminders sent: {$totalSent}\n";
    echo "Reminders skipped: {$totalSkipped}\n";
    echo "Errors: " . count($errors) . "\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    
    if (!empty($errors)) {
        echo "\nErrors encountered:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
        exit(1);
    }
    
    if ($dryRun) {
        echo "\nThis was a DRY RUN. No emails were actually sent.\n";
        echo "Run without --dry-run to send the reminders.\n";
    } else {
        echo "\nRenewal reminder process completed successfully!\n";
    }
    
    exit(0);
    
} catch (\Exception $e) {
    echo "\nFatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
