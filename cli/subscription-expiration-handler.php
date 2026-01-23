<?php
/**
 * Subscription Expiration Handler CLI
 * 
 * Expires subscriptions and locks associated condominiums when subscriptions are not paid.
 * 
 * Usage: php cli/subscription-expiration-handler.php [--dry-run]
 * 
 * Options:
 *   --dry-run   Show what would be expired without actually expiring
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../database.php';

use App\Services\SubscriptionService;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv);

echo "========================================\n";
echo "Subscription Expiration Handler\n";
echo "========================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no subscriptions will be expired)" : "LIVE") . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }
    
    $subscriptionService = new SubscriptionService();
    
    // Get expired subscriptions
    $subscriptions = $subscriptionService->getExpiredSubscriptions();
    
    if (empty($subscriptions)) {
        echo "No expired subscriptions found.\n";
        exit(0);
    }
    
    echo "Found " . count($subscriptions) . " expired subscription(s).\n\n";
    
    $totalExpired = 0;
    $totalSkipped = 0;
    $errors = [];
    
    foreach ($subscriptions as $subscription) {
        $subscriptionId = $subscription['id'];
        $userName = $subscription['user_name'];
        $userEmail = $subscription['user_email'];
        $planName = $subscription['plan_name'];
        $expirationDate = date('d/m/Y', strtotime($subscription['current_period_end']));
        $daysExpired = ceil((time() - strtotime($subscription['current_period_end'])) / 86400);
        
        echo "Processing: {$userName} ({$userEmail})\n";
        echo "  Plan: {$planName}\n";
        echo "  Expired: {$expirationDate} ({$daysExpired} day(s) ago)\n";
        
        if ($dryRun) {
            echo "  [DRY RUN] Would expire subscription and lock condominiums\n";
            $totalExpired++;
        } else {
            try {
                $success = $subscriptionService->expireSubscription($subscriptionId);
                
                if ($success) {
                    echo "  ✓ Subscription expired and condominiums locked\n";
                    $totalExpired++;
                } else {
                    echo "  ✗ Failed to expire subscription\n";
                    $errors[] = "Failed to expire subscription ID {$subscriptionId}";
                    $totalSkipped++;
                }
            } catch (\Exception $e) {
                $errorMsg = "Error expiring subscription ID {$subscriptionId}: " . $e->getMessage();
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
    echo "Subscriptions expired: {$totalExpired}\n";
    echo "Subscriptions skipped: {$totalSkipped}\n";
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
        echo "\nThis was a DRY RUN. No subscriptions were actually expired.\n";
        echo "Run without --dry-run to perform the actual expiration.\n";
    } else {
        echo "\nExpiration process completed successfully!\n";
    }
    
    exit(0);
    
} catch (\Exception $e) {
    echo "\nFatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
