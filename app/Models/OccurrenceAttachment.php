<?php

namespace App\Models;

use App\Core\Model;

class OccurrenceAttachment extends Model
{
    protected $table = 'occurrence_attachments';

    /**
     * Create attachment
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO occurrence_attachments (
                occurrence_id, condominium_id, file_path, file_name, file_size, mime_type, uploaded_by
            )
            VALUES (
                :occurrence_id, :condominium_id, :file_path, :file_name, :file_size, :mime_type, :uploaded_by
            )
        ");

        $stmt->execute([
            ':occurrence_id' => $data['occurrence_id'],
            ':condominium_id' => $data['condominium_id'],
            ':file_path' => $data['file_path'],
            ':file_name' => $data['file_name'],
            ':file_size' => $data['file_size'],
            ':mime_type' => $data['mime_type'] ?? null,
            ':uploaded_by' => $data['uploaded_by']
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get attachments by occurrence
     */
    public function getByOccurrence(int $occurrenceId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT oa.*, u.name as uploaded_by_name
            FROM occurrence_attachments oa
            LEFT JOIN users u ON u.id = oa.uploaded_by
            WHERE oa.occurrence_id = :occurrence_id
            ORDER BY oa.created_at ASC
        ");
        $stmt->execute([':occurrence_id' => $occurrenceId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find attachment by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM occurrence_attachments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Delete attachment
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $attachment = $this->findById($id);
        if (!$attachment) {
            return false;
        }

        // Delete file from storage
        $fileStorageService = new \App\Services\FileStorageService();
        // file_path already contains the full relative path from storage root
        try {
            $fileStorageService->delete($attachment['file_path']);
        } catch (\Exception $e) {
            // Log error but continue with database deletion
            error_log("Error deleting attachment file: " . $e->getMessage());
        }

        // Delete from database
        $stmt = $this->db->prepare("DELETE FROM occurrence_attachments WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
