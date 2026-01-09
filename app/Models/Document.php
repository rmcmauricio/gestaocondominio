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

        $sql .= " ORDER BY d.created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
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
     * Create document
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO documents (
                condominium_id, fraction_id, folder, title, description,
                file_path, file_name, file_size, mime_type, visibility,
                document_type, uploaded_by
            )
            VALUES (
                :condominium_id, :fraction_id, :folder, :title, :description,
                :file_path, :file_name, :file_size, :mime_type, :visibility,
                :document_type, :uploaded_by
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





