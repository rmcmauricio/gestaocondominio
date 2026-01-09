<?php

namespace App\Models;

use App\Core\Model;

class AssemblyAttendee extends Model
{
    protected $table = 'assembly_attendees';

    /**
     * Get attendees by assembly
     */
    public function getByAssembly(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT aa.*, u.name as user_name, f.identifier as fraction_identifier,
                   f.millage as fraction_millage
            FROM assembly_attendees aa
            INNER JOIN users u ON u.id = aa.user_id
            INNER JOIN fractions f ON f.id = aa.fraction_id
            WHERE aa.assembly_id = :assembly_id
            ORDER BY aa.attendance_type DESC, f.identifier ASC
        ");

        $stmt->execute([':assembly_id' => $assemblyId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Register attendance
     */
    public function register(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check if already registered
        $stmt = $this->db->prepare("
            SELECT id FROM assembly_attendees 
            WHERE assembly_id = :assembly_id AND fraction_id = :fraction_id
        ");
        $stmt->execute([
            ':assembly_id' => $data['assembly_id'],
            ':fraction_id' => $data['fraction_id']
        ]);

        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing
            $stmt = $this->db->prepare("
                UPDATE assembly_attendees 
                SET user_id = :user_id, attendance_type = :attendance_type,
                    proxy_user_id = :proxy_user_id, notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $existing['id'],
                ':user_id' => $data['user_id'],
                ':attendance_type' => $data['attendance_type'],
                ':proxy_user_id' => $data['proxy_user_id'] ?? null,
                ':notes' => $data['notes'] ?? null
            ]);
            return (int)$existing['id'];
        }

        // Create new
        $stmt = $this->db->prepare("
            INSERT INTO assembly_attendees (
                assembly_id, fraction_id, user_id, attendance_type,
                proxy_user_id, notes
            )
            VALUES (
                :assembly_id, :fraction_id, :user_id, :attendance_type,
                :proxy_user_id, :notes
            )
        ");

        $stmt->execute([
            ':assembly_id' => $data['assembly_id'],
            ':fraction_id' => $data['fraction_id'],
            ':user_id' => $data['user_id'],
            ':attendance_type' => $data['attendance_type'],
            ':proxy_user_id' => $data['proxy_user_id'] ?? null,
            ':notes' => $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Calculate quorum
     */
    public function calculateQuorum(int $assemblyId, int $condominiumId): array
    {
        if (!$this->db) {
            return ['percentage' => 0, 'total_millage' => 0, 'attended_millage' => 0];
        }

        // Get total millage of condominium
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(millage), 0) as total_millage
            FROM fractions
            WHERE condominium_id = :condominium_id AND is_active = TRUE
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $total = $stmt->fetch();
        $totalMillage = (float)($total['total_millage'] ?? 0);

        // Get attended millage
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(f.millage), 0) as attended_millage
            FROM assembly_attendees aa
            INNER JOIN fractions f ON f.id = aa.fraction_id
            WHERE aa.assembly_id = :assembly_id
        ");
        $stmt->execute([':assembly_id' => $assemblyId]);
        $attended = $stmt->fetch();
        $attendedMillage = (float)($attended['attended_millage'] ?? 0);

        $percentage = $totalMillage > 0 ? ($attendedMillage / $totalMillage * 100) : 0;

        return [
            'percentage' => round($percentage, 2),
            'total_millage' => $totalMillage,
            'attended_millage' => $attendedMillage
        ];
    }
}





