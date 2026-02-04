<?php
/**
 * Update Demo Condominiums with Logos and Templates
 * 
 * Usage: php cli/update-demo-logos.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Models\Condominium;

/**
 * Copy logo file from assets/images to storage/condominiums/{id}/logo/
 */
function copyLogoToStorage(int $condominiumId, string $sourcePath): ?string
{
    $projectRoot = __DIR__ . '/..';
    $sourceFile = $projectRoot . '/' . $sourcePath;
    
    // Check if source file exists
    if (!file_exists($sourceFile)) {
        echo "   Aviso: Arquivo de logo não encontrado: {$sourcePath}\n";
        return null;
    }
    
    // Get file extension
    $extension = strtolower(pathinfo($sourceFile, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        echo "   Aviso: Extensão de arquivo não suportada: {$extension}\n";
        return null;
    }
    
    // Create storage directory structure: storage/condominiums/{condominium_id}/logo/
    $storageBasePath = $projectRoot . '/storage';
    $storagePath = 'condominiums/' . $condominiumId . '/logo/';
    $fullStoragePath = $storageBasePath . '/' . $storagePath;
    
    if (!is_dir($fullStoragePath)) {
        mkdir($fullStoragePath, 0755, true);
    }
    
    // Delete old logo if exists
    $oldLogoPath = $fullStoragePath . 'logo.*';
    $oldLogos = glob($oldLogoPath);
    foreach ($oldLogos as $oldLogo) {
        if (is_file($oldLogo)) {
            unlink($oldLogo);
        }
    }
    
    // Copy file to storage
    $filename = 'logo.' . $extension;
    $destinationFile = $fullStoragePath . $filename;
    
    if (!copy($sourceFile, $destinationFile)) {
        echo "   Aviso: Erro ao copiar logo para storage\n";
        return null;
    }
    
    return $storagePath . $filename;
}

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }
    
    echo "=== Atualizando Condomínios Demo ===\n";
    
    // Get demo condominiums
    $stmt = $db->prepare("SELECT id, name FROM condominiums WHERE is_demo = TRUE ORDER BY id ASC");
    $stmt->execute();
    $condominiums = $stmt->fetchAll();
    
    if (count($condominiums) < 2) {
        echo "ERRO: É necessário ter pelo menos 2 condomínios demo.\n";
        echo "Execute primeiro: php cli/restore-demo.php\n";
        exit(1);
    }
    
    // Update first condominium (Residencial Sol Nascente)
    $firstCondominium = $condominiums[0];
    echo "\n1. Atualizando '{$firstCondominium['name']}' (ID: {$firstCondominium['id']})...\n";
    $logoPath1 = copyLogoToStorage($firstCondominium['id'], 'assets/images/2596845_condominium_400x267.jpg');
    $stmt = $db->prepare("
        UPDATE condominiums 
        SET logo_path = :logo_path,
            document_template = :document_template
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $firstCondominium['id'],
        ':logo_path' => $logoPath1,
        ':document_template' => null
    ]);
    echo "   ✓ Logo copiado para: {$logoPath1}\n";
    echo "   ✓ Template: Padrão (NULL)\n";
    
    // Update second condominium (Edifício Mar Atlântico)
    $secondCondominium = $condominiums[1];
    echo "\n2. Atualizando '{$secondCondominium['name']}' (ID: {$secondCondominium['id']})...\n";
    $logoPath2 = copyLogoToStorage($secondCondominium['id'], 'assets/images/77106082_modern-apartment-building_400x600.jpg');
    $stmt = $db->prepare("
        UPDATE condominiums 
        SET logo_path = :logo_path,
            document_template = :document_template
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $secondCondominium['id'],
        ':logo_path' => $logoPath2,
        ':document_template' => 3
    ]);
    echo "   ✓ Logo copiado para: {$logoPath2}\n";
    echo "   ✓ Template: 3 (Elegante Dark Mode)\n";
    
    echo "\n=== Atualização concluída com sucesso! ===\n";
    
} catch (\Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
