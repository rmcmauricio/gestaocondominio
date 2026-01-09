<?php
/**
 * Database Seeder Runner
 * 
 * Usage: php cli/seed.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Core\DatabaseMigration;

try {
    // Ensure migrations are run first
    $migration = new DatabaseMigration();
    $migration->runMigrations();
    
    // Run seeders
    require __DIR__ . '/../database/seeders/SeederRunner.php';
    $seeder = new SeederRunner($GLOBALS['db']);
    $seeder->run();
    
    echo "Seeders completed successfully!\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}





