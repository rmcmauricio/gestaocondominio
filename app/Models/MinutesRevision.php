<?php

namespace App\Models;

use App\Core\Model;

class MinutesRevision extends Model
{
    protected $table = 'minutes_revisions';

    /**
     * Create or update a revision for a document and fraction (upsert by document_id, fraction_id).
     */
    public function createOrUpdate(int $documentId, int $assemblyId, int $fractionId, int $userId, bool $accepted, ?string $comment): bool
    {
        if (!$this->db) {
            return false;
        }
        $stmt = $this->db->prepare("
            SELECT id FROM minutes_revisions
            WHERE document_id = :document_id AND fraction_id = :fraction_id
            LIMIT 1
        ");
        $stmt->execute([':document_id' => $documentId, ':fraction_id' => $fractionId]);
        $existing = $stmt->fetch();
        $acceptedInt = $accepted ? 1 : 0;
        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE minutes_revisions
                SET user_id = :user_id, accepted = :accepted, comment = :comment, updated_at = NOW()
                WHERE document_id = :document_id AND fraction_id = :fraction_id
            ");
            return $stmt->execute([
                ':user_id' => $userId,
                ':accepted' => $acceptedInt,
                ':comment' => $comment,
                ':document_id' => $documentId,
                ':fraction_id' => $fractionId
            ]);
        }
        $stmt = $this->db->prepare("
            INSERT INTO minutes_revisions (document_id, assembly_id, fraction_id, user_id, accepted, comment)
            VALUES (:document_id, :assembly_id, :fraction_id, :user_id, :accepted, :comment)
        ");
        return $stmt->execute([
            ':document_id' => $documentId,
            ':assembly_id' => $assemblyId,
            ':fraction_id' => $fractionId,
            ':user_id' => $userId,
            ':accepted' => $acceptedInt,
            ':comment' => $comment
        ]);
    }

    /**
     * Get all revisions for a document.
     */
    public function getByDocument(int $documentId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT mr.*, f.identifier as fraction_identifier, u.name as user_name
            FROM minutes_revisions mr
            LEFT JOIN fractions f ON f.id = mr.fraction_id
            LEFT JOIN users u ON u.id = mr.user_id
            WHERE mr.document_id = :document_id
            ORDER BY f.identifier ASC
        ");
        $stmt->execute([':document_id' => $documentId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get one revision by document and fraction.
     */
    public function getByDocumentAndFraction(int $documentId, int $fractionId): ?array
    {
        if (!$this->db) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT mr.*, f.identifier as fraction_identifier, u.name as user_name
            FROM minutes_revisions mr
            LEFT JOIN fractions f ON f.id = mr.fraction_id
            LEFT JOIN users u ON u.id = mr.user_id
            WHERE mr.document_id = :document_id AND mr.fraction_id = :fraction_id
            LIMIT 1
        ");
        $stmt->execute([':document_id' => $documentId, ':fraction_id' => $fractionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get stats: total (present fractions), accepted, commented, pending.
     */
    public function getStats(int $documentId, int $assemblyId): array
    {
        if (!$this->db) {
            return ['total' => 0, 'accepted' => 0, 'commented' => 0, 'pending' => 0];
        }
        $presentIds = $this->getPresentFractionIds($assemblyId);
        $total = count($presentIds);
        if ($total === 0) {
            return ['total' => 0, 'accepted' => 0, 'commented' => 0, 'pending' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($presentIds), '?'));
        $stmt = $this->db->prepare("
            SELECT accepted, comment
            FROM minutes_revisions
            WHERE document_id = ? AND fraction_id IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$documentId], $presentIds));
        $rows = $stmt->fetchAll() ?: [];
        $accepted = 0;
        $commented = 0;
        foreach ($rows as $r) {
            if (!empty($r['accepted'])) {
                $accepted++;
            }
            if (!empty(trim((string)($r['comment'] ?? '')))) {
                $commented++;
            }
        }
        $responded = count($rows);
        $pending = $total - $responded;
        return [
            'total' => $total,
            'accepted' => $accepted,
            'commented' => $commented,
            'pending' => $pending
        ];
    }

    /**
     * Get fraction IDs present at the assembly (present or proxy).
     */
    public function getPresentFractionIds(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT DISTINCT fraction_id
            FROM assembly_attendees
            WHERE assembly_id = :assembly_id
            AND attendance_type IN ('present', 'proxy')
        ");
        $stmt->execute([':assembly_id' => $assemblyId]);
        $results = $stmt->fetchAll() ?: [];
        return array_column($results, 'fraction_id');
    }
}
