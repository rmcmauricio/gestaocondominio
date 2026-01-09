<?php
/**
 * Database Migration Runner
 * 
 * Usage: php cli/migrate.php [up|down|fresh]
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Core\DatabaseMigration;

try {
    $migration = new DatabaseMigration();
    
    $command = $argv[1] ?? 'up';
    
    switch ($command) {
        case 'up':
            echo "Running migrations...\n";
            $migration->runMigrations();
            echo "Migrations completed successfully!\n";
            break;
            
        case 'down':
            $batches = (int)($argv[2] ?? 1);
            echo "Rolling back {$batches} batch(es)...\n";
            $migration->rollback($batches);
            echo "Rollback completed!\n";
            break;
            
        case 'fresh':
            echo "WARNING: This will drop all tables and re-run migrations!\n";
            echo "Type 'yes' to continue: ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            if (trim($line) !== 'yes') {
                echo "Cancelled.\n";
                exit;
            }
            fclose($handle);
            
            // Drop all tables (implement if needed)
            echo "Fresh migration not fully implemented. Please run migrations manually.\n";
            break;
            
        default:
            echo "Usage: php cli/migrate.php [up|down|fresh]\n";
            exit(1);
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}





