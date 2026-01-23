<?php
/**
 * Validation script for Migration 098: Migrate Existing Subscriptions
 * 
 * This script validates that the migration 098 was executed correctly
 * and that all subscriptions have been properly migrated to the license-based model.
 * 
 * Usage: php cli/validate-migration-098.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

global $db;

if (!$db) {
    echo "ERROR: Database connection not available\n";
    exit(1);
}

echo "=== Validating Migration 098: Migrate Existing Subscriptions ===\n\n";

$errors = [];
$warnings = [];
$success = [];

// 1. Check if migration was executed
echo "1. Checking if migration 098 was executed...\n";
$migrationStmt = $db->query("SELECT * FROM migrations WHERE migration = '098_migrate_existing_subscriptions'");
$migration = $migrationStmt->fetch();

if (!$migration) {
    $warnings[] = "Migration 098 not found in migrations table. It may not have been executed yet.";
    echo "   ⚠ WARNING: Migration 098 not found in migrations table\n";
} else {
    $success[] = "Migration 098 was executed on: " . $migration['executed_at'];
    echo "   ✓ Migration 098 executed on: {$migration['executed_at']}\n";
}
echo "\n";

// 2. Check if new columns exist in plans table
echo "2. Checking if new plan columns exist...\n";
$columnsStmt = $db->query("SHOW COLUMNS FROM plans");
$columns = $columnsStmt->fetchAll();
$columnNames = array_column($columns, 'Field');

$requiredColumns = ['plan_type', 'license_min', 'license_limit', 'allow_multiple_condos', 'allow_overage', 'pricing_mode'];
$missingColumns = [];

foreach ($requiredColumns as $col) {
    if (!in_array($col, $columnNames)) {
        $missingColumns[] = $col;
    }
}

if (!empty($missingColumns)) {
    $errors[] = "Missing required columns in plans table: " . implode(', ', $missingColumns);
    echo "   ✗ Missing columns: " . implode(', ', $missingColumns) . "\n";
} else {
    $success[] = "All required columns exist in plans table";
    echo "   ✓ All required columns exist\n";
}
echo "\n";

// 3. Check if plan_pricing_tiers table exists
echo "3. Checking if plan_pricing_tiers table exists...\n";
$tablesStmt = $db->query("SHOW TABLES LIKE 'plan_pricing_tiers'");
if ($tablesStmt->rowCount() === 0) {
    $errors[] = "plan_pricing_tiers table does not exist";
    echo "   ✗ plan_pricing_tiers table does not exist\n";
} else {
    $success[] = "plan_pricing_tiers table exists";
    echo "   ✓ plan_pricing_tiers table exists\n";
    
    // Check if tiers are seeded
    $tiersStmt = $db->query("SELECT COUNT(*) as count FROM plan_pricing_tiers");
    $tiersCount = $tiersStmt->fetch()['count'];
    if ($tiersCount === 0) {
        $warnings[] = "No pricing tiers found. Run seeders to populate pricing tiers.";
        echo "   ⚠ WARNING: No pricing tiers found\n";
    } else {
        $success[] = "Found {$tiersCount} pricing tiers";
        echo "   ✓ Found {$tiersCount} pricing tiers\n";
    }
}
echo "\n";

// 4. Check subscriptions migration status
echo "4. Checking subscriptions migration status...\n";
$subscriptionsStmt = $db->query("
    SELECT 
        s.id,
        s.status,
        s.used_licenses,
        s.extra_licenses,
        p.slug as plan_slug,
        p.plan_type,
        p.license_min
    FROM subscriptions s
    INNER JOIN plans p ON s.plan_id = p.id
    WHERE s.status IN ('trial', 'active', 'pending')
    ORDER BY s.id
");
$subscriptions = $subscriptionsStmt->fetchAll();

if (empty($subscriptions)) {
    $warnings[] = "No active subscriptions found";
    echo "   ⚠ WARNING: No active subscriptions found\n";
} else {
    $migratedCount = 0;
    $unmigratedCount = 0;
    
    foreach ($subscriptions as $sub) {
        if ($sub['plan_type'] && ($sub['used_licenses'] !== null && $sub['used_licenses'] >= 0)) {
            $migratedCount++;
            
            // Validate used_licenses is at least license_min
            if ($sub['used_licenses'] < $sub['license_min']) {
                $warnings[] = "Subscription #{$sub['id']} has used_licenses ({$sub['used_licenses']}) less than license_min ({$sub['license_min']})";
            }
        } else {
            $unmigratedCount++;
            $errors[] = "Subscription #{$sub['id']} (plan: {$sub['plan_slug']}) not properly migrated";
        }
    }
    
    $success[] = "Found {$migratedCount} migrated subscriptions";
    echo "   ✓ Found {$migratedCount} migrated subscriptions\n";
    
    if ($unmigratedCount > 0) {
        echo "   ✗ Found {$unmigratedCount} unmigrated subscriptions\n";
    }
}
echo "\n";

// 5. Check subscription_condominiums associations
echo "5. Checking subscription_condominiums associations...\n";
$assocStmt = $db->query("
    SELECT COUNT(*) as count 
    FROM subscription_condominiums 
    WHERE status = 'active'
");
$assocCount = $assocStmt->fetch()['count'];

if ($assocCount > 0) {
    $success[] = "Found {$assocCount} active condominium associations";
    echo "   ✓ Found {$assocCount} active condominium associations\n";
} else {
    $warnings[] = "No condominium associations found";
    echo "   ⚠ WARNING: No condominium associations found\n";
}
echo "\n";

// 6. Validate license counts match actual fractions
echo "6. Validating license counts match actual fractions...\n";
$validationStmt = $db->query("
    SELECT 
        s.id as subscription_id,
        s.used_licenses,
        p.plan_type,
        COUNT(DISTINCT f.id) as actual_fractions
    FROM subscriptions s
    INNER JOIN plans p ON s.plan_id = p.id
    LEFT JOIN subscription_condominiums sc ON s.id = sc.subscription_id AND sc.status = 'active'
    LEFT JOIN fractions f ON sc.condominium_id = f.condominium_id 
        AND f.is_active = TRUE 
        AND f.archived_at IS NULL
        AND (f.license_consumed IS NULL OR f.license_consumed = TRUE)
    WHERE s.status IN ('trial', 'active', 'pending')
        AND p.plan_type IN ('professional', 'enterprise')
    GROUP BY s.id, s.used_licenses, p.plan_type
");
$validations = $validationStmt->fetchAll();

$mismatchCount = 0;
foreach ($validations as $val) {
    $expected = max((int)$val['actual_fractions'], (int)$val['license_min'] ?? 0);
    if ((int)$val['used_licenses'] !== $expected) {
        $mismatchCount++;
        $warnings[] = "Subscription #{$val['subscription_id']}: used_licenses ({$val['used_licenses']}) doesn't match actual fractions ({$val['actual_fractions']})";
    }
}

if ($mismatchCount === 0) {
    $success[] = "All license counts match actual fractions";
    echo "   ✓ All license counts match actual fractions\n";
} else {
    echo "   ⚠ WARNING: Found {$mismatchCount} subscriptions with mismatched license counts\n";
}
echo "\n";

// 7. Check for orphaned subscriptions (without associated plan)
echo "7. Checking for orphaned subscriptions...\n";
$orphanedStmt = $db->query("
    SELECT s.id, s.user_id, s.plan_id
    FROM subscriptions s
    LEFT JOIN plans p ON s.plan_id = p.id
    WHERE s.status IN ('trial', 'active', 'pending')
    AND p.id IS NULL
");
$orphaned = $orphanedStmt->fetchAll();

if (empty($orphaned)) {
    $success[] = "No orphaned subscriptions found";
    echo "   ✓ No orphaned subscriptions found\n";
} else {
    $errors[] = "Found " . count($orphaned) . " orphaned subscription(s) without associated plan";
    echo "   ✗ Found " . count($orphaned) . " orphaned subscription(s)\n";
    foreach ($orphaned as $orphan) {
        echo "     - Subscription #{$orphan['id']} (plan_id: {$orphan['plan_id']})\n";
    }
}
echo "\n";

// 8. Validate referential integrity between subscriptions and subscription_condominiums
echo "8. Validating referential integrity...\n";
$integrityStmt = $db->query("
    SELECT sc.id, sc.subscription_id, sc.condominium_id
    FROM subscription_condominiums sc
    LEFT JOIN subscriptions s ON sc.subscription_id = s.id
    WHERE sc.status = 'active'
    AND s.id IS NULL
");
$brokenRefs = $integrityStmt->fetchAll();

if (empty($brokenRefs)) {
    $success[] = "Referential integrity is valid";
    echo "   ✓ Referential integrity is valid\n";
} else {
    $errors[] = "Found " . count($brokenRefs) . " broken reference(s) in subscription_condominiums";
    echo "   ✗ Found " . count($brokenRefs) . " broken reference(s)\n";
}
echo "\n";

// 9. Check for active fractions without condominium association
echo "9. Checking for orphaned fractions...\n";
$orphanedFractionsStmt = $db->query("
    SELECT f.id, f.condominium_id, f.identifier
    FROM fractions f
    LEFT JOIN condominiums c ON f.condominium_id = c.id
    WHERE f.is_active = TRUE
    AND f.archived_at IS NULL
    AND c.id IS NULL
");
$orphanedFractions = $orphanedFractionsStmt->fetchAll();

if (empty($orphanedFractions)) {
    $success[] = "No orphaned fractions found";
    echo "   ✓ No orphaned fractions found\n";
} else {
    $warnings[] = "Found " . count($orphanedFractions) . " orphaned fraction(s) without condominium";
    echo "   ⚠ WARNING: Found " . count($orphanedFractions) . " orphaned fraction(s)\n";
}
echo "\n";

// 10. Validate pricing tier prices are correct
echo "10. Validating pricing tier prices...\n";
$tierStmt = $db->query("
    SELECT 
        pt.id,
        pt.plan_id,
        pt.min_licenses,
        pt.max_licenses,
        pt.price_per_license,
        p.name as plan_name,
        p.slug as plan_slug
    FROM plan_pricing_tiers pt
    INNER JOIN plans p ON pt.plan_id = p.id
    WHERE pt.is_active = TRUE
    ORDER BY pt.plan_id, pt.sort_order
");
$tiers = $tierStmt->fetchAll();

$invalidTiers = [];
foreach ($tiers as $tier) {
    $price = (float)$tier['price_per_license'];
    if ($price <= 0) {
        $invalidTiers[] = $tier;
    }
}

if (empty($invalidTiers)) {
    $success[] = "All pricing tier prices are valid";
    echo "   ✓ All pricing tier prices are valid\n";
} else {
    $warnings[] = "Found " . count($invalidTiers) . " pricing tier(s) with invalid prices";
    echo "   ⚠ WARNING: Found " . count($invalidTiers) . " pricing tier(s) with invalid prices\n";
    foreach ($invalidTiers as $tier) {
        echo "     - Tier #{$tier['id']} ({$tier['plan_name']}): Price = {$tier['price_per_license']}\n";
    }
}
echo "\n";

// Summary
echo "=== Validation Summary ===\n\n";
echo "✓ Success: " . count($success) . "\n";
echo "⚠ Warnings: " . count($warnings) . "\n";
echo "✗ Errors: " . count($errors) . "\n\n";

if (!empty($success)) {
    echo "Success messages:\n";
    foreach ($success as $msg) {
        echo "  ✓ {$msg}\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "Warnings:\n";
    foreach ($warnings as $msg) {
        echo "  ⚠ {$msg}\n";
    }
    echo "\n";
}

if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $msg) {
        echo "  ✗ {$msg}\n";
    }
    echo "\n";
    exit(1);
}

if (empty($errors) && empty($warnings)) {
    echo "✓ All validations passed! Migration 098 was successful.\n";
    exit(0);
} elseif (empty($errors)) {
    echo "⚠ Migration completed with warnings. Please review the warnings above.\n";
    exit(0);
} else {
    echo "✗ Migration validation failed. Please fix the errors above.\n";
    exit(1);
}
