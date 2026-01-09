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

        $stmt = $this->db->prepare("
            INSERT INTO assemblies (
                condominium_id, title, description, type, scheduled_date,
                location, quorum_percentage, convocation_sent_at, status, created_by
            )
            VALUES (
                :condominium_id, :title, :description, :type, :scheduled_date,
                :location, :quorum_percentage, :convocation_sent_at, :status, :created_by
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':title' => $data['title'],
            ':description' => $data['description'] ?? null,
            ':type' => $data['type'] ?? 'ordinary',
            ':scheduled_date' => $data['scheduled_date'],
            ':location' => $data['location'] ?? null,
            ':quorum_percentage' => $data['quorum_percentage'] ?? 50,
            ':convocation_sent_at' => $data['convocation_sent_at'] ?? null,
            ':status' => $data['status'] ?? 'scheduled',
            ':created_by' => $data['created_by']
        ]);

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
        return $this->update($id, [
            'status' => 'in_progress',
            'started_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Close assembly
     */
    public function close(int $id, string $minutes = null): bool
    {
        $data = [
            'status' => 'closed',
            'closed_at' => date('Y-m-d H:i:s')
        ];

        if ($minutes) {
            $data['minutes'] = $minutes;
        }

        return $this->update($id, $data);
    }
}





