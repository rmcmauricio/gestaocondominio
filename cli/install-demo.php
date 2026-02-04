<?php
/**
 * Demo Data Installation CLI
 * 
 * Installs demo data for the first time and saves a snapshot of all created IDs.
 * This script will delete existing demo data before installing to avoid duplicates.
 * 
 * Usage: php cli/install-demo.php
 * 
 * This script:
 * 1. Deletes all existing demo condominiums (via delete-condominium.php)
 * 2. Runs DemoSeeder to create demo data
 * 3. Captures all created IDs
 * 4. Saves snapshot to storage/demo/original_ids.json
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

use App\Core\DatabaseMigration;
use App\Core\AuditManager;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

echo "========================================\n";
echo "Demo Data Installation\n";
echo "========================================\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }

    // Disable auditing during demo installation to avoid filling the database with logs
    AuditManager::disable();
    echo "Auditoria desabilitada durante a instalação demo.\n\n";

    // Ensure migrations are run first
    echo "Verificando migrations...\n";
    try {
        $migration = new DatabaseMigration();
        $migration->runMigrations();
        echo "Migrations verificadas.\n\n";
    } catch (\Exception $e) {
        echo "Aviso: Erro ao executar migrations: " . $e->getMessage() . "\n";
        echo "Continuando mesmo assim...\n\n";
    }

    // Step 1: Delete existing demo data to avoid duplicates
    echo "Passo 1: Removendo dados demo existentes (se houver)...\n";
    
    // Check if demo condominiums exist
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM condominiums WHERE is_demo = TRUE");
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result && $result['count'] > 0) {
        echo "   Encontrados {$result['count']} condomínio(s) demo existente(s).\n";
        echo "   Executando delete-condominium.php para remover dados existentes...\n";
        
        // Execute delete-condominium.php script
        $deleteScript = __DIR__ . '/delete-condominium.php';
        if (file_exists($deleteScript)) {
            // Use exec to run the delete script
            $output = [];
            $returnVar = 0;
            exec("php \"{$deleteScript}\" 2>&1", $output, $returnVar);
            
            if ($returnVar !== 0) {
                echo "   AVISO: Erro ao executar delete-condominium.php:\n";
                echo "   " . implode("\n   ", $output) . "\n";
                echo "   Continuando mesmo assim...\n";
            } else {
                echo "   Dados demo existentes removidos com sucesso.\n";
            }
        } else {
            echo "   AVISO: Script delete-condominium.php não encontrado.\n";
            echo "   Continuando mesmo assim...\n";
        }
    } else {
        echo "   Nenhum condomínio demo existente encontrado.\n";
    }
    
    echo "\n";

    // Step 2: Run DemoSeeder to create demo data
    echo "Passo 2: Criando dados demo...\n";
    
    require_once __DIR__ . '/../database/seeders/DemoSeeder.php';
    $demoSeeder = new DemoSeeder($db);
    $demoSeeder->run();
    
    echo "\n";

    // Step 3: Capture all created IDs
    echo "Passo 3: Capturando IDs de todos os registos criados...\n";
    $createdIds = $demoSeeder->getCreatedIds();
    
    $totalRecords = 0;
    foreach ($createdIds as $table => $ids) {
        if ($table === 'created_at' || $table === 'demo_user_id') {
            continue;
        }
        $count = is_array($ids) ? count($ids) : 0;
        $totalRecords += $count;
        if ($count > 0) {
            echo "   {$table}: {$count} registos\n";
        }
    }
    echo "   Total: {$totalRecords} registos capturados\n";
    echo "\n";

    // Step 4: Save snapshot to JSON file
    echo "Passo 4: Guardando snapshot dos IDs...\n";
    
    $storageDir = __DIR__ . '/../storage/demo';
    if (!is_dir($storageDir)) {
        if (!mkdir($storageDir, 0755, true)) {
            throw new \Exception("Não foi possível criar o diretório: {$storageDir}");
        }
    }
    
    $snapshotFile = $storageDir . '/original_ids.json';
    $jsonData = json_encode($createdIds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if (file_put_contents($snapshotFile, $jsonData) === false) {
        throw new \Exception("Não foi possível escrever o ficheiro: {$snapshotFile}");
    }
    
    echo "   Snapshot guardado em: {$snapshotFile}\n";
    echo "\n";

    // Re-enable auditing
    AuditManager::enable();
    
    echo "========================================\n";
    echo "Instalação concluída com sucesso!\n";
    echo "========================================\n";
    echo "\n";
    echo "Os dados demo foram instalados e os IDs foram guardados.\n";
    echo "Agora pode usar restore-demo.php para restaurar os dados demo quando necessário.\n";
    echo "\n";

} catch (\PDOException $e) {
    // Re-enable auditing even on error
    AuditManager::enable();
    echo "Erro de banco de dados: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
} catch (\Exception $e) {
    // Re-enable auditing even on error
    AuditManager::enable();
    
    echo "Erro: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
