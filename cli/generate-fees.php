<?php
/**
 * Automatic Fee Generation CLI
 * 
 * Generates monthly fees for all condominiums with approved budgets.
 * Can be run via cron job monthly.
 * 
 * Usage: php cli/generate-fees.php [year] [month]
 * Example: php cli/generate-fees.php 2024 12
 * 
 * If no parameters provided, uses current year/month
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Services\FeeService;
use App\Models\Condominium;
use App\Models\Budget;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

// Get year and month from arguments or use current
$year = isset($argv[1]) ? (int)$argv[1] : (int)date('Y');
$month = isset($argv[2]) ? (int)$argv[2] : (int)date('m');

// Validate month
if ($month < 1 || $month > 12) {
    echo "Error: Invalid month. Must be between 1 and 12.\n";
    exit(1);
}

// Validate year
if ($year < 2020 || $year > 2100) {
    echo "Error: Invalid year.\n";
    exit(1);
}

echo "========================================\n";
echo "Automatic Fee Generation\n";
echo "========================================\n";
echo "Period: {$year}/" . str_pad($month, 2, '0', STR_PAD_LEFT) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }
    
    // Get all active condominiums
    $condominiumModel = new Condominium();
    $stmt = $db->prepare("SELECT id, name FROM condominiums WHERE is_active = TRUE ORDER BY id");
    $stmt->execute();
    $condominiums = $stmt->fetchAll() ?: [];
    
    if (empty($condominiums)) {
        echo "No active condominiums found.\n";
        exit(0);
    }
    
    echo "Found " . count($condominiums) . " active condominium(s).\n\n";
    
    $feeService = new FeeService();
    $budgetModel = new Budget();
    
    $totalProcessed = 0;
    $totalGenerated = 0;
    $totalSkipped = 0;
    $errors = [];
    
    foreach ($condominiums as $condominium) {
        $condominiumId = $condominium['id'];
        $condominiumName = $condominium['name'];
        
        echo "Processing: {$condominiumName} (ID: {$condominiumId})...\n";
        
        try {
            // Check if budget exists and is approved for this year
            $budget = $budgetModel->getByCondominiumAndYear($condominiumId, $year);
            
            if (!$budget) {
                echo "  ⚠ Skipped: No budget found for year {$year}\n";
                $totalSkipped++;
                continue;
            }
            
            if (!in_array($budget['status'], ['draft', 'approved', 'active'])) {
                echo "  ⚠ Skipped: Budget status is '{$budget['status']}' (needs to be draft, approved, or active)\n";
                $totalSkipped++;
                continue;
            }
            
            // Generate fees
            $generated = $feeService->generateMonthlyFees($condominiumId, $year, $month);
            
            if (empty($generated)) {
                echo "  ℹ No fees generated (may already exist)\n";
                $totalSkipped++;
            } else {
                echo "  ✓ Generated " . count($generated) . " fee(s)\n";
                $totalGenerated += count($generated);
            }
            
            $totalProcessed++;
            
        } catch (\Exception $e) {
            $errorMsg = "Error processing {$condominiumName}: " . $e->getMessage();
            echo "  ✗ {$errorMsg}\n";
            $errors[] = $errorMsg;
        }
        
        echo "\n";
    }
    
    // Summary
    echo "========================================\n";
    echo "Summary\n";
    echo "========================================\n";
    echo "Condominiums processed: {$totalProcessed}\n";
    echo "Fees generated: {$totalGenerated}\n";
    echo "Condominiums skipped: {$totalSkipped}\n";
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
    
    echo "\nFee generation completed successfully!\n";
    exit(0);
    
} catch (\Exception $e) {
    echo "\nFatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
