<?php

namespace App\Models;

use App\Core\Model;

class Document extends Model
{
    protected $table = 'documents';

    /**
     * Get documents by condominium
     * @param int $condominiumId
     * @param array $filters - Can include 'user_fraction_ids' array and 'is_admin' boolean for access control
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT d.*, u.name as uploaded_by_name, f.identifier as fraction_identifier
                FROM documents d
                LEFT JOIN users u ON u.id = d.uploaded_by
                LEFT JOIN fractions f ON f.id = d.fraction_id
                WHERE d.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        // Access control: filter documents based on visibility and user's fractions
        $isAdmin = $filters['is_admin'] ?? false;
        $userFractionIds = $filters['user_fraction_ids'] ?? [];
        
        if (!$isAdmin && !empty($userFractionIds)) {
            // Non-admin users can only see:
            // 1. Documents with visibility = 'condominos' (public to all condominos)
            // 2. Documents with visibility = 'fraction' AND fraction_id IN (user's fractions)
            // 3. Documents with visibility = 'fraction' AND fraction_id IS NULL (shouldn't happen, but handle it)
            // 4. Documents with visibility = 'admin' are excluded (only admins see them)
            $fractionPlaceholders = [];
            foreach ($userFractionIds as $index => $fractionId) {
                $key = ':fraction_id_' . $index;
                $fractionPlaceholders[] = $key;
                $params[$key] = $fractionId;
            }
            $fractionPlaceholdersStr = implode(',', $fractionPlaceholders);
            
            $sql .= " AND (
                (d.visibility = 'condominos')
                OR (d.visibility = 'fraction' AND d.fraction_id IS NOT NULL AND d.fraction_id IN ($fractionPlaceholdersStr))
            )";
        } elseif (!$isAdmin) {
            // User has no fractions in this condominium - only show public documents
            $sql .= " AND d.visibility = 'condominos'";
        }
        // If isAdmin is true, show all documents (no additional filter)

        if (isset($filters['folder'])) {
            if ($filters['folder'] === null || $filters['folder'] === '') {
                // Show only documents without folder (root level)
                $sql .= " AND d.folder IS NULL";
            } else {
                // Show only documents in the exact folder (not in subfolders)
                // Documents in subfolders will be shown when navigating into those subfolders
                $sql .= " AND d.folder = :folder";
                $params[':folder'] = $filters['folder'];
            }
        } else {
            // If folder filter is not set, default to showing only root documents
            $sql .= " AND d.folder IS NULL";
        }

        if (isset($filters['document_type'])) {
            $sql .= " AND d.document_type = :document_type";
            $params[':document_type'] = $filters['document_type'];
        }

        if (isset($filters['visibility'])) {
            $sql .= " AND d.visibility = :visibility";
            $params[':visibility'] = $filters['visibility'];
        }

        if (isset($filters['date_from'])) {
            $sql .= " AND DATE(d.created_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (isset($filters['date_to'])) {
            $sql .= " AND DATE(d.created_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'DESC');
        
        $allowedSortFields = ['created_at', 'title', 'file_size', 'document_type'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }
        
        if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
            $sortOrder = 'DESC';
        }

        $sql .= " ORDER BY d.{$sortBy} {$sortOrder}";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get unique document types for a condominium
     */
    public function getDocumentTypes(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT document_type, COUNT(*) as count
            FROM documents
            WHERE condominium_id = :condominium_id AND document_type IS NOT NULL
            GROUP BY document_type
            ORDER BY document_type ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get documents by folder
     */
    public function getByFolder(int $condominiumId, string $folder): array
    {
        return $this->getByCondominium($condominiumId, ['folder' => $folder]);
    }

    /**
     * Count total documents in a folder (without access control)
     */
    public function countByFolder(int $condominiumId, ?string $folder = null): int
    {
        if (!$this->db) {
            return 0;
        }

        $sql = "SELECT COUNT(*) as count FROM documents WHERE condominium_id = :condominium_id";
        $params = [':condominium_id' => $condominiumId];

        if ($folder === null || $folder === '') {
            $sql .= " AND folder IS NULL";
        } else {
            $sql .= " AND folder = :folder";
            $params[':folder'] = $folder;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get folders for condominium
     * @param int $condominiumId
     * @param string|null $parentFolder If null, returns root folders only. If 'all', returns all folders.
     */
    public function getFolders(int $condominiumId, ?string $parentFolder = null): array
    {
        if (!$this->db) {
            return [];
        }

        if ($parentFolder === null) {
            // Get root folders - extract first part of folder path
            // This includes folders that have subfolders even if no direct documents
            $sql = "
                SELECT 
                    CASE 
                        WHEN folder LIKE '%/%' THEN SUBSTRING_INDEX(folder, '/', 1)
                        ELSE folder
                    END as folder,
                    COUNT(DISTINCT CASE 
                        WHEN (document_type != 'folder_placeholder' OR document_type IS NULL) 
                             AND (title != '.folder_placeholder' OR title IS NULL)
                        THEN id ELSE NULL END) as document_count
                FROM documents
                WHERE condominium_id = :condominium_id 
                AND folder IS NOT NULL
                AND folder != ''
                GROUP BY CASE 
                    WHEN folder LIKE '%/%' THEN SUBSTRING_INDEX(folder, '/', 1)
                    ELSE folder
                END
                ORDER BY folder ASC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':condominium_id' => $condominiumId]);
            $results = $stmt->fetchAll() ?: [];
            return $results;
        } elseif ($parentFolder === 'all') {
            // Get all folders (for dropdowns)
            $sql = "
                SELECT DISTINCT folder, 
                       SUM(CASE WHEN (document_type != 'folder_placeholder' OR document_type IS NULL) 
                                 AND title != '.folder_placeholder' 
                            THEN 1 ELSE 0 END) as document_count
                FROM documents
                WHERE condominium_id = :condominium_id 
                AND folder IS NOT NULL
                GROUP BY folder
                ORDER BY folder ASC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':condominium_id' => $condominiumId]);
            return $stmt->fetchAll() ?: [];
        } else {
            // Get direct subfolders of parent folder (only immediate children, not grandchildren)
            // Example: if parent is "Contratos", get "Contratos/2026" but not "Contratos/2026/01"
            // We extract the immediate subfolder by taking parent + '/' + first segment after parent
            $parentPattern = $parentFolder . '/%';
            $parentLength = strlen($parentFolder);
            $sql = "
                SELECT DISTINCT 
                    CONCAT(:parent_folder, '/', SUBSTRING_INDEX(SUBSTRING(folder, :parent_length_plus_2), '/', 1)) as folder,
                    SUM(CASE WHEN (document_type != 'folder_placeholder' OR document_type IS NULL) 
                              AND (title != '.folder_placeholder' OR title IS NULL)
                         THEN 1 ELSE 0 END) as document_count
                FROM documents
                WHERE condominium_id = :condominium_id 
                AND folder IS NOT NULL
                AND folder LIKE :parent_pattern 
                AND folder != :parent_folder
                GROUP BY CONCAT(:parent_folder, '/', SUBSTRING_INDEX(SUBSTRING(folder, :parent_length_plus_2), '/', 1))
                ORDER BY folder ASC
            ";
            $params = [
                ':condominium_id' => $condominiumId,
                ':parent_pattern' => $parentPattern,
                ':parent_folder' => $parentFolder,
                ':parent_length_plus_2' => $parentLength + 2
            ];
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        }
    }

    /**
     * Get all folders organized hierarchically
     */
    public function getFoldersHierarchical(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT folder, COUNT(*) as document_count
            FROM documents
            WHERE condominium_id = :condominium_id AND folder IS NOT NULL
            GROUP BY folder
            ORDER BY folder ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        $allFolders = $stmt->fetchAll() ?: [];

        // Organize hierarchically
        $hierarchical = [];
        foreach ($allFolders as $folder) {
            $parts = explode('/', $folder['folder']);
            $current = &$hierarchical;
            
            foreach ($parts as $index => $part) {
                $path = implode('/', array_slice($parts, 0, $index + 1));
                
                if (!isset($current[$path])) {
                    $current[$path] = [
                        'folder' => $path,
                        'name' => $part,
                        'level' => $index,
                        'document_count' => 0,
                        'children' => []
                    ];
                }
                
                // If this is the final part, set document count
                if ($index === count($parts) - 1) {
                    $current[$path]['document_count'] = $folder['document_count'];
                }
                
                $current = &$current[$path]['children'];
            }
        }

        return $hierarchical;
    }

    /**
     * Get parent folder path
     */
    public function getParentFolder(string $folderPath): ?string
    {
        $parts = explode('/', $folderPath);
        if (count($parts) <= 1) {
            return null;
        }
        array_pop($parts);
        return implode('/', $parts);
    }

    /**
     * Get folder name (last part of path)
     */
    public function getFolderName(string $folderPath): string
    {
        $parts = explode('/', $folderPath);
        return end($parts);
    }

    /**
     * Get next version number for a document
     */
    public function getNextVersion(int $parentDocumentId): int
    {
        if (!$this->db) {
            return 1;
        }

        $stmt = $this->db->prepare("
            SELECT MAX(version) as max_version
            FROM documents
            WHERE id = :parent_id OR parent_document_id = :parent_id
        ");

        $stmt->execute([':parent_id' => $parentDocumentId]);
        $result = $stmt->fetch();
        
        return ($result['max_version'] ?? 0) + 1;
    }

    /**
     * Create document
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $version = $data['version'] ?? 1;
        $parentDocumentId = $data['parent_document_id'] ?? null;

        // If creating a new version, calculate the next version number
        if ($parentDocumentId) {
            $version = $this->getNextVersion($parentDocumentId);
        }

        // Check if assembly_id and status columns exist
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'assembly_id'");
        $hasAssemblyId = $stmt->rowCount() > 0;
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'status'");
        $hasStatus = $stmt->rowCount() > 0;
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'review_deadline'");
        $hasReview = $stmt->rowCount() > 0;

        if ($hasAssemblyId && $hasStatus) {
            $cols = "condominium_id, assembly_id, fraction_id, folder, title, description, file_path, file_name, file_size, mime_type, visibility, document_type, status, version, parent_document_id, uploaded_by";
            $vals = ":condominium_id, :assembly_id, :fraction_id, :folder, :title, :description, :file_path, :file_name, :file_size, :mime_type, :visibility, :document_type, :status, :version, :parent_document_id, :uploaded_by";
            $exec = [
                ':condominium_id' => $data['condominium_id'],
                ':assembly_id' => $data['assembly_id'] ?? null,
                ':fraction_id' => $data['fraction_id'] ?? null,
                ':folder' => $data['folder'] ?? null,
                ':title' => $data['title'],
                ':description' => $data['description'] ?? null,
                ':file_path' => $data['file_path'],
                ':file_name' => $data['file_name'],
                ':file_size' => $data['file_size'] ?? null,
                ':mime_type' => $data['mime_type'] ?? null,
                ':visibility' => $data['visibility'] ?? 'condominos',
                ':document_type' => $data['document_type'] ?? null,
                ':status' => $data['status'] ?? 'draft',
                ':version' => $version,
                ':parent_document_id' => $parentDocumentId,
                ':uploaded_by' => $data['uploaded_by']
            ];
            if ($hasReview) {
                $cols .= ", review_deadline, review_sent_at";
                $vals .= ", :review_deadline, :review_sent_at";
                $exec[':review_deadline'] = $data['review_deadline'] ?? null;
                $exec[':review_sent_at'] = $data['review_sent_at'] ?? null;
            }
            $stmt = $this->db->prepare("INSERT INTO documents ({$cols}) VALUES ({$vals})");
            $stmt->execute($exec);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO documents (
                    condominium_id, fraction_id, folder, title, description,
                    file_path, file_name, file_size, mime_type, visibility,
                    document_type, version, parent_document_id, uploaded_by
                )
                VALUES (
                    :condominium_id, :fraction_id, :folder, :title, :description,
                    :file_path, :file_name, :file_size, :mime_type, :visibility,
                    :document_type, :version, :parent_document_id, :uploaded_by
                )
            ");

            $stmt->execute([
                ':condominium_id' => $data['condominium_id'],
                ':fraction_id' => $data['fraction_id'] ?? null,
                ':folder' => $data['folder'] ?? null,
                ':title' => $data['title'],
                ':description' => $data['description'] ?? null,
                ':file_path' => $data['file_path'],
                ':file_name' => $data['file_name'],
                ':file_size' => $data['file_size'] ?? null,
                ':mime_type' => $data['mime_type'] ?? null,
                ':visibility' => $data['visibility'] ?? 'condominos',
                ':document_type' => $data['document_type'] ?? null,
                ':version' => $version,
                ':parent_document_id' => $parentDocumentId,
                ':uploaded_by' => $data['uploaded_by']
            ]);
        }

        $documentId = (int)$this->db->lastInsertId();
        
        // Log audit
        $this->auditCreate($documentId, $data);
        
        return $documentId;
    }

    /**
     * Get documents by assembly ID
     */
    public function getByAssemblyId(int $assemblyId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT d.*, u.name as uploaded_by_name, f.identifier as fraction_identifier
                FROM documents d
                LEFT JOIN users u ON u.id = d.uploaded_by
                LEFT JOIN fractions f ON f.id = d.fraction_id
                WHERE d.assembly_id = :assembly_id";

        $params = [':assembly_id' => $assemblyId];

        if (isset($filters['document_type'])) {
            $sql .= " AND d.document_type = :document_type";
            $params[':document_type'] = $filters['document_type'];
        }

        if (isset($filters['status'])) {
            $sql .= " AND d.status = :status";
            $params[':status'] = $filters['status'];
        }

        $sql .= " ORDER BY d.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find document by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM documents WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update document metadata
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        // Check if status and review columns exist
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'status'");
        $hasStatus = $stmt->rowCount() > 0;
        $stmt = $this->db->query("SHOW COLUMNS FROM documents LIKE 'review_deadline'");
        $hasReview = $stmt->rowCount() > 0;

        $allowedFields = ['title', 'description', 'folder', 'document_type', 'visibility', 'fraction_id'];
        if ($hasStatus) {
            $allowedFields[] = 'status';
        }
        if ($hasReview) {
            $allowedFields[] = 'review_deadline';
            $allowedFields[] = 'review_sent_at';
        }
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        // Get old data for audit
        $oldData = $this->findById($id);

        $sql = "UPDATE documents SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        $result = $stmt->execute($params);
        
        // Log audit
        if ($result) {
            $this->auditUpdate($id, $data, $oldData);
        }
        
        return $result;
    }

    /**
     * Get document versions
     */
    public function getVersions(int $documentId): array
    {
        if (!$this->db) {
            return [];
        }

        // Get parent document ID (if this is a version, get its parent)
        $document = $this->findById($documentId);
        if (!$document) {
            return [];
        }

        $parentId = $document['parent_document_id'] ?? $documentId;

        $stmt = $this->db->prepare("
            SELECT d.*, u.name as uploaded_by_name
            FROM documents d
            LEFT JOIN users u ON u.id = d.uploaded_by
            WHERE d.id = :parent_id OR d.parent_document_id = :parent_id
            ORDER BY d.version ASC, d.created_at DESC
        ");

        $stmt->execute([':parent_id' => $parentId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Search documents
     * @param int $condominiumId
     * @param string $query
     * @param array $filters - Can include 'user_fraction_ids' array and 'is_admin' boolean for access control
     */
    public function search(int $condominiumId, string $query, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT d.*, u.name as uploaded_by_name, f.identifier as fraction_identifier
                FROM documents d
                LEFT JOIN users u ON u.id = d.uploaded_by
                LEFT JOIN fractions f ON f.id = d.fraction_id
                WHERE d.condominium_id = :condominium_id
                AND (d.title LIKE :query OR d.description LIKE :query)";

        $params = [
            ':condominium_id' => $condominiumId,
            ':query' => '%' . $query . '%'
        ];

        // Access control: filter documents based on visibility and user's fractions
        $isAdmin = $filters['is_admin'] ?? false;
        $userFractionIds = $filters['user_fraction_ids'] ?? [];
        
        if (!$isAdmin && !empty($userFractionIds)) {
            // Non-admin users can only see:
            // 1. Documents with visibility = 'condominos' (public to all condominos)
            // 2. Documents with visibility = 'fraction' AND fraction_id IN (user's fractions)
            $fractionPlaceholders = [];
            foreach ($userFractionIds as $index => $fractionId) {
                $key = ':search_fraction_id_' . $index;
                $fractionPlaceholders[] = $key;
                $params[$key] = $fractionId;
            }
            $fractionPlaceholdersStr = implode(',', $fractionPlaceholders);
            
            $sql .= " AND (
                (d.visibility = 'condominos')
                OR (d.visibility = 'fraction' AND d.fraction_id IS NOT NULL AND d.fraction_id IN ($fractionPlaceholdersStr))
            )";
        } elseif (!$isAdmin) {
            // User has no fractions in this condominium - only show public documents
            $sql .= " AND d.visibility = 'condominos'";
        }
        // If isAdmin is true, show all documents (no additional filter)

        if (isset($filters['folder'])) {
            if ($filters['folder'] === null) {
                $sql .= " AND d.folder IS NULL";
            } else {
                $sql .= " AND d.folder = :folder";
                $params[':folder'] = $filters['folder'];
            }
        }

        if (isset($filters['document_type'])) {
            $sql .= " AND d.document_type = :document_type";
            $params[':document_type'] = $filters['document_type'];
        }

        if (isset($filters['visibility'])) {
            $sql .= " AND d.visibility = :visibility";
            $params[':visibility'] = $filters['visibility'];
        }

        $sql .= " ORDER BY d.created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Rename folder
     */
    public function renameFolder(int $condominiumId, string $oldFolderName, string $newFolderName): bool
    {
        if (!$this->db) {
            return false;
        }

        // Count affected documents
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM documents 
            WHERE condominium_id = :condominium_id AND folder = :old_folder
        ");
        $countStmt->execute([
            ':condominium_id' => $condominiumId,
            ':old_folder' => $oldFolderName
        ]);
        $countResult = $countStmt->fetch();
        $affectedCount = (int)($countResult['count'] ?? 0);

        $stmt = $this->db->prepare("
            UPDATE documents
            SET folder = :new_folder
            WHERE condominium_id = :condominium_id AND folder = :old_folder
        ");

        $result = $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':old_folder' => $oldFolderName,
            ':new_folder' => $newFolderName
        ]);
        
        // Log audit for folder rename operation
        if ($result) {
            $this->auditCustom(
                'folder_rename',
                "Pasta renomeada de '{$oldFolderName}' para '{$newFolderName}' ({$affectedCount} documentos afetados)",
                [
                    'condominium_id' => $condominiumId,
                    'old_folder' => $oldFolderName,
                    'new_folder' => $newFolderName,
                    'affected_documents_count' => $affectedCount
                ],
                'documents'
            );
        }
        
        return $result;
    }

    /**
     * Delete folder (moves documents to root)
     */
    public function deleteFolder(int $condominiumId, string $folderName): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE documents
            SET folder = NULL
            WHERE condominium_id = :condominium_id AND folder = :folder
        ");

        return $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':folder' => $folderName
        ]);
    }

    /**
     * Move document to different folder
     */
    public function moveToFolder(int $documentId, string $folderName): bool
    {
        if (!$this->db) {
            return false;
        }

        // Get old data for audit
        $document = $this->findById($documentId);
        if (!$document) {
            return false;
        }
        
        $oldFolder = $document['folder'] ?? null;
        $newFolder = $folderName ?: null;

        $stmt = $this->db->prepare("
            UPDATE documents
            SET folder = :folder
            WHERE id = :id
        ");

        $result = $stmt->execute([
            ':id' => $documentId,
            ':folder' => $newFolder
        ]);
        
        // Log audit for document move operation
        if ($result) {
            $this->auditCustom(
                'document_move',
                "Documento #{$documentId} movido de '{$oldFolder}' para '{$newFolder}'",
                [
                    'document_id' => $documentId,
                    'old_folder' => $oldFolder,
                    'new_folder' => $newFolder,
                    'document_title' => $document['title'] ?? null
                ],
                'documents',
                $documentId
            );
        }
        
        return $result;
    }

    /**
     * Delete document
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $document = $this->findById($id);
        if (!$document) {
            return false;
        }

        // Get old data for audit before deletion
        $oldData = $document;

        // Delete file from storage
        $filePath = __DIR__ . '/../../storage/documents/' . $document['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete from database
        $stmt = $this->db->prepare("DELETE FROM documents WHERE id = :id");
        $result = $stmt->execute([':id' => $id]);
        
        // Log audit
        if ($result && $oldData) {
            $this->auditDelete($id, $oldData);
        }
        
        return $result;
    }
}





