<?php

namespace App\Services;

use App\Core\AuditManager;

class CondominiumDeletionService
{
    protected $db;
    protected $basePath;

    public function __construct()
    {
        global $db;
        $this->db = $db;
        $this->basePath = __DIR__ . '/../../storage';
    }

    /**
     * Delete all data for a condominium
     * 
     * @param int $condominiumId Condominium ID
     * @param bool $isDemo Whether this is a demo condominium (for safety checks)
     * @return bool Success status
     */
    public function deleteCondominiumData(int $condominiumId, bool $isDemo = false): bool
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Safety check for demo condominiums
        if ($isDemo) {
            $checkStmt = $this->db->prepare("SELECT is_demo FROM condominiums WHERE id = :condominium_id LIMIT 1");
            $checkStmt->execute([':condominium_id' => $condominiumId]);
            $condominium = $checkStmt->fetch();
            if (!$condominium || !$condominium['is_demo']) {
                throw new \Exception("CRITICAL: Attempted to delete data from non-demo condominium ID {$condominiumId} when is_demo flag was set.");
            }
        }

        try {
            // Start transaction
            $this->db->beginTransaction();

            // Delete in correct order to respect foreign keys
            
            // Assembly-related data
            $this->db->exec("DELETE FROM minutes_revisions WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM assembly_votes WHERE topic_id IN (SELECT id FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId}))");
            $this->db->exec("DELETE FROM assembly_agenda_points WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM assembly_attendees WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM assemblies WHERE condominium_id = {$condominiumId}");
            
            // Standalone votes
            $this->db->exec("DELETE FROM standalone_vote_responses WHERE standalone_vote_id IN (SELECT id FROM standalone_votes WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM standalone_votes WHERE condominium_id = {$condominiumId}");
            
            // Fee-related data
            $this->db->exec("DELETE FROM fee_payment_history WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
            $this->db->exec("UPDATE fee_payments SET financial_transaction_id = NULL WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM fee_payments WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM fees WHERE condominium_id = {$condominiumId}");
            
            // Financial transactions and bank accounts
            $this->db->exec("DELETE FROM financial_transactions WHERE condominium_id = {$condominiumId}");
            $this->db->exec("DELETE FROM bank_accounts WHERE condominium_id = {$condominiumId}");
            
            // Reservations and spaces
            $this->db->exec("DELETE FROM reservations WHERE condominium_id = {$condominiumId}");
            $this->db->exec("DELETE FROM spaces WHERE condominium_id = {$condominiumId}");
            
            // Occurrences
            $occurrenceAttachmentsStmt = $this->db->prepare("SELECT file_path FROM occurrence_attachments WHERE condominium_id = :condominium_id");
            $occurrenceAttachmentsStmt->execute([':condominium_id' => $condominiumId]);
            $occurrenceAttachments = $occurrenceAttachmentsStmt->fetchAll();
            foreach ($occurrenceAttachments as $attachment) {
                if (!empty($attachment['file_path'])) {
                    $fullPath = $this->basePath . '/' . $attachment['file_path'];
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            $this->db->exec("DELETE FROM occurrence_comments WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM occurrence_history WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM occurrence_attachments WHERE condominium_id = {$condominiumId}");
            $this->db->exec("DELETE FROM occurrences WHERE condominium_id = {$condominiumId}");
            
            // Budgets
            $this->db->exec("DELETE FROM budget_items WHERE budget_id IN (SELECT id FROM budgets WHERE condominium_id = {$condominiumId})");
            $this->db->exec("DELETE FROM budgets WHERE condominium_id = {$condominiumId}");
            
            // Contracts
            $this->db->exec("DELETE FROM contracts WHERE condominium_id = {$condominiumId}");
            
            // Suppliers
            $this->db->exec("DELETE FROM suppliers WHERE condominium_id = {$condominiumId}");
            
            // Receipts (with file deletion)
            $receiptsStmt = $this->db->prepare("SELECT file_path FROM receipts WHERE condominium_id = :condominium_id");
            $receiptsStmt->execute([':condominium_id' => $condominiumId]);
            $receipts = $receiptsStmt->fetchAll();
            foreach ($receipts as $receipt) {
                if (!empty($receipt['file_path'])) {
                    $filePath = $receipt['file_path'];
                    if (strpos($filePath, 'condominiums/') === 0) {
                        $fullPath = $this->basePath . '/' . $filePath;
                    } else {
                        $fullPath = $this->basePath . '/documents/' . $filePath;
                    }
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            $this->db->exec("DELETE FROM receipts WHERE condominium_id = {$condominiumId}");
            
            // Messages and attachments
            $messageAttachmentsStmt = $this->db->prepare("SELECT file_path FROM message_attachments WHERE condominium_id = :condominium_id");
            $messageAttachmentsStmt->execute([':condominium_id' => $condominiumId]);
            $messageAttachments = $messageAttachmentsStmt->fetchAll();
            foreach ($messageAttachments as $attachment) {
                if (!empty($attachment['file_path'])) {
                    $fullPath = $this->basePath . '/' . $attachment['file_path'];
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            $this->db->exec("DELETE FROM message_attachments WHERE condominium_id = {$condominiumId}");
            $this->db->exec("DELETE FROM messages WHERE condominium_id = {$condominiumId}");
            
            // Revenues
            $this->db->exec("DELETE FROM revenues WHERE condominium_id = {$condominiumId}");
            
            // Documents (with file deletion)
            $documentsStmt = $this->db->prepare("SELECT file_path FROM documents WHERE condominium_id = :condominium_id");
            $documentsStmt->execute([':condominium_id' => $condominiumId]);
            $documents = $documentsStmt->fetchAll();
            foreach ($documents as $document) {
                if (!empty($document['file_path'])) {
                    $fullPath = $this->basePath . '/' . $document['file_path'];
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            $this->db->exec("DELETE FROM documents WHERE condominium_id = {$condominiumId}");
            
            // Notifications
            $this->db->exec("DELETE FROM notifications WHERE condominium_id = {$condominiumId}");
            
            // Subscription associations
            $this->db->exec("DELETE FROM subscription_condominiums WHERE condominium_id = {$condominiumId}");
            
            // Condominium users
            $this->db->exec("DELETE FROM condominium_users WHERE condominium_id = {$condominiumId}");
            
            // Fractions
            $this->db->exec("DELETE FROM fractions WHERE condominium_id = {$condominiumId}");
            
            // Delete condominium storage folder
            $condominiumFolder = $this->basePath . '/condominiums/' . $condominiumId;
            if (is_dir($condominiumFolder)) {
                $this->deleteDirectory($condominiumFolder);
            }
            
            // Delete condominium record
            $stmt = $this->db->prepare("DELETE FROM condominiums WHERE id = :id");
            $stmt->execute([':id' => $condominiumId]);
            
            // Commit transaction
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            // Rollback on error
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error deleting condominium {$condominiumId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Recursively delete a directory and all its contents
     * 
     * @param string $dir Directory path to delete
     * @return bool True on success, false on failure
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        
        return @rmdir($dir);
    }
}
