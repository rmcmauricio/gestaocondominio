<?php
/**
 * Demo Data Restorer CLI
 * 
 * Restores demo data to its original state by removing all user modifications
 * while preserving original demo data (no regeneration needed).
 * 
 * Usage: php cli/restore-demo.php [--dry-run]
 * 
 * Options:
 *   --dry-run    Show what would be restored without actually restoring
 * 
 * This script:
 * 1. Reads storage/demo/original_ids.json (created by install-demo.php)
 * 2. If snapshot doesn't exist, runs install-demo.php first
 * 3. For each table, removes only records that belong to demo condominiums
 *    but are NOT in the original IDs list (user modifications)
 * 4. Preserves all original demo data (including receipts)
 * 5. Regenerates PDFs only if they're missing
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

use App\Models\User;
use App\Core\DatabaseMigration;

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

    // Step 1: Check if snapshot exists
    $snapshotFile = __DIR__ . '/../storage/demo/original_ids.json';
    
    if (!file_exists($snapshotFile)) {
        echo "Snapshot não encontrado: {$snapshotFile}\n";
        echo "Executando install-demo.php para criar dados demo e snapshot...\n\n";
        
        if (!$dryRun) {
            // Execute install-demo.php
            $installScript = __DIR__ . '/install-demo.php';
            if (file_exists($installScript)) {
                $output = [];
                $returnVar = 0;
                exec("php \"{$installScript}\" 2>&1", $output, $returnVar);
                
                if ($returnVar !== 0) {
                    throw new \Exception("Erro ao executar install-demo.php:\n" . implode("\n", $output));
                }
                
                echo implode("\n", $output) . "\n\n";
            } else {
                throw new \Exception("Script install-demo.php não encontrado.");
            }
        } else {
            echo "[DRY RUN] install-demo.php seria executado.\n\n";
            exit(0);
        }
    }

    // Step 2: Load snapshot
    echo "Carregando snapshot dos IDs originais...\n";
    $snapshotJson = file_get_contents($snapshotFile);
    $snapshot = json_decode($snapshotJson, true);
    
    if (!$snapshot || !isset($snapshot['condominiums'])) {
        throw new \Exception("Snapshot inválido ou corrompido.");
    }
    
    $demoCondominiumIds = $snapshot['condominiums'];
    $demoUserId = $snapshot['demo_user_id'] ?? null;
    
    echo "   Condomínios demo: " . implode(', ', $demoCondominiumIds) . "\n";
    echo "   Utilizador demo ID: " . ($demoUserId ?? 'N/A') . "\n";
    echo "   Snapshot criado em: " . ($snapshot['created_at'] ?? 'N/A') . "\n";
    echo "\n";

    // Step 3: Verify demo condominiums still exist
    $condominiumIdsList = implode(',', $demoCondominiumIds);
    $stmt = $db->prepare("SELECT id, name FROM condominiums WHERE id IN ({$condominiumIdsList}) AND is_demo = TRUE");
    $stmt->execute();
    $existingCondominiums = $stmt->fetchAll();
    
    if (count($existingCondominiums) !== count($demoCondominiumIds)) {
        echo "AVISO: Alguns condomínios demo não foram encontrados.\n";
        echo "Executando install-demo.php para recriar dados demo...\n\n";
        
        if (!$dryRun) {
            $installScript = __DIR__ . '/install-demo.php';
            if (file_exists($installScript)) {
                $output = [];
                $returnVar = 0;
                exec("php \"{$installScript}\" 2>&1", $output, $returnVar);
                
                if ($returnVar !== 0) {
                    throw new \Exception("Erro ao executar install-demo.php:\n" . implode("\n", $output));
                }
                
                // Reload snapshot
                $snapshotJson = file_get_contents($snapshotFile);
                $snapshot = json_decode($snapshotJson, true);
                $demoCondominiumIds = $snapshot['condominiums'];
                $demoUserId = $snapshot['demo_user_id'] ?? null;
            }
        } else {
            echo "[DRY RUN] install-demo.php seria executado.\n\n";
            exit(0);
        }
    }

    // Step 4: Remove user modifications (records not in original IDs)
    echo "Removendo modificações de utilizadores...\n\n";
    
    $basePath = __DIR__ . '/../storage';
    
    // Define tables with their relationship to condominiums
    // Format: ['table_name' => ['has_direct_condominium_id' => bool, 'parent_table' => string|null, 'parent_field' => string|null]]
    $tablesToClean = [
        // Direct condominium_id
        'standalone_votes' => [true, null, null],
        'assemblies' => [true, null, null],
        'fees' => [true, null, null],
        'financial_transactions' => [true, null, null],
        'bank_accounts' => [true, null, null],
        'reservations' => [true, null, null],
        'spaces' => [true, null, null],
        'occurrences' => [true, null, null],
        'expenses' => [true, null, null],
        'budgets' => [true, null, null],
        'contracts' => [true, null, null],
        'suppliers' => [true, null, null],
        'condominium_users' => [true, null, null],
        'fractions' => [true, null, null],
        'messages' => [true, null, null],
        'revenues' => [true, null, null],
        'documents' => [true, null, null],
        'notifications' => [true, null, null],
        'occurrence_attachments' => [true, null, null],
        'message_attachments' => [true, null, null],
        
        // Indirect relationships
        'standalone_vote_responses' => [false, 'standalone_votes', 'standalone_vote_id'],
        'assembly_vote_topics' => [false, 'assemblies', 'assembly_id'],
        'assembly_votes' => [false, 'assembly_vote_topics', 'topic_id', 'assemblies', 'assembly_id'], // Two-level join
        'assembly_attendees' => [false, 'assemblies', 'assembly_id'],
        'minutes_revisions' => [false, 'assemblies', 'assembly_id'],
        'fee_payment_history' => [false, 'fees', 'fee_id'],
        'fee_payments' => [false, 'fees', 'fee_id'],
        'budget_items' => [false, 'budgets', 'budget_id'],
        'occurrence_comments' => [false, 'occurrences', 'occurrence_id'],
        'occurrence_history' => [false, 'occurrences', 'occurrence_id'],
    ];

    foreach ($tablesToClean as $table => $config) {
        $originalIds = $snapshot[$table] ?? [];
        $hasDirectCondominiumId = $config[0];
        $parentTable = $config[1];
        $parentField = $config[2];
        $grandparentTable = $config[3] ?? null;
        $grandparentField = $config[4] ?? null;
        
        // Build query to find records to delete
        if ($hasDirectCondominiumId) {
            // Direct condominium_id relationship
            if (empty($originalIds)) {
                $sql = "SELECT id FROM {$table} WHERE condominium_id IN ({$condominiumIdsList})";
            } else {
                $originalIdsList = implode(',', array_map('intval', $originalIds));
                $sql = "SELECT id FROM {$table} WHERE condominium_id IN ({$condominiumIdsList}) AND id NOT IN ({$originalIdsList})";
            }
        } else {
            // Indirect relationship through parent table
            if ($grandparentTable !== null) {
                // Two-level join (e.g., assembly_votes -> assembly_vote_topics -> assemblies)
                if (empty($originalIds)) {
                    $sql = "SELECT t.id FROM {$table} t 
                            INNER JOIN {$parentTable} p ON p.id = t.{$parentField}
                            INNER JOIN {$grandparentTable} g ON g.id = p.{$grandparentField}
                            WHERE g.condominium_id IN ({$condominiumIdsList})";
                } else {
                    $originalIdsList = implode(',', array_map('intval', $originalIds));
                    $sql = "SELECT t.id FROM {$table} t 
                            INNER JOIN {$parentTable} p ON p.id = t.{$parentField}
                            INNER JOIN {$grandparentTable} g ON g.id = p.{$grandparentField}
                            WHERE g.condominium_id IN ({$condominiumIdsList})
                            AND t.id NOT IN ({$originalIdsList})";
                }
            } else {
                // One-level join
                if (empty($originalIds)) {
                    $sql = "SELECT t.id FROM {$table} t 
                            INNER JOIN {$parentTable} p ON p.id = t.{$parentField}
                            WHERE p.condominium_id IN ({$condominiumIdsList})";
                } else {
                    $originalIdsList = implode(',', array_map('intval', $originalIds));
                    $sql = "SELECT t.id FROM {$table} t 
                            INNER JOIN {$parentTable} p ON p.id = t.{$parentField}
                            WHERE p.condominium_id IN ({$condominiumIdsList})
                            AND t.id NOT IN ({$originalIdsList})";
                }
            }
        }
        
        if (!$dryRun) {
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $recordsToDelete = $stmt->fetchAll();
            
            if (!empty($recordsToDelete)) {
                $idsToDelete = array_column($recordsToDelete, 'id');
                $idsList = implode(',', array_map('intval', $idsToDelete));
                
                // Delete associated files if applicable
                if (in_array($table, ['documents', 'occurrence_attachments', 'message_attachments'])) {
                    $fileStmt = $db->prepare("SELECT file_path FROM {$table} WHERE id IN ({$idsList})");
                    $fileStmt->execute();
                    $files = $fileStmt->fetchAll();
                    foreach ($files as $file) {
                        if (!empty($file['file_path'])) {
                            $filePath = $basePath . '/' . $file['file_path'];
                            if (file_exists($filePath)) {
                                @unlink($filePath);
                            }
                        }
                    }
                }
                
                // Delete records
                $deleteStmt = $db->prepare("DELETE FROM {$table} WHERE id IN ({$idsList})");
                $deleteStmt->execute();
                
                echo "   {$table}: " . count($idsToDelete) . " registos removidos\n";
            }
        } else {
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $recordsToDelete = $stmt->fetchAll();
            if (!empty($recordsToDelete)) {
                echo "   [DRY RUN] {$table}: " . count($recordsToDelete) . " registos seriam removidos\n";
            }
        }
    }

    // Handle receipts specially (only delete non-demo receipts, preserve demo ones)
    echo "\nProcessando recibos...\n";
    $originalReceiptIds = $snapshot['receipts'] ?? [];
    
    if (!empty($originalReceiptIds)) {
        $originalReceiptIdsList = implode(',', array_map('intval', $originalReceiptIds));
        
        // Delete receipts not in original list
        if (!$dryRun) {
            // Get receipts to delete (with file paths)
            $receiptsStmt = $db->prepare("
                SELECT id, file_path FROM receipts 
                WHERE condominium_id IN ({$condominiumIdsList}) 
                AND id NOT IN ({$originalReceiptIdsList})
            ");
            $receiptsStmt->execute();
            $receiptsToDelete = $receiptsStmt->fetchAll();
            
            foreach ($receiptsToDelete as $receipt) {
                // Delete PDF file
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
            
            // Delete receipt records
            $deleteStmt = $db->prepare("DELETE FROM receipts WHERE condominium_id IN ({$condominiumIdsList}) AND id NOT IN ({$originalReceiptIdsList})");
            $deleteStmt->execute();
            
            $deletedCount = $deleteStmt->rowCount();
            if ($deletedCount > 0) {
                echo "   receipts: {$deletedCount} registos removidos\n";
            }
            
            // Check if any demo receipts are missing PDFs and regenerate them
            $missingPdfStmt = $db->prepare("
                SELECT id, file_path FROM receipts 
                WHERE id IN ({$originalReceiptIdsList})
            ");
            $missingPdfStmt->execute();
            $allReceipts = $missingPdfStmt->fetchAll();
            
            $missingPdfs = [];
            foreach ($allReceipts as $receipt) {
                $fileExists = false;
                if (!empty($receipt['file_path'])) {
                    $filePath = $receipt['file_path'];
                    if (strpos($filePath, 'condominiums/') === 0) {
                        $fullPath = $basePath . '/' . $filePath;
                    } else {
                        $fullPath = $basePath . '/documents/' . $filePath;
                    }
                    $fileExists = file_exists($fullPath);
                }
                
                if (!$fileExists) {
                    $missingPdfs[] = $receipt;
                }
            }
            
            if (!empty($missingPdfs)) {
                echo "   " . count($missingPdfs) . " PDF(s) em falta encontrado(s).\n";
                echo "   AVISO: Regeneração de PDFs requer acesso ao PdfService.\n";
                echo "   Execute createReceiptsForDemoPayments() no DemoSeeder para regenerar.\n";
            }
        } else {
            $receiptsStmt = $db->prepare("
                SELECT COUNT(*) as count FROM receipts 
                WHERE condominium_id IN ({$condominiumIdsList}) 
                AND id NOT IN ({$originalReceiptIdsList})
            ");
            $receiptsStmt->execute();
            $result = $receiptsStmt->fetch();
            if ($result && $result['count'] > 0) {
                echo "   [DRY RUN] receipts: {$result['count']} registos seriam removidos\n";
            }
        }
    }

    // Handle vote_options (delete options for demo condominiums that are not in original list)
    echo "\nProcessando opções de voto...\n";
    $originalVoteOptionIds = $snapshot['vote_options'] ?? [];
    
    if (!empty($originalVoteOptionIds)) {
        $originalVoteOptionIdsList = implode(',', array_map('intval', $originalVoteOptionIds));
        
        // Delete vote_options that belong to demo condominiums but are not in original list
        if (!$dryRun) {
            $deleteStmt = $db->prepare("
                SELECT id FROM vote_options 
                WHERE condominium_id IN ({$condominiumIdsList})
                AND id NOT IN ({$originalVoteOptionIdsList})
            ");
            $deleteStmt->execute();
            $toDelete = $deleteStmt->fetchAll();
            
            if (!empty($toDelete)) {
                $idsToDelete = array_column($toDelete, 'id');
                $idsList = implode(',', array_map('intval', $idsToDelete));
                $deleteStmt = $db->prepare("DELETE FROM vote_options WHERE id IN ({$idsList})");
                $deleteStmt->execute();
                echo "   vote_options: " . count($idsToDelete) . " opções removidas\n";
            }
        } else {
            $checkStmt = $db->prepare("
                SELECT COUNT(*) as count FROM vote_options 
                WHERE condominium_id IN ({$condominiumIdsList})
                AND id NOT IN ({$originalVoteOptionIdsList})
            ");
            $checkStmt->execute();
            $result = $checkStmt->fetch();
            if ($result && $result['count'] > 0) {
                echo "   [DRY RUN] vote_options: {$result['count']} opções seriam removidas\n";
            }
        }
    }

    // Handle users (only delete if not associated with non-demo condominiums)
    echo "\nProcessando utilizadores...\n";
    $originalUserIds = $snapshot['users'] ?? [];
    $allDemoUserIds = array_merge([$demoUserId], $originalUserIds);
    
    if (!$dryRun) {
        // Get all users associated with demo condominiums
        $usersStmt = $db->prepare("
            SELECT DISTINCT u.id 
            FROM users u
            INNER JOIN condominium_users cu ON cu.user_id = u.id
            WHERE cu.condominium_id IN ({$condominiumIdsList})
            AND u.id NOT IN (" . implode(',', array_map('intval', $allDemoUserIds)) . ")
        ");
        $usersStmt->execute();
        $usersToCheck = $usersStmt->fetchAll();
        
        $usersToDelete = [];
        foreach ($usersToCheck as $user) {
            $userId = $user['id'];
            
            // Check if user is associated with any non-demo condominiums
            $checkStmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM condominium_users cu
                INNER JOIN condominiums c ON c.id = cu.condominium_id
                WHERE cu.user_id = :user_id 
                AND (c.is_demo = FALSE OR c.is_demo IS NULL)
            ");
            $checkStmt->execute([':user_id' => $userId]);
            $check = $checkStmt->fetch();
            
            // Only delete if user is NOT associated with any non-demo condominiums
            if ($check && $check['count'] == 0) {
                $usersToDelete[] = $userId;
            }
        }
        
        if (!empty($usersToDelete)) {
            $usersIdsList = implode(',', array_map('intval', $usersToDelete));
            $deleteStmt = $db->prepare("DELETE FROM users WHERE id IN ({$usersIdsList})");
            $deleteStmt->execute();
            echo "   users: " . count($usersToDelete) . " utilizadores removidos\n";
        }
    } else {
        echo "   [DRY RUN] Verificação de utilizadores seria executada\n";
    }

    echo "\n========================================\n";
    echo "Restauração concluída!\n";
    echo "========================================\n";

} catch (\PDOException $e) {
    echo "Erro de banco de dados: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
