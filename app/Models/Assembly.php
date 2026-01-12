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

        return (int)$this->db->lastInsertId();
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

        $sql = "UPDATE assemblies SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
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
}





