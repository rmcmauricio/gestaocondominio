<?php

namespace App\Models;

use App\Core\Model;

class Fee extends Model
{
    protected $table = 'fees';

    /**
     * Get fees by fraction
     */
    public function getByFraction(int $fractionId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT * FROM fees WHERE fraction_id = :fraction_id";
        $params = [':fraction_id' => $fractionId];

        if (isset($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['year'])) {
            $sql .= " AND period_year = :year";
            $params[':year'] = $filters['year'];
        }

        $sql .= " ORDER BY period_year DESC, period_month DESC, created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get pending fees by fraction
     */
    public function getPendingByFraction(int $fractionId): array
    {
        return $this->getByFraction($fractionId, ['status' => 'pending']);
    }

    /**
     * Create fee
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO fees (
                condominium_id, fraction_id, period_type, fee_type, period_year,
                period_month, period_quarter, amount, base_amount,
                status, due_date, reference, notes, is_historical
            )
            VALUES (
                :condominium_id, :fraction_id, :period_type, :fee_type, :period_year,
                :period_month, :period_quarter, :amount, :base_amount,
                :status, :due_date, :reference, :notes, :is_historical
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':fraction_id' => $data['fraction_id'],
            ':period_type' => $data['period_type'] ?? 'monthly',
            ':fee_type' => $data['fee_type'] ?? 'regular',
            ':period_year' => $data['period_year'],
            ':period_month' => $data['period_month'] ?? null,
            ':period_quarter' => $data['period_quarter'] ?? null,
            ':amount' => $data['amount'],
            ':base_amount' => $data['base_amount'] ?? $data['amount'],
            ':status' => $data['status'] ?? 'pending',
            ':due_date' => $data['due_date'],
            ':reference' => $data['reference'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':is_historical' => isset($data['is_historical']) ? (int)$data['is_historical'] : 0
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Mark fee as paid
     */
    public function markAsPaid(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE fees 
            SET status = 'paid', paid_at = NOW() 
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $id]);
    }

    /**
     * Get total pending amount by fraction
     */
    public function getTotalPendingByFraction(int $fractionId): float
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT SUM(amount) as total 
            FROM fees 
            WHERE fraction_id = :fraction_id 
            AND status = 'pending'
        ");

        $stmt->execute([':fraction_id' => $fractionId]);
        $result = $stmt->fetch();
        return (float)($result['total'] ?? 0);
    }

    /**
     * Get fees by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT f.*, fr.identifier as fraction_identifier
                FROM fees f
                INNER JOIN fractions fr ON fr.id = f.fraction_id
                WHERE f.condominium_id = :condominium_id";

        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['year'])) {
            $sql .= " AND f.period_year = :year";
            $params[':year'] = $filters['year'];
        }

        if (isset($filters['month'])) {
            $sql .= " AND f.period_month = :month";
            $params[':month'] = $filters['month'];
        }

        if (isset($filters['status'])) {
            $sql .= " AND f.status = :status";
            $params[':status'] = $filters['status'];
        }

        $sql .= " ORDER BY f.period_year DESC, f.period_month DESC, fr.identifier ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find fee by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM fees WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all fees by month and fraction
     * Returns array of all fees for a specific month and fraction
     */
    public function getByMonthAndFraction(int $condominiumId, int $year, int $month, int $fractionId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT 
                f.*,
                fr.identifier as fraction_identifier,
                COALESCE((
                    SELECT SUM(fp.amount) 
                    FROM fee_payments fp 
                    WHERE fp.fee_id = f.id
                ), 0) as paid_amount
            FROM fees f
            INNER JOIN fractions fr ON fr.id = f.fraction_id
            WHERE f.condominium_id = :condominium_id
            AND f.period_year = :year
            AND f.period_month = :month
            AND f.fraction_id = :fraction_id
            AND COALESCE(f.is_historical, 0) = 0
            ORDER BY f.fee_type ASC, f.created_at ASC
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':year' => $year,
            ':month' => $month,
            ':fraction_id' => $fractionId
        ]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get fees map by year (organized by month and fraction)
     * Returns array: [month => [fraction_id => fee_data]]
     * Now sums all fees (regular + extra) for each month/fraction combination
     */
    public function getFeesMapByYear(int $condominiumId, int $year): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT 
                f.id,
                f.fraction_id,
                f.period_month,
                f.amount,
                f.status,
                f.due_date,
                f.paid_at,
                f.fee_type,
                f.notes,
                fr.identifier as fraction_identifier,
                COALESCE((
                    SELECT SUM(fp.amount) 
                    FROM fee_payments fp 
                    WHERE fp.fee_id = f.id
                ), 0) as paid_amount
            FROM fees f
            INNER JOIN fractions fr ON fr.id = f.fraction_id
            WHERE f.condominium_id = :condominium_id
            AND f.period_year = :year
            AND f.period_month IS NOT NULL
            AND COALESCE(f.is_historical, 0) = 0
            ORDER BY f.period_month ASC, fr.identifier ASC, f.fee_type ASC, f.created_at ASC
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':year' => $year
        ]);

        $fees = $stmt->fetchAll() ?: [];
        
        // Organize by month and fraction, summing amounts
        $map = [];
        foreach ($fees as $fee) {
            $month = (int)$fee['period_month'];
            $fractionId = (int)$fee['fraction_id'];
            
            if (!isset($map[$month])) {
                $map[$month] = [];
            }
            
            if (!isset($map[$month][$fractionId])) {
                // Initialize with first fee data
                $isOverdue = false;
                if ($fee['status'] === 'pending' && !empty($fee['due_date'])) {
                    $dueDate = new \DateTime($fee['due_date']);
                    $today = new \DateTime();
                    $isOverdue = $dueDate < $today;
                }
                
                $map[$month][$fractionId] = [
                    'id' => (int)$fee['id'], // Keep first fee ID for modal
                    'fraction_id' => $fractionId,
                    'fraction_identifier' => $fee['fraction_identifier'],
                    'amount' => 0, // Start at 0, will sum below
                    'paid_amount' => 0, // Start at 0, will sum below
                    'status' => $fee['status'],
                    'due_date' => $fee['due_date'],
                    'paid_at' => $fee['paid_at'],
                    'is_overdue' => $isOverdue,
                    'has_extra' => false,
                    'all_fees' => [] // Store all fees for this month/fraction
                ];
            }
            
            // Add this fee to the list
            $map[$month][$fractionId]['all_fees'][] = [
                'id' => (int)$fee['id'],
                'fee_type' => $fee['fee_type'] ?? 'regular',
                'amount' => (float)$fee['amount'],
                'notes' => $fee['notes']
            ];
            
            // Sum amounts (always sum, including first fee)
            $map[$month][$fractionId]['amount'] += (float)$fee['amount'];
            $map[$month][$fractionId]['paid_amount'] += (float)$fee['paid_amount'];
            
            // Check if has extra fees
            if (($fee['fee_type'] ?? 'regular') === 'extra') {
                $map[$month][$fractionId]['has_extra'] = true;
            }
            
            // Update status: if any is overdue, mark as overdue
            if ($fee['status'] === 'pending' && !empty($fee['due_date'])) {
                $dueDate = new \DateTime($fee['due_date']);
                $today = new \DateTime();
                if ($dueDate < $today) {
                    $map[$month][$fractionId]['is_overdue'] = true;
                    $map[$month][$fractionId]['status'] = 'overdue';
                }
            }
            
            // Update remaining amount
            $map[$month][$fractionId]['remaining_amount'] = 
                $map[$month][$fractionId]['amount'] - $map[$month][$fractionId]['paid_amount'];
        }

        return $map;
    }

    /**
     * Get available years for fees in a condominium
     */
    public function getAvailableYears(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT period_year as year
            FROM fees
            WHERE condominium_id = :condominium_id
            AND period_year IS NOT NULL
            ORDER BY period_year DESC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        $results = $stmt->fetchAll() ?: [];
        
        return array_map(function($row) {
            return (int)$row['year'];
        }, $results);
    }
}

