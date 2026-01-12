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
                   f.permillage as fraction_millage
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

        // Check if notes column exists
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_attendees LIKE 'notes'");
        $hasNotes = $stmt->rowCount() > 0;

        if ($existing) {
            // Update existing
            if ($hasNotes) {
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
            } else {
                $stmt = $this->db->prepare("
                    UPDATE assembly_attendees 
                    SET user_id = :user_id, attendance_type = :attendance_type,
                        proxy_user_id = :proxy_user_id
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $existing['id'],
                    ':user_id' => $data['user_id'],
                    ':attendance_type' => $data['attendance_type'],
                    ':proxy_user_id' => $data['proxy_user_id'] ?? null
                ]);
            }
            return (int)$existing['id'];
        }

        // Create new
        if ($hasNotes) {
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
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO assembly_attendees (
                    assembly_id, fraction_id, user_id, attendance_type,
                    proxy_user_id
                )
                VALUES (
                    :assembly_id, :fraction_id, :user_id, :attendance_type,
                    :proxy_user_id
                )
            ");

            $stmt->execute([
                ':assembly_id' => $data['assembly_id'],
                ':fraction_id' => $data['fraction_id'],
                ':user_id' => $data['user_id'],
                ':attendance_type' => $data['attendance_type'],
                ':proxy_user_id' => $data['proxy_user_id'] ?? null
            ]);
        }

        return (int)$this->db->lastInsertId();
    }

    /**
     * Register multiple attendances (bulk)
     */
    public function registerBulk(int $assemblyId, array $attendances): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $registered = 0;
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_attendees LIKE 'notes'");
        $hasNotes = $stmt->rowCount() > 0;

        foreach ($attendances as $attendance) {
            $fractionId = (int)$attendance['fraction_id'];
            $attendanceType = $attendance['attendance_type'] ?? 'absent';
            
            // Skip if absent
            if ($attendanceType === 'absent') {
                // Remove if exists
                $stmt = $this->db->prepare("DELETE FROM assembly_attendees WHERE assembly_id = :assembly_id AND fraction_id = :fraction_id");
                $stmt->execute([':assembly_id' => $assemblyId, ':fraction_id' => $fractionId]);
                continue;
            }

            // Check if already registered
            $stmt = $this->db->prepare("SELECT id FROM assembly_attendees WHERE assembly_id = :assembly_id AND fraction_id = :fraction_id");
            $stmt->execute([':assembly_id' => $assemblyId, ':fraction_id' => $fractionId]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update existing
                if ($hasNotes) {
                    $stmt = $this->db->prepare("
                        UPDATE assembly_attendees 
                        SET user_id = :user_id, attendance_type = :attendance_type,
                            proxy_user_id = :proxy_user_id, notes = :notes
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $existing['id'],
                        ':user_id' => $attendance['user_id'] ?? null,
                        ':attendance_type' => $attendanceType,
                        ':proxy_user_id' => $attendance['proxy_user_id'] ?? null,
                        ':notes' => $attendance['notes'] ?? null
                    ]);
                } else {
                    $stmt = $this->db->prepare("
                        UPDATE assembly_attendees 
                        SET user_id = :user_id, attendance_type = :attendance_type,
                            proxy_user_id = :proxy_user_id
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $existing['id'],
                        ':user_id' => $attendance['user_id'] ?? null,
                        ':attendance_type' => $attendanceType,
                        ':proxy_user_id' => $attendance['proxy_user_id'] ?? null
                    ]);
                }
            } else {
                // Create new
                if ($hasNotes) {
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
                        ':assembly_id' => $assemblyId,
                        ':fraction_id' => $fractionId,
                        ':user_id' => $attendance['user_id'] ?? null,
                        ':attendance_type' => $attendanceType,
                        ':proxy_user_id' => $attendance['proxy_user_id'] ?? null,
                        ':notes' => $attendance['notes'] ?? null
                    ]);
                } else {
                    $stmt = $this->db->prepare("
                        INSERT INTO assembly_attendees (
                            assembly_id, fraction_id, user_id, attendance_type,
                            proxy_user_id
                        )
                        VALUES (
                            :assembly_id, :fraction_id, :user_id, :attendance_type,
                            :proxy_user_id
                        )
                    ");
                    $stmt->execute([
                        ':assembly_id' => $assemblyId,
                        ':fraction_id' => $fractionId,
                        ':user_id' => $attendance['user_id'] ?? null,
                        ':attendance_type' => $attendanceType,
                        ':proxy_user_id' => $attendance['proxy_user_id'] ?? null
                    ]);
                }
            }
            $registered++;
        }

        return $registered;
    }

    /**
     * Check if fraction is present
     */
    public function isPresent(int $assemblyId, int $fractionId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT id FROM assembly_attendees 
            WHERE assembly_id = :assembly_id 
            AND fraction_id = :fraction_id 
            AND attendance_type IN ('present', 'proxy')
            LIMIT 1
        ");
        $stmt->execute([':assembly_id' => $assemblyId, ':fraction_id' => $fractionId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Get present fractions for assembly
     */
    public function getPresentFractions(int $assemblyId): array
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
        $results = $stmt->fetchAll();
        
        return array_column($results, 'fraction_id');
    }

    /**
     * Calculate quorum
     */
    public function calculateQuorum(int $assemblyId, int $condominiumId): array
    {
        if (!$this->db) {
            return ['percentage' => 0, 'total_millage' => 0, 'attended_millage' => 0];
        }

        // Get total permillage of condominium
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(permillage), 0) as total_millage
            FROM fractions
            WHERE condominium_id = :condominium_id AND is_active = TRUE
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $total = $stmt->fetch();
        $totalMillage = (float)($total['total_millage'] ?? 0);

        // Get attended permillage
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(f.permillage), 0) as attended_millage
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





