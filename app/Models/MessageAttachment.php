<?php

namespace App\Models;

use App\Core\Model;

class MessageAttachment extends Model
{
    protected $table = 'message_attachments';

    /**
     * Create attachment
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO message_attachments (
                message_id, condominium_id, file_path, file_name, file_size, mime_type, uploaded_by
            )
            VALUES (
                :message_id, :condominium_id, :file_path, :file_name, :file_size, :mime_type, :uploaded_by
            )
        ");

        $stmt->execute([
            ':message_id' => $data['message_id'],
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
     * Get attachments by message
     */
    public function getByMessage(int $messageId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT ma.*, u.name as uploaded_by_name
            FROM message_attachments ma
            LEFT JOIN users u ON u.id = ma.uploaded_by
            WHERE ma.message_id = :message_id
            ORDER BY ma.created_at ASC
        ");
        $stmt->execute([':message_id' => $messageId]);
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

        $stmt = $this->db->prepare("SELECT * FROM message_attachments WHERE id = :id LIMIT 1");
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
        $stmt = $this->db->prepare("DELETE FROM message_attachments WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
