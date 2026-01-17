<?php
/**
 * Condominium Data Deletion CLI
 * 
 * Removes all data related to a condominium (generic, can be used for demo or any condominium).
 * 
 * Usage:
 *   php cli/delete-condominium.php                    - Remove all demo condominiums
 *   php cli/delete-condominium.php --condominium-id=123 - Remove specific condominium by ID
 *   php cli/delete-condominium.php --dry-run          - Show what would be deleted without deleting
 * 
 * Options:
 *   --condominium-id=N  ID of the condominium to delete (if not provided, deletes all demo condominiums)
 *   --dry-run          Show what would be deleted without actually deleting
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

use App\Core\DatabaseMigration;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

// Parse command line arguments
$condominiumId = null;
$dryRun = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--condominium-id=') === 0) {
        $condominiumId = (int)substr($arg, strlen('--condominium-id='));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

echo "========================================\n";
echo "Condominium Data Deletion\n";
echo "========================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes will be made)" : "LIVE") . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }

    // Ensure migrations are run first
    if (!$dryRun) {
        echo "Verificando migrations...\n";
        try {
            $migration = new DatabaseMigration();
            $migration->runMigrations();
            echo "Migrations verificadas.\n\n";
        } catch (\Exception $e) {
            echo "Aviso: Erro ao executar migrations: " . $e->getMessage() . "\n";
            echo "Continuando mesmo assim...\n\n";
        }
    }

    $condominiumsToDelete = [];

    if ($condominiumId) {
        // Delete specific condominium
        $stmt = $db->prepare("SELECT id, name, is_demo FROM condominiums WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $condominiumId]);
        $condominium = $stmt->fetch();
        
        if (!$condominium) {
            throw new \Exception("Condomínio com ID {$condominiumId} não encontrado.");
        }
        
        $condominiumsToDelete[] = $condominium;
        
        if ($condominium['is_demo']) {
            echo "ATENÇÃO: Está prestes a remover um condomínio DEMO.\n";
        } else {
            echo "ATENÇÃO: Está prestes a remover um condomínio NÃO-DEMO (ID: {$condominiumId}).\n";
        }
    } else {
        // Delete all demo condominiums
        $stmt = $db->prepare("SELECT id, name, is_demo FROM condominiums WHERE is_demo = TRUE");
        $stmt->execute();
        $condominiumsToDelete = $stmt->fetchAll();
        
        if (empty($condominiumsToDelete)) {
            echo "Nenhum condomínio demo encontrado.\n";
            exit(0);
        }
        
        echo "Encontrados " . count($condominiumsToDelete) . " condomínio(s) demo.\n";
    }

    echo "\n";

    $hasDemoCondominium = false;
    
    foreach ($condominiumsToDelete as $condominium) {
        $id = $condominium['id'];
        $name = $condominium['name'];
        
        if ($condominium['is_demo']) {
            $hasDemoCondominium = true;
        }
        
        echo "--- Processando: {$name} (ID: {$id}) ---\n";
        
        if ($dryRun) {
            echo "  [DRY RUN] Dados seriam removidos para este condomínio.\n";
            continue;
        }

        // Delete condominium data
        deleteCondominiumData($db, $id, $condominium['is_demo']);
        
        // Finally, delete the condominium itself
        $stmt = $db->prepare("DELETE FROM condominiums WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        echo "  Condomínio removido com sucesso.\n";
    }

    // If we deleted demo condominiums, also delete the demo snapshot
    if ($hasDemoCondominium && !$dryRun) {
        echo "\nRemovendo snapshot da demo...\n";
        $snapshotFile = __DIR__ . '/../storage/demo/original_ids.json';
        if (file_exists($snapshotFile)) {
            if (@unlink($snapshotFile)) {
                echo "  Snapshot da demo removido: {$snapshotFile}\n";
            } else {
                echo "  AVISO: Não foi possível remover o snapshot da demo: {$snapshotFile}\n";
            }
        } else {
            echo "  Snapshot da demo não encontrado (já foi removido ou nunca existiu).\n";
        }
    } elseif ($hasDemoCondominium && $dryRun) {
        echo "\n[DRY RUN] Snapshot da demo seria removido.\n";
    }

    echo "\n========================================\n";
    echo "Remoção concluída!\n";
    echo "========================================\n";

} catch (\PDOException $e) {
    echo "Erro de banco de dados: " . $e->getMessage() . "\n";
    exit(1);
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Delete all data for a condominium
 * 
 * @param PDO $db Database connection
 * @param int $condominiumId Condominium ID
 * @param bool $isDemo Whether this is a demo condominium (for safety checks)
 */
