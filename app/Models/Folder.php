<?php

namespace App\Models;

use App\Core\Model;

class Folder extends Model
{
    protected $table = 'folders';

    /**
     * Get folders by condominium
     */
    public function getByCondominium(int $condominiumId, ?int $parentFolderId = null): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT f.*, 
                       u.name as created_by_name,
                       (SELECT COUNT(*) FROM documents d WHERE d.folder = f.path AND d.condominium_id = f.condominium_id 
                        AND (d.document_type != 'folder_placeholder' OR d.document_type IS NULL)
                        AND d.title != '.folder_placeholder') as document_count,
                       (SELECT COUNT(*) FROM folders f2 WHERE f2.parent_folder_id = f.id) as subfolder_count
                FROM folders f
                LEFT JOIN users u ON u.id = f.created_by
                WHERE f.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if ($parentFolderId === null) {
            // Get root folders
            $sql .= " AND f.parent_folder_id IS NULL";
        } else {
            // Get subfolders
            $sql .= " AND f.parent_folder_id = :parent_folder_id";
            $params[':parent_folder_id'] = $parentFolderId;
        }

        $sql .= " ORDER BY f.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get all folders for a condominium (for dropdowns)
     */
    public function getAllByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT f.*, 
                   (SELECT COUNT(*) FROM documents d WHERE d.folder = f.path AND d.condominium_id = f.condominium_id) as document_count
            FROM folders f
            WHERE f.condominium_id = :condominium_id
            ORDER BY f.path ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find folder by path
     */
    public function findByPath(int $condominiumId, string $path): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM folders WHERE condominium_id = :condominium_id AND path = :path LIMIT 1");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':path' => $path
        ]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find folder by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM folders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create folder
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO folders (condominium_id, name, parent_folder_id, path, created_by)
            VALUES (:condominium_id, :name, :parent_folder_id, :path, :created_by)
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':name' => $data['name'],
            ':parent_folder_id' => $data['parent_folder_id'] ?? null,
            ':path' => $data['path'],
            ':created_by' => $data['created_by']
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update folder
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['name', 'parent_folder_id', 'path'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE folders SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete folder
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Get folder path before deleting
        $folder = $this->findById($id);
        if (!$folder) {
            return false;
        }

        // Move all documents in this folder to root (set folder to NULL)
        $stmt = $this->db->prepare("
            UPDATE documents 
            SET folder = NULL 
            WHERE condominium_id = :condominium_id AND folder = :folder_path
        ");
        $stmt->execute([
            ':condominium_id' => $folder['condominium_id'],
            ':folder_path' => $folder['path']
        ]);

        // Delete the folder
        $stmt = $this->db->prepare("DELETE FROM folders WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Rename folder and update all documents
     */
    public function rename(int $id, string $newName, string $newPath): bool
    {
        if (!$this->db) {
            return false;
        }

        $folder = $this->findById($id);
        if (!$folder) {
            return false;
        }

        $oldPath = $folder['path'];
        $condominiumId = $folder['condominium_id'];

        // Start transaction
        $this->db->beginTransaction();

        try {
            // Update folder name and path
            $stmt = $this->db->prepare("
                UPDATE folders 
                SET name = :name, path = :path 
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':name' => $newName,
                ':path' => $newPath
            ]);

            // Update all documents in this folder
            $stmt = $this->db->prepare("
                UPDATE documents 
                SET folder = :new_path 
                WHERE condominium_id = :condominium_id AND folder = :old_path
            ");
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':old_path' => $oldPath,
                ':new_path' => $newPath
            ]);

            // Update all subfolders paths
            $this->updateSubfolderPaths($condominiumId, $oldPath, $newPath);

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Update paths of all subfolders recursively
     */
    protected function updateSubfolderPaths(int $condominiumId, string $oldPath, string $newPath): void
    {
        // Get all folders that start with old path
        $stmt = $this->db->prepare("
            SELECT id, path FROM folders 
            WHERE condominium_id = :condominium_id 
            AND path LIKE :old_path_pattern
            AND path != :old_path
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':old_path_pattern' => $oldPath . '/%',
            ':old_path' => $oldPath
        ]);

        $subfolders = $stmt->fetchAll() ?: [];

        foreach ($subfolders as $subfolder) {
            $newSubfolderPath = str_replace($oldPath, $newPath, $subfolder['path']);
            
            // Update folder path
            $stmt = $this->db->prepare("UPDATE folders SET path = :new_path WHERE id = :id");
            $stmt->execute([
                ':id' => $subfolder['id'],
                ':new_path' => $newSubfolderPath
            ]);

            // Update documents in this subfolder
            $stmt = $this->db->prepare("
                UPDATE documents 
                SET folder = :new_path 
                WHERE condominium_id = :condominium_id AND folder = :old_path
            ");
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':old_path' => $subfolder['path'],
                ':new_path' => $newSubfolderPath
            ]);
        }
    }
}
