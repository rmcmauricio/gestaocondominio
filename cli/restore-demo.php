<?php
/**
 * Demo Data Restorer CLI
 * 
 * Restores demo data to its original state by removing all user modifications
 * and re-running the demo seeder.
 * 
 * Usage: php cli/restore-demo.php [--dry-run]
 * 
 * Options:
 *   --dry-run    Show what would be restored without actually restoring
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

use App\Models\Condominium;
use App\Models\User;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

// Check for dry-run flag
$dryRun = in_array('--dry-run', $argv);

echo "========================================\n";
echo "Demo Data Restorer\n";
echo "========================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes will be made)" : "LIVE") . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }

    // Find demo condominiums
    $stmt = $db->prepare("SELECT id, name FROM condominiums WHERE is_demo = TRUE");
    $stmt->execute();
    $demoCondominiums = $stmt->fetchAll();

    if (empty($demoCondominiums)) {
        echo "Nenhum condomínio demo encontrado.\n\n";
        exit(0);
    }

    echo "Encontrados " . count($demoCondominiums) . " condomínio(s) demo.\n\n";

    $userModel = new User($db);

    if (!$dryRun) {
        try {
            // Get demo user ID
            $demoUser = $userModel->findByEmail('demo@predio.pt');
            if (!$demoUser) {
                throw new \Exception("Utilizador demo não encontrado.");
            }

            // Instantiate DemoSeeder - it will handle all demo condominiums
            require_once __DIR__ . '/../database/seeders/DemoSeeder.php';
            $demoSeeder = new DemoSeeder($db);

            echo "Removendo todos os dados demo existentes...\n";
            $demoSeeder->deleteDemoData();
            echo "Dados removidos.\n\n";

            echo "Repopulando dados demo para todos os condomínios...\n";
            $demoSeeder->run(); // This will recreate data for all demo condominiums
            echo "Dados repopulados.\n\n";

            echo "Restauração concluída com sucesso para todos os condomínios demo.\n\n";
            
        } catch (\Exception $e) {
            echo "ERRO ao restaurar dados demo: " . $e->getMessage() . "\n";
            echo "Stack trace: " . $e->getTraceAsString() . "\n\n";
        }
    } else {
        foreach ($demoCondominiums as $condominium) {
            echo "  [DRY RUN] Condomínio {$condominium['name']} (ID: {$condominium['id']}) seria restaurado.\n";
        }
        echo "\n";
    }

} catch (\PDOException $e) {
    echo "Erro de banco de dados: " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

echo "========================================\n";
echo "Restauração concluída!\n";
echo "========================================\n";
