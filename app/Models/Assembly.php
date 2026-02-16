<?php

namespace App\Models;

use App\Core\Model;

class Assembly extends Model
{
    protected $table = 'assemblies';

    /**
     * Get assemblies by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT a.*, COUNT(DISTINCT aa.id) as attendees_count
                FROM assemblies a
                LEFT JOIN assembly_attendees aa ON aa.assembly_id = a.id
                WHERE a.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['status'])) {
            $sql .= " AND a.status = :status";
            $params[':status'] = $filters['status'];
        }

        $sql .= " GROUP BY a.id ORDER BY a.scheduled_date DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create assembly
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check which columns exist
        $stmt = $this->db->query("SHOW COLUMNS FROM assemblies");
        $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // Map type to database enum values if needed
        $type = $data['type'] ?? 'ordinaria';
        if ($type === 'ordinary') {
            $type = 'ordinaria';
        } elseif ($type === 'extraordinary') {
            $type = 'extraordinaria';
        }
        
        $fields = ['condominium_id', 'title', 'type', 'scheduled_date', 'location', 'quorum_percentage', 'status', 'created_by'];
        $values = [
            ':condominium_id' => $data['condominium_id'],
            ':title' => $data['title'],
            ':type' => $type,
            ':scheduled_date' => $data['scheduled_date'],
            ':location' => $data['location'] ?? null,
            ':quorum_percentage' => $data['quorum_percentage'] ?? 50,
            ':status' => $data['status'] ?? 'scheduled',
            ':created_by' => $data['created_by']
        ];
        
        // Add description if column exists
        if (in_array('description', $columns)) {
            $fields[] = 'description';
            $values[':description'] = $data['description'] ?? null;
        } elseif (in_array('agenda', $columns)) {
            // Use agenda if description doesn't exist
            $fields[] = 'agenda';
            $values[':agenda'] = $data['description'] ?? null;
        }
        
        // Add convocation_sent_at if column exists
        if (in_array('convocation_sent_at', $columns)) {
            $fields[] = 'convocation_sent_at';
            $values[':convocation_sent_at'] = $data['convocation_sent_at'] ?? null;
        }

        $sql = "INSERT INTO assemblies (" . implode(', ', $fields) . ") VALUES (:" . implode(', :', $fields) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        $assemblyId = (int)$this->db->lastInsertId();
        
        // Log audit
        $this->auditCreate($assemblyId, $data);
        
        return $assemblyId;
    }

    /**
     * Find assembly by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM assemblies WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update assembly
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[":$key"] = $value;
        }

        if (empty($fields)) {
            return false;
        }

        // Get old data for audit
        $oldData = $this->findById($id);

        $sql = "UPDATE assemblies SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        $result = $stmt->execute($params);
        
        // Log audit
        if ($result) {
            $this->auditUpdate($id, $data, $oldData);
        }
        
        return $result;
    }

    /**
     * Mark convocation as sent
     */
    public function markConvocationSent(int $id): bool
    {
        return $this->update($id, [
            'convocation_sent_at' => date('Y-m-d H:i:s'),
            'status' => 'scheduled'
        ]);
    }

    /**
     * Get fraction IDs that received convocation by email for this assembly (with sent_at for display)
     * @return array List of fraction rows with id, identifier, sent_at
     */
    public function getConvocationRecipients(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT f.id, f.identifier, acr.sent_at
            FROM assembly_convocation_recipients acr
            INNER JOIN fractions f ON f.id = acr.fraction_id
            WHERE acr.assembly_id = :assembly_id AND acr.sent_at IS NOT NULL
            ORDER BY f.identifier ASC
        ");
        $stmt->execute([':assembly_id' => $assemblyId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get convocation delivery info per fraction (sent_at by email, registered_letter_number by post)
     * @return array [ fraction_id => ['sent_at' => ..., 'registered_letter_number' => ...], ... ]
     */
    public function getConvocationDeliveryByAssembly(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT fraction_id, sent_at, registered_letter_number
            FROM assembly_convocation_recipients
            WHERE assembly_id = :assembly_id
        ");
        $stmt->execute([':assembly_id' => $assemblyId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['fraction_id']] = [
                'sent_at' => $r['sent_at'] ?? null,
                'registered_letter_number' => $r['registered_letter_number'] ?? null,
            ];
        }
        return $map;
    }

    /**
     * Set or update registered letter number for a fraction (envio por correio)
     */
    public function setRegisteredLetterNumber(int $assemblyId, int $fractionId, ?string $number): bool
    {
        if (!$this->db) {
            return false;
        }
        $stmt = $this->db->prepare("
            INSERT INTO assembly_convocation_recipients (assembly_id, fraction_id, sent_at, registered_letter_number)
            VALUES (:aid, :fid, NULL, :num)
            ON DUPLICATE KEY UPDATE registered_letter_number = VALUES(registered_letter_number)
        ");
        return $stmt->execute([
            ':aid' => $assemblyId,
            ':fid' => $fractionId,
            ':num' => $number !== null && $number !== '' ? $number : null,
        ]);
    }

    /**
     * Set convocation recipients (fraction IDs that received by email). Keeps registered_letter_number for other fractions.
     */
    public function setConvocationRecipients(int $assemblyId, array $fractionIds, string $sentAt = null): void
    {
        if (!$this->db) {
            return;
        }
        $sentAt = $sentAt ?? date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE assembly_convocation_recipients SET sent_at = NULL WHERE assembly_id = :aid")->execute([':aid' => $assemblyId]);
        $stmt = $this->db->prepare("
            INSERT INTO assembly_convocation_recipients (assembly_id, fraction_id, sent_at)
            VALUES (:aid, :fid, :sent_at)
            ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at)
        ");
        foreach ($fractionIds as $fid) {
            $stmt->execute([':aid' => $assemblyId, ':fid' => (int)$fid, ':sent_at' => $sentAt]);
        }
    }

    /**
     * Start assembly
     */
    public function start(int $id): bool
    {
        // Check if started_at column exists
        global $db;
        $stmt = $db->query("SHOW COLUMNS FROM assemblies LIKE 'started_at'");
        $hasStartedAt = $stmt->rowCount() > 0;

        $data = ['status' => 'in_progress'];
        if ($hasStartedAt) {
            $data['started_at'] = date('Y-m-d H:i:s');
        }

        return $this->update($id, $data);
    }

    /**
     * Close assembly
     */
    public function close(int $id, string $minutes = null): bool
    {
        // Check if closed_at column exists
        global $db;
        $stmt = $db->query("SHOW COLUMNS FROM assemblies LIKE 'closed_at'");
        $hasClosedAt = $stmt->rowCount() > 0;

        $data = ['status' => 'completed'];
        if ($hasClosedAt) {
            $data['closed_at'] = date('Y-m-d H:i:s');
        }

        if ($minutes) {
            $data['minutes'] = $minutes;
        }

        return $this->update($id, $data);
    }

    /**
     * Check if accounts for a year have been approved
     */
    public function hasApprovedAccountsForYear(int $condominiumId, int $year): bool
    {
        $approvalModel = new \App\Models\AssemblyAccountApproval();
        return $approvalModel->hasApprovedYear($condominiumId, $year);
    }
}





