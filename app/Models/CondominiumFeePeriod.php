<?php

namespace App\Models;

use App\Core\Model;

class CondominiumFeePeriod extends Model
{
    protected $table = 'condominium_fee_periods';

    public const PERIOD_TYPES = ['monthly', 'bimonthly', 'quarterly', 'semiannual', 'annual'];

    /**
     * Get fee period for condominium and year
     */
    public function get(int $condominiumId, int $year): ?string
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT period_type FROM condominium_fee_periods
            WHERE condominium_id = :condominium_id AND year = :year
            LIMIT 1
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':year' => $year
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (string)$row['period_type'] : null;
    }

    /**
     * Set fee period for condominium and year (upsert)
     */
    public function set(int $condominiumId, int $year, string $periodType): void
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }
        if (!in_array($periodType, self::PERIOD_TYPES)) {
            throw new \InvalidArgumentException("Invalid period type: {$periodType}");
        }

        $stmt = $this->db->prepare("
            INSERT INTO condominium_fee_periods (condominium_id, year, period_type)
            VALUES (:condominium_id, :year, :period_type)
            ON DUPLICATE KEY UPDATE period_type = :period_type2
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':year' => $year,
            ':period_type' => $periodType,
            ':period_type2' => $periodType
        ]);
    }
}
