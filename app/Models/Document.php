<?php

namespace App\Models;

use App\Core\Model;

class Document extends Model
{
    protected $table = 'documents';

    /**
     * Get documents by condominium
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

        if (isset($filters['folder'])) {
            $sql .= " AND d.folder = :folder";
            $params[':folder'] = $filters['folder'];
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
     * Get folders for condominium
     */
    public function getFolders(int $condominiumId): array
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
        return $stmt->fetchAll() ?: [];
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

        return (int)$this->db->lastInsertId();
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

        $allowedFields = ['title', 'description', 'folder', 'document_type', 'visibility', 'fraction_id'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE documents SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
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

        if (isset($filters['folder'])) {
            $sql .= " AND d.folder = :folder";
            $params[':folder'] = $filters['folder'];
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

        $stmt = $this->db->prepare("
            UPDATE documents
            SET folder = :new_folder
            WHERE condominium_id = :condominium_id AND folder = :old_folder
        ");

        return $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':old_folder' => $oldFolderName,
            ':new_folder' => $newFolderName
        ]);
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

        $stmt = $this->db->prepare("
            UPDATE documents
            SET folder = :folder
            WHERE id = :id
        ");

        return $stmt->execute([
            ':id' => $documentId,
            ':folder' => $folderName ?: null
        ]);
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

        // Delete file from storage
        $filePath = __DIR__ . '/../../storage/documents/' . $document['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete from database
        $stmt = $this->db->prepare("DELETE FROM documents WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}





