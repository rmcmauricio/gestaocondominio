<?php

namespace App\Models;

use App\Core\Model;

class AssemblyAccountApproval extends Model
{
    protected $table = 'assembly_account_approvals';

    /**
     * Get all approvals by condominium
     */
    public function getByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT aaa.*, a.title as assembly_title, u.name as approved_by_name
            FROM assembly_account_approvals aaa
            LEFT JOIN assemblies a ON a.id = aaa.assembly_id
            LEFT JOIN users u ON u.id = aaa.approved_by
            WHERE aaa.condominium_id = :condominium_id
            ORDER BY aaa.approved_year DESC, aaa.approved_at DESC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get approval by year for a condominium
     */
    public function getByYear(int $condominiumId, int $year): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT aaa.*, a.title as assembly_title, u.name as approved_by_name
            FROM assembly_account_approvals aaa
            LEFT JOIN assemblies a ON a.id = aaa.assembly_id
            LEFT JOIN users u ON u.id = aaa.approved_by
            WHERE aaa.condominium_id = :condominium_id
            AND aaa.approved_year = :year
            LIMIT 1
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':year' => $year
        ]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Check if a year has been approved for a condominium
     */
    public function hasApprovedYear(int $condominiumId, int $year): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM assembly_account_approvals
            WHERE condominium_id = :condominium_id
            AND approved_year = :year
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':year' => $year
        ]);

        $result = $stmt->fetch();
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Create approval
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO assembly_account_approvals (
                assembly_id, condominium_id, approved_year, approved_at, approved_by, notes
            )
            VALUES (
                :assembly_id, :condominium_id, :approved_year, :approved_at, :approved_by, :notes
            )
        ");

        $stmt->execute([
            ':assembly_id' => $data['assembly_id'],
            ':condominium_id' => $data['condominium_id'],
            ':approved_year' => $data['approved_year'],
            ':approved_at' => $data['approved_at'] ?? date('Y-m-d H:i:s'),
            ':approved_by' => $data['approved_by'],
            ':notes' => $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Find approval by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT aaa.*, a.title as assembly_title, u.name as approved_by_name
            FROM assembly_account_approvals aaa
            LEFT JOIN assemblies a ON a.id = aaa.assembly_id
            LEFT JOIN users u ON u.id = aaa.approved_by
            WHERE aaa.id = :id
            LIMIT 1
        ");

        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
