<?php
/**
 * Data Integrity Verification CLI
 * 
 * Verifies and optionally fixes data inconsistencies in subscriptions, licenses, and condominiums.
 * 
 * Usage: php cli/verify-data-integrity.php [--fix] [--dry-run]
 * 
 * Options:
 *   --fix       Automatically fix inconsistencies (use with caution!)
 *   --dry-run   Show what would be fixed without actually fixing
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../database.php';

use App\Services\SubscriptionService;
use App\Services\LicenseService;
use App\Models\Condominium;
use App\Models\Fraction;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

// Parse command line arguments
$fix = in_array('--fix', $argv);
$dryRun = in_array('--dry-run', $argv);

echo "========================================\n";
echo "Data Integrity Verification System\n";
echo "========================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no fixes will be applied)" : ($fix ? "FIX MODE (fixes will be applied)" : "VERIFY ONLY")) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }
    
    $subscriptionService = new SubscriptionService();
    $licenseService = new LicenseService();
    $condominiumModel = new Condominium();
    $fractionModel = new Fraction();
    
    $issues = [];
    $fixes = [];
    
    // 1. Verify used_licenses vs actual active fractions
    echo "1. Verifying license usage...\n";
    $stmt = $db->query("
        SELECT s.id, s.user_id, s.plan_id, s.license_limit, s.used_licenses,
               p.license_min, p.plan_type
        FROM subscriptions s
        INNER JOIN plans p ON s.plan_id = p.id
        WHERE s.status IN ('active', 'trial')
        AND p.plan_type IS NOT NULL
    ");
    
    $subscriptions = $stmt->fetchAll() ?: [];
    
    foreach ($subscriptions as $subscription) {
        $subscriptionId = $subscription['id'];
        $userId = $subscription['user_id'];
        $licenseLimit = (int)($subscription['license_limit'] ?? 0);
        $usedLicenses = (int)($subscription['used_licenses'] ?? 0);
        
        // Count actual active fractions
        $countStmt = $db->prepare("
            SELECT COUNT(DISTINCT f.id) as count
            FROM fractions f
            INNER JOIN condominiums c ON f.condominium_id = c.id
            WHERE c.user_id = :user_id
            AND f.is_active = 1
        ");
        $countStmt->execute([':user_id' => $userId]);
        $actualCount = (int)$countStmt->fetch()['count'];
        
        if ($usedLicenses != $actualCount) {
            $issue = [
                'type' => 'license_count_mismatch',
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
                'expected' => $actualCount,
                'actual' => $usedLicenses,
                'message' => "Subscription ID {$subscriptionId}: used_licenses ({$usedLicenses}) doesn't match actual active fractions ({$actualCount})"
            ];
            $issues[] = $issue;
            
            echo "  ⚠ {$issue['message']}\n";
            
            if ($fix && !$dryRun) {
                $db->prepare("UPDATE subscriptions SET used_licenses = :count WHERE id = :id")
                   ->execute([':count' => $actualCount, ':id' => $subscriptionId]);
                $fixes[] = "Fixed license count for subscription ID {$subscriptionId}";
                echo "    ✓ Fixed\n";
            }
        }
    }
    
    echo "\n";
    
    // 2. Verify license_limit vs license_min + extra_licenses
    echo "2. Verifying license limits...\n";
    $stmt = $db->query("
        SELECT s.id, s.plan_id, s.license_limit, s.extra_licenses,
               p.license_min
        FROM subscriptions s
        INNER JOIN plans p ON s.plan_id = p.id
        WHERE s.status IN ('active', 'trial')
        AND p.plan_type IS NOT NULL
    ");
    
    $subscriptions = $stmt->fetchAll() ?: [];
    
    foreach ($subscriptions as $subscription) {
        $subscriptionId = $subscription['id'];
        $licenseMin = (int)($subscription['license_min'] ?? 0);
        $extraLicenses = (int)($subscription['extra_licenses'] ?? 0);
        $licenseLimit = (int)($subscription['license_limit'] ?? 0);
        $expectedLimit = $licenseMin + $extraLicenses;
        
        if ($licenseLimit != $expectedLimit && $licenseLimit > 0) {
            $issue = [
                'type' => 'license_limit_mismatch',
                'subscription_id' => $subscriptionId,
                'expected' => $expectedLimit,
                'actual' => $licenseLimit,
                'message' => "Subscription ID {$subscriptionId}: license_limit ({$licenseLimit}) doesn't match license_min ({$licenseMin}) + extra_licenses ({$extraLicenses}) = {$expectedLimit}"
            ];
            $issues[] = $issue;
            
            echo "  ⚠ {$issue['message']}\n";
            
            if ($fix && !$dryRun) {
                $db->prepare("UPDATE subscriptions SET license_limit = :limit WHERE id = :id")
                   ->execute([':limit' => $expectedLimit, ':id' => $subscriptionId]);
                $fixes[] = "Fixed license limit for subscription ID {$subscriptionId}";
                echo "    ✓ Fixed\n";
            }
        }
    }
    
    echo "\n";
    
    // 3. Verify expired subscriptions are locked
    echo "3. Verifying expired subscriptions...\n";
    $stmt = $db->query("
        SELECT s.id, s.user_id, s.status, s.current_period_end
        FROM subscriptions s
        WHERE s.status = 'expired'
        AND s.current_period_end < NOW()
    ");
    
    $expiredSubscriptions = $stmt->fetchAll() ?: [];
    
    foreach ($expiredSubscriptions as $subscription) {
        $subscriptionId = $subscription['id'];
        $userId = $subscription['user_id'];
        
        // Check if condominiums are locked
        $condStmt = $db->prepare("
            SELECT c.id, c.subscription_status, c.locked_reason
            FROM condominiums c
            WHERE c.user_id = :user_id
            AND (c.subscription_status != 'locked' OR c.locked_reason IS NULL)
        ");
        $condStmt->execute([':user_id' => $userId]);
        $unlockedCondominiums = $condStmt->fetchAll() ?: [];
        
        if (!empty($unlockedCondominiums)) {
            $issue = [
                'type' => 'expired_subscription_not_locked',
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
                'unlocked_count' => count($unlockedCondominiums),
                'message' => "Subscription ID {$subscriptionId} is expired but " . count($unlockedCondominiums) . " condominium(s) are not locked"
            ];
            $issues[] = $issue;
            
            echo "  ⚠ {$issue['message']}\n";
            
            if ($fix && !$dryRun) {
                foreach ($unlockedCondominiums as $cond) {
                    $condominiumModel->lock($cond['id'], null, 'Subscrição expirada - pagamento pendente');
                }
                $fixes[] = "Locked " . count($unlockedCondominiums) . " condominium(s) for expired subscription ID {$subscriptionId}";
                echo "    ✓ Fixed\n";
            }
        }
    }
    
    echo "\n";
    
    // 4. Verify condominiums blocked without reason
    echo "4. Verifying locked condominiums...\n";
    $stmt = $db->query("
        SELECT id, name, subscription_status, locked_reason
        FROM condominiums
        WHERE subscription_status = 'locked'
        AND (locked_reason IS NULL OR locked_reason = '')
    ");
    
    $lockedWithoutReason = $stmt->fetchAll() ?: [];
    
    foreach ($lockedWithoutReason as $condominium) {
        $issue = [
            'type' => 'locked_without_reason',
            'condominium_id' => $condominium['id'],
            'message' => "Condominium ID {$condominium['id']} ({$condominium['name']}) is locked but has no reason"
        ];
        $issues[] = $issue;
        
        echo "  ⚠ {$issue['message']}\n";
        
        if ($fix && !$dryRun) {
            $db->prepare("UPDATE condominiums SET locked_reason = 'Bloqueado por verificação de integridade' WHERE id = :id")
               ->execute([':id' => $condominium['id']]);
            $fixes[] = "Added reason to locked condominium ID {$condominium['id']}";
            echo "    ✓ Fixed\n";
        }
    }
    
    echo "\n";
    
    // Summary
    echo "========================================\n";
    echo "Summary\n";
    echo "========================================\n";
    echo "Issues found: " . count($issues) . "\n";
    echo "Fixes applied: " . count($fixes) . "\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    
    if (!empty($issues)) {
        echo "\nIssues by type:\n";
        $byType = [];
        foreach ($issues as $issue) {
            $byType[$issue['type']] = ($byType[$issue['type']] ?? 0) + 1;
        }
        foreach ($byType as $type => $count) {
            echo "  - {$type}: {$count}\n";
        }
    }
    
    if (!empty($fixes)) {
        echo "\nFixes applied:\n";
        foreach ($fixes as $fix) {
            echo "  - {$fix}\n";
        }
    }
    
    if ($dryRun && $fix) {
        echo "\nThis was a DRY RUN. No fixes were actually applied.\n";
        echo "Run without --dry-run to apply the fixes.\n";
    } elseif (!$fix && !empty($issues)) {
        echo "\nRun with --fix to automatically fix these issues.\n";
    }
    
    exit(empty($issues) ? 0 : 1);
    
} catch (\Exception $e) {
    echo "\nFatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
