<?php

namespace App\Models;

use App\Core\Model;

class MinutesSignature extends Model
{
    protected $table = 'minutes_signatures';

    /**
     * Get signatures for a document
     */
    public function getByDocument(int $documentId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT ms.*, u.name as user_name, u.email as user_email, 
                   f.identifier as fraction_identifier
            FROM minutes_signatures ms
            LEFT JOIN users u ON u.id = ms.user_id
            LEFT JOIN fractions f ON f.id = ms.fraction_id
            WHERE ms.document_id = :document_id AND ms.is_valid = TRUE
            ORDER BY ms.signed_at ASC
        ");
        
        $stmt->execute([':document_id' => $documentId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get signatures for an assembly
     */
    public function getByAssembly(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT ms.*, u.name as user_name, u.email as user_email, 
                   f.identifier as fraction_identifier, d.id as document_id
            FROM minutes_signatures ms
            LEFT JOIN users u ON u.id = ms.user_id
            LEFT JOIN fractions f ON f.id = ms.fraction_id
            LEFT JOIN documents d ON d.id = ms.document_id
            WHERE ms.assembly_id = :assembly_id AND ms.is_valid = TRUE
            ORDER BY ms.signed_at ASC
        ");
        
        $stmt->execute([':assembly_id' => $assemblyId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Check if user has signed
     */
    public function hasUserSigned(int $documentId, int $userId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM minutes_signatures
            WHERE document_id = :document_id 
            AND user_id = :user_id 
            AND is_valid = TRUE
        ");
        
        $stmt->execute([
            ':document_id' => $documentId,
            ':user_id' => $userId
        ]);
        
        $result = $stmt->fetch();
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Check if fraction has signed
     */
    public function hasFractionSigned(int $documentId, int $fractionId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM minutes_signatures
            WHERE document_id = :document_id 
            AND fraction_id = :fraction_id 
            AND is_valid = TRUE
        ");
        
        $stmt->execute([
            ':document_id' => $documentId,
            ':fraction_id' => $fractionId
        ]);
        
        $result = $stmt->fetch();
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Create signature
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check if there's already a valid signature for this document and fraction
        $existing = $this->hasFractionSigned($data['document_id'], $data['fraction_id']);
        
        if ($existing) {
            // If exists, invalidate it first
            $this->invalidateSignatures($data['document_id'], $data['fraction_id']);
        }

        $stmt = $this->db->prepare("
            INSERT INTO minutes_signatures (
                document_id, assembly_id, user_id, fraction_id,
                signature_type, signature_data
            )
            VALUES (
                :document_id, :assembly_id, :user_id, :fraction_id,
                :signature_type, :signature_data
            )
        ");

        $stmt->execute([
            ':document_id' => $data['document_id'],
            ':assembly_id' => $data['assembly_id'],
            ':user_id' => $data['user_id'],
            ':fraction_id' => $data['fraction_id'],
            ':signature_type' => $data['signature_type'] ?? 'digital',
            ':signature_data' => $data['signature_data'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Invalidate signatures for a document and fraction
     */
    public function invalidateSignatures(int $documentId, int $fractionId, string $reason = 'Document edited'): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE minutes_signatures
            SET is_valid = FALSE,
                invalidated_at = NOW(),
                invalidation_reason = :reason
            WHERE document_id = :document_id 
            AND fraction_id = :fraction_id
            AND is_valid = TRUE
        ");

        return $stmt->execute([
            ':document_id' => $documentId,
            ':fraction_id' => $fractionId,
            ':reason' => $reason
        ]);
    }

    /**
     * Invalidate all signatures for a document (when edited)
     */
    public function invalidateAllSignatures(int $documentId, string $reason = 'Document edited'): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE minutes_signatures
            SET is_valid = FALSE,
                invalidated_at = NOW(),
                invalidation_reason = :reason
            WHERE document_id = :document_id 
            AND is_valid = TRUE
        ");

        return $stmt->execute([
            ':document_id' => $documentId,
            ':reason' => $reason
        ]);
    }

    /**
     * Get all present fractions that haven't signed yet
     */
    public function getUnsignedFractions(int $documentId, int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT aa.fraction_id, f.identifier as fraction_identifier,
                   f.permillage, u.name as user_name, u.id as user_id
            FROM assembly_attendees aa
            INNER JOIN fractions f ON f.id = aa.fraction_id
            LEFT JOIN condominium_users cu ON cu.fraction_id = f.id 
                AND cu.condominium_id = (SELECT condominium_id FROM assemblies WHERE id = :assembly_id)
                AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            LEFT JOIN users u ON u.id = cu.user_id
            LEFT JOIN minutes_signatures ms ON ms.fraction_id = aa.fraction_id 
                AND ms.document_id = :document_id 
                AND ms.is_valid = TRUE
            WHERE aa.assembly_id = :assembly_id
            AND (aa.attendance_type = 'present' OR aa.attendance_type = 'proxy')
            AND ms.id IS NULL
            ORDER BY f.identifier ASC
        ");
        
        $stmt->execute([
            ':document_id' => $documentId,
            ':assembly_id' => $assemblyId
        ]);
        
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Check if all present fractions have signed
     */
    public function allPresentFractionsSigned(int $documentId, int $assemblyId): bool
    {
        $unsigned = $this->getUnsignedFractions($documentId, $assemblyId);
        return empty($unsigned);
    }

    /**
     * Get signature statistics for a document
     */
    public function getSignatureStats(int $documentId, int $assemblyId): array
    {
        if (!$this->db) {
            return ['total' => 0, 'signed' => 0, 'pending' => 0];
        }

        // Get total present fractions
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT aa.fraction_id) as total
            FROM assembly_attendees aa
            WHERE aa.assembly_id = :assembly_id
            AND (aa.attendance_type = 'present' OR aa.attendance_type = 'proxy')
        ");
        $stmt->execute([':assembly_id' => $assemblyId]);
        $total = $stmt->fetch();
        $totalCount = (int)($total['total'] ?? 0);

        // Get signed count
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT ms.fraction_id) as signed
            FROM minutes_signatures ms
            WHERE ms.document_id = :document_id
            AND ms.assembly_id = :assembly_id
            AND ms.is_valid = TRUE
        ");
        $stmt->execute([
            ':document_id' => $documentId,
            ':assembly_id' => $assemblyId
        ]);
        $signed = $stmt->fetch();
        $signedCount = (int)($signed['signed'] ?? 0);

        return [
            'total' => $totalCount,
            'signed' => $signedCount,
            'pending' => $totalCount - $signedCount
        ];
    }
}
