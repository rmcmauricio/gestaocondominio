<?php

class MigrateExistingFolders
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Get all unique folder paths from documents
        $stmt = $this->db->query("
            SELECT DISTINCT condominium_id, folder 
            FROM documents 
            WHERE folder IS NOT NULL 
            AND folder != ''
            ORDER BY condominium_id, folder
        ");
        
        $folders = $stmt->fetchAll() ?: [];
        
        foreach ($folders as $folderData) {
            $condominiumId = $folderData['condominium_id'];
            $folderPath = $folderData['folder'];
            
            // Check if folder already exists
            $checkStmt = $this->db->prepare("
                SELECT id FROM folders 
                WHERE condominium_id = :condominium_id AND path = :path
            ");
            $checkStmt->execute([
                ':condominium_id' => $condominiumId,
                ':path' => $folderPath
            ]);
            
            if ($checkStmt->fetch()) {
                continue; // Folder already exists
            }
            
            // Parse folder path to get name and parent
            $parts = explode('/', $folderPath);
            $name = end($parts);
            $parentPath = count($parts) > 1 ? implode('/', array_slice($parts, 0, -1)) : null;
            
            // Get parent folder ID if exists
            $parentFolderId = null;
            if ($parentPath) {
                $parentStmt = $this->db->prepare("
                    SELECT id FROM folders 
                    WHERE condominium_id = :condominium_id AND path = :path
                ");
                $parentStmt->execute([
                    ':condominium_id' => $condominiumId,
                    ':path' => $parentPath
                ]);
                $parentFolder = $parentStmt->fetch();
                if ($parentFolder) {
                    $parentFolderId = $parentFolder['id'];
                }
            }
            
            // Get first user who uploaded to this folder as creator
            $userStmt = $this->db->prepare("
                SELECT uploaded_by FROM documents 
                WHERE condominium_id = :condominium_id AND folder = :path 
                LIMIT 1
            ");
            $userStmt->execute([
                ':condominium_id' => $condominiumId,
                ':path' => $folderPath
            ]);
            $userData = $userStmt->fetch();
            $createdBy = $userData['uploaded_by'] ?? 1; // Default to user 1 if not found
            
            // Insert folder
            $insertStmt = $this->db->prepare("
                INSERT INTO folders (condominium_id, name, parent_folder_id, path, created_by)
                VALUES (:condominium_id, :name, :parent_folder_id, :path, :created_by)
            ");
            $insertStmt->execute([
                ':condominium_id' => $condominiumId,
                ':name' => $name,
                ':parent_folder_id' => $parentFolderId,
                ':path' => $folderPath,
                ':created_by' => $createdBy
            ]);
        }
    }

    public function down(): void
    {
        // This migration is one-way - we don't delete folders when rolling back
        // as they may have been created manually
    }
}
