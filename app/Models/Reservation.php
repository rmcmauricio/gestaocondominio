<?php

namespace App\Models;

use App\Core\Model;

class Reservation extends Model
{
    protected $table = 'reservations';

    /**
     * Get reservations by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT r.*, s.name as space_name, f.identifier as fraction_identifier,
                       u.name as user_name
                FROM reservations r
                INNER JOIN spaces s ON s.id = r.space_id
                INNER JOIN fractions f ON f.id = r.fraction_id
                INNER JOIN users u ON u.id = r.user_id
                WHERE r.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['status'])) {
            $sql .= " AND r.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['space_id'])) {
            $sql .= " AND r.space_id = :space_id";
            $params[':space_id'] = $filters['space_id'];
        }

        if (isset($filters['start_date'])) {
            $sql .= " AND r.start_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }

        if (isset($filters['end_date'])) {
            $sql .= " AND r.end_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        $sql .= " ORDER BY r.start_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Check if space is available
     */
    public function isSpaceAvailable(int $spaceId, string $startDate, string $endDate, int $excludeReservationId = null): bool
    {
        if (!$this->db) {
            return false;
        }

        $sql = "SELECT COUNT(*) as count 
                FROM reservations 
                WHERE space_id = :space_id 
                AND status IN ('pending', 'approved')
                AND (
                    (start_date <= :start_date AND end_date >= :start_date) OR
                    (start_date <= :end_date AND end_date >= :end_date) OR
                    (start_date >= :start_date AND end_date <= :end_date)
                )";

        $params = [
            ':space_id' => $spaceId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ];

        if ($excludeReservationId) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeReservationId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return ($result['count'] ?? 0) == 0;
    }

    /**
     * Create reservation
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO reservations (
                condominium_id, space_id, fraction_id, user_id,
                start_date, end_date, status, price, deposit, notes
            )
            VALUES (
                :condominium_id, :space_id, :fraction_id, :user_id,
                :start_date, :end_date, :status, :price, :deposit, :notes
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':space_id' => $data['space_id'],
            ':fraction_id' => $data['fraction_id'],
            ':user_id' => $data['user_id'],
            ':start_date' => $data['start_date'],
            ':end_date' => $data['end_date'],
            ':status' => $data['status'] ?? 'pending',
            ':price' => $data['price'] ?? 0,
            ':deposit' => $data['deposit'] ?? 0,
            ':notes' => $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update reservation status
     */
    public function updateStatus(int $id, string $status, int $approvedBy = null): bool
    {
        if (!$this->db) {
            return false;
        }

        $data = ['status' => $status];
        
        if ($status === 'approved' && $approvedBy) {
            $data['approved_by'] = $approvedBy;
            $data['approved_at'] = date('Y-m-d H:i:s');
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[":$key"] = $value;
        }

        $sql = "UPDATE reservations SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Find reservation by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM reservations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}