function deleteCondominiumData($db, int $condominiumId, bool $isDemo = false): void
{
    // Safety check for demo condominiums
    if ($isDemo) {
        $checkStmt = $db->prepare("SELECT is_demo FROM condominiums WHERE id = :condominium_id LIMIT 1");
        $checkStmt->execute([':condominium_id' => $condominiumId]);
        $condominium = $checkStmt->fetch();
        if (!$condominium || !$condominium['is_demo']) {
            throw new \Exception("CRITICAL: Attempted to delete data from non-demo condominium ID {$condominiumId} when is_demo flag was set. This should never happen!");
        }
    }
    
    $basePath = __DIR__ . '/../storage';
    
    // Delete in correct order to respect foreign keys
    
    // Assembly-related data
    echo "  Removendo dados de assembleias...\n";
    $db->exec("DELETE FROM minutes_signatures WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
    $db->exec("DELETE FROM assembly_votes WHERE topic_id IN (SELECT id FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId}))");
    $db->exec("DELETE FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
    $db->exec("DELETE FROM assembly_attendees WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
    $db->exec("DELETE FROM assemblies WHERE condominium_id = {$condominiumId}");
    
    // Standalone votes
    echo "  Removendo votações standalone...\n";
    $db->exec("DELETE FROM standalone_vote_responses WHERE standalone_vote_id IN (SELECT id FROM standalone_votes WHERE condominium_id = {$condominiumId})");
    $db->exec("DELETE FROM standalone_votes WHERE condominium_id = {$condominiumId}");
    
    // Fee-related data
    echo "  Removendo dados de quotas...\n";
    $db->exec("DELETE FROM fee_payment_history WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
    $db->exec("UPDATE fee_payments SET financial_transaction_id = NULL WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
    $db->exec("DELETE FROM fee_payments WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
    $db->exec("DELETE FROM fees WHERE condominium_id = {$condominiumId}");
    
    // Financial transactions and bank accounts
    echo "  Removendo transações financeiras e contas bancárias...\n";
    $db->exec("DELETE FROM financial_transactions WHERE condominium_id = {$condominiumId}");
    $db->exec("DELETE FROM bank_accounts WHERE condominium_id = {$condominiumId}");
    
    // Reservations and spaces
    echo "  Removendo reservas e espaços...\n";
    $db->exec("DELETE FROM reservations WHERE condominium_id = {$condominiumId}");
    $db->exec("DELETE FROM spaces WHERE condominium_id = {$condominiumId}");
    
    // Occurrences
    echo "  Removendo ocorrências...\n";
    $occurrenceAttachmentsStmt = $db->prepare("SELECT file_path FROM occurrence_attachments WHERE condominium_id = :condominium_id");
    $occurrenceAttachmentsStmt->execute([':condominium_id' => $condominiumId]);
    $occurrenceAttachments = $occurrenceAttachmentsStmt->fetchAll();
    foreach ($occurrenceAttachments as $attachment) {
        if (!empty($attachment['file_path'])) {
            $fullPath = $basePath . '/' . $attachment['file_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
    $db->exec("DELETE FROM occurrence_comments WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id = {$condominiumId})");
    $db->exec("DELETE FROM occurrence_history WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id = {$condominiumId})");
    $db->exec("DELETE FROM occurrence_attachments WHERE condominium_id = {$condominiumId}");
    $db->exec("DELETE FROM occurrences WHERE condominium_id = {$condominiumId}");
    
    // Expenses
    echo "  Removendo despesas...\n";
    $db->exec("DELETE FROM expenses WHERE condominium_id = {$condominiumId}");
    
    // Budgets
    echo "  Removendo orçamentos...\n";
    $db->exec("DELETE FROM budget_items WHERE budget_id IN (SELECT id FROM budgets WHERE condominium_id = {$condominiumId})");
    $db->exec("DELETE FROM budgets WHERE condominium_id = {$condominiumId}");
    
    // Contracts
    echo "  Removendo contratos...\n";
    $db->exec("DELETE FROM contracts WHERE condominium_id = {$condominiumId}");
    
    // Suppliers
    echo "  Removendo fornecedores...\n";
    $db->exec("DELETE FROM suppliers WHERE condominium_id = {$condominiumId}");
    
    // Receipts (with file deletion)
    echo "  Removendo recibos...\n";
    $receiptsStmt = $db->prepare("SELECT file_path FROM receipts WHERE condominium_id = :condominium_id");
    $receiptsStmt->execute([':condominium_id' => $condominiumId]);
    $receipts = $receiptsStmt->fetchAll();
    foreach ($receipts as $receipt) {
        if (!empty($receipt['file_path'])) {
            $filePath = $receipt['file_path'];
            if (strpos($filePath, 'condominiums/') === 0) {
                $fullPath = $basePath . '/' . $filePath;
            } else {
                $fullPath = $basePath . '/documents/' . $filePath;
            }
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
    $db->exec("DELETE FROM receipts WHERE condominium_id = {$condominiumId}");
    
    // Messages and attachments
    echo "  Removendo mensagens...\n";
    $messageAttachmentsStmt = $db->prepare("SELECT file_path FROM message_attachments WHERE condominium_id = :condominium_id");
    $messageAttachmentsStmt->execute([':condominium_id' => $condominiumId]);
    $messageAttachments = $messageAttachmentsStmt->fetchAll();
    foreach ($messageAttachments as $attachment) {
        if (!empty($attachment['file_path'])) {
            $fullPath = $basePath . '/' . $attachment['file_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
    $db->exec("DELETE FROM message_attachments WHERE condominium_id = {$condominiumId}");
    $db->exec("DELETE FROM messages WHERE condominium_id = {$condominiumId}");
    
    // Revenues
    echo "  Removendo receitas...\n";
    $db->exec("DELETE FROM revenues WHERE condominium_id = {$condominiumId}");
    
    // Documents (with file deletion)
    echo "  Removendo documentos...\n";
    $documentsStmt = $db->prepare("SELECT file_path FROM documents WHERE condominium_id = :condominium_id");
    $documentsStmt->execute([':condominium_id' => $condominiumId]);
    $documents = $documentsStmt->fetchAll();
    foreach ($documents as $document) {
        if (!empty($document['file_path'])) {
            $fullPath = $basePath . '/' . $document['file_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
    $db->exec("DELETE FROM documents WHERE condominium_id = {$condominiumId}");
    
    // Notifications
    echo "  Removendo notificações...\n";
    $db->exec("DELETE FROM notifications WHERE condominium_id = {$condominiumId}");
    
    // Condominium users
    echo "  Removendo associações de utilizadores...\n";
    $db->exec("DELETE FROM condominium_users WHERE condominium_id = {$condominiumId}");
    
    // Fractions
    echo "  Removendo frações...\n";
    $db->exec("DELETE FROM fractions WHERE condominium_id = {$condominiumId}");
    
    // Delete condominium storage folder
    echo "  Removendo pasta de documentos do condomínio...\n";
    $condominiumFolder = $basePath . '/condominiums/' . $condominiumId;
    if (is_dir($condominiumFolder)) {
        deleteDirectory($condominiumFolder);
        echo "  Pasta de documentos removida: {$condominiumFolder}\n";
    }
    
    // Delete users that are ONLY associated with this condominium (but keep demo user)
    echo "  Removendo utilizadores órfãos...\n";
    $stmt = $db->prepare("SELECT user_id FROM condominium_users WHERE condominium_id = {$condominiumId}");
    $stmt->execute();
    $userIds = $stmt->fetchAll();
    
    // Get demo user ID if exists
    $demoUserStmt = $db->prepare("SELECT id FROM users WHERE email = 'demo@predio.pt' LIMIT 1");
    $demoUserStmt->execute();
    $demoUser = $demoUserStmt->fetch();
    $demoUserId = $demoUser ? $demoUser['id'] : null;
    
    foreach ($userIds as $row) {
        $userId = $row['user_id'];
        
        // Skip demo user
        if ($demoUserId && $userId == $demoUserId) {
            continue;
        }
        
        // Check if user is associated with any other condominiums
        $checkStmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM condominium_users 
            WHERE user_id = :user_id 
            AND condominium_id != :condominium_id
        ");
        $checkStmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        $check = $checkStmt->fetch();
        
        // Only delete if user is NOT associated with any other condominiums
        if ($check && $check['count'] == 0) {
            $db->exec("DELETE FROM users WHERE id = {$userId}");
        }
    }
}

/**
 * Recursively delete a directory and all its contents
 * 
 * @param string $dir Directory path to delete
 * @return bool True on success, false on failure
 */
function deleteDirectory(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
    
    return @rmdir($dir);
}
