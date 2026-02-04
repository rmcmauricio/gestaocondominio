<?php

namespace App\Models;

use App\Core\Model;

class Occurrence extends Model
{
    protected $table = 'occurrences';

    /**
     * Get occurrences by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT o.*, 
                       u1.name as reported_by_name, 
                       u2.name as assigned_to_name,
                       s.name as supplier_name,
                       f.identifier as fraction_identifier
                FROM occurrences o
                LEFT JOIN users u1 ON u1.id = o.reported_by
                LEFT JOIN users u2 ON u2.id = o.assigned_to
                LEFT JOIN suppliers s ON s.id = o.supplier_id
                LEFT JOIN fractions f ON f.id = o.fraction_id
                WHERE o.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['status'])) {
            $sql .= " AND o.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['priority'])) {
            $sql .= " AND o.priority = :priority";
            $params[':priority'] = $filters['priority'];
        }

        if (isset($filters['reported_by'])) {
            $sql .= " AND o.reported_by = :reported_by";
            $params[':reported_by'] = $filters['reported_by'];
        }

        if (isset($filters['category'])) {
            $sql .= " AND o.category = :category";
            $params[':category'] = $filters['category'];
        }

        if (isset($filters['fraction_id'])) {
            $sql .= " AND o.fraction_id = :fraction_id";
            $params[':fraction_id'] = $filters['fraction_id'];
        }

        if (isset($filters['assigned_to'])) {
            $sql .= " AND o.assigned_to = :assigned_to";
            $params[':assigned_to'] = $filters['assigned_to'];
        }

        if (isset($filters['supplier_id'])) {
            $sql .= " AND o.supplier_id = :supplier_id";
            $params[':supplier_id'] = $filters['supplier_id'];
        }

        if (isset($filters['date_from'])) {
            $sql .= " AND DATE(o.created_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (isset($filters['date_to'])) {
            $sql .= " AND DATE(o.created_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'DESC');
        
        $allowedSortFields = ['created_at', 'title', 'priority', 'status'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }
        
        if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
            $sortOrder = 'DESC';
        }

        $sql .= " ORDER BY o.{$sortBy} {$sortOrder}";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Search occurrences
     */
    public function search(int $condominiumId, string $query, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT o.*, 
                       u1.name as reported_by_name, 
                       u2.name as assigned_to_name,
                       s.name as supplier_name,
                       f.identifier as fraction_identifier
                FROM occurrences o
                LEFT JOIN users u1 ON u1.id = o.reported_by
                LEFT JOIN users u2 ON u2.id = o.assigned_to
                LEFT JOIN suppliers s ON s.id = o.supplier_id
                LEFT JOIN fractions f ON f.id = o.fraction_id
                WHERE o.condominium_id = :condominium_id
                AND (o.title LIKE :query OR o.description LIKE :query)";

        $params = [
            ':condominium_id' => $condominiumId,
            ':query' => '%' . $query . '%'
        ];

        // Apply same filters as getByCondominium
        if (isset($filters['status'])) {
            $sql .= " AND o.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['priority'])) {
            $sql .= " AND o.priority = :priority";
            $params[':priority'] = $filters['priority'];
        }

        if (isset($filters['category'])) {
            $sql .= " AND o.category = :category";
            $params[':category'] = $filters['category'];
        }

        if (isset($filters['fraction_id'])) {
            $sql .= " AND o.fraction_id = :fraction_id";
            $params[':fraction_id'] = $filters['fraction_id'];
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'DESC');
        
        $allowedSortFields = ['created_at', 'title', 'priority', 'status'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }
        
        if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
            $sortOrder = 'DESC';
        }

        $sql .= " ORDER BY o.{$sortBy} {$sortOrder}";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get unique categories for a condominium
     */
    public function getCategories(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT category, COUNT(*) as count
            FROM occurrences
            WHERE condominium_id = :condominium_id AND category IS NOT NULL
            GROUP BY category
            ORDER BY category ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create occurrence
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO occurrences (
                condominium_id, fraction_id, reported_by, title, description,
                category, priority, status, location, attachments
            )
            VALUES (
                :condominium_id, :fraction_id, :reported_by, :title, :description,
                :category, :priority, :status, :location, :attachments
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':fraction_id' => $data['fraction_id'] ?? null,
            ':reported_by' => $data['reported_by'],
            ':title' => $data['title'],
            ':description' => $data['description'],
            ':category' => $data['category'] ?? null,
            ':priority' => $data['priority'] ?? 'medium',
            ':status' => $data['status'] ?? 'open',
            ':location' => $data['location'] ?? null,
            ':attachments' => !empty($data['attachments']) ? json_encode($data['attachments']) : null
        ]);

        $occurrenceId = (int)$this->db->lastInsertId();
        
        // Log audit
        $this->auditCreate($occurrenceId, $data);
        
        return $occurrenceId;
    }

    /**
     * Update occurrence
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            if ($key === 'attachments' && is_array($value)) {
                $fields[] = "attachments = :attachments";
                $params[':attachments'] = json_encode($value);
            } else {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        // Get old data for audit
        $oldData = $this->findById($id);

        $sql = "UPDATE occurrences SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        $result = $stmt->execute($params);
        
        // Log audit
        if ($result) {
            $this->auditUpdate($id, $data, $oldData);
        }
        
        return $result;
    }

    /**
     * Find occurrence by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM occurrences WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Change status
     */
    public function changeStatus(int $id, string $status, string $notes = null): bool
    {
        $data = ['status' => $status];
        
        if ($status === 'completed') {
            $data['completed_at'] = date('Y-m-d H:i:s');
        }
        
        if ($notes) {
            $data['resolution_notes'] = $notes;
        }

        return $this->update($id, $data);
    }

    /**
     * Assign to user/supplier
     */
    public function assign(int $id, int $assignedTo = null, int $supplierId = null): bool
    {
        $data = [
            'status' => 'assigned',
            'assigned_to' => $assignedTo,
            'supplier_id' => $supplierId
        ];

        return $this->update($id, $data);
    }
}





