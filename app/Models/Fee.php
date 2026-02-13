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
     * Get pending/overdue fees for liquidation: oldest first, regular before extra.
     * Only fees with remaining amount > 0.
     */
    public function getPendingOrderedForLiquidation(int $fractionId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT f.id, f.amount, f.reference, f.period_year, f.period_month, f.period_index, f.period_type, f.fee_type, f.condominium_id
            FROM fees f
            WHERE f.fraction_id = :fraction_id
            AND f.status IN ('pending', 'overdue')
            AND (f.amount - COALESCE(
                (SELECT SUM(fp.amount) FROM fee_payments fp WHERE fp.fee_id = f.id),
                0
            )) > 0
            ORDER BY f.period_year ASC, COALESCE(f.period_index, f.period_month) ASC,
                     (CASE WHEN f.fee_type = 'regular' THEN 0 ELSE 1 END) ASC,
                     f.id ASC
        ");
        $stmt->execute([':fraction_id' => $fractionId]);
        return $stmt->fetchAll() ?: [];
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
                period_month, period_quarter, period_index, amount, base_amount,
                status, due_date, reference, notes, is_historical
            )
            VALUES (
                :condominium_id, :fraction_id, :period_type, :fee_type, :period_year,
                :period_month, :period_quarter, :period_index, :amount, :base_amount,
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
            ':period_index' => $data['period_index'] ?? null,
            ':amount' => $data['amount'],
            ':base_amount' => $data['base_amount'] ?? $data['amount'],
            ':status' => $data['status'] ?? 'pending',
            ':due_date' => $data['due_date'],
            ':reference' => $data['reference'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':is_historical' => isset($data['is_historical']) ? (int)$data['is_historical'] : 0
        ]);

        $feeId = (int)$this->db->lastInsertId();
        
        // Log audit
        $this->auditCreate($feeId, $data);
        
        return $feeId;
    }

    private const PERIOD_COUNTS = [
        'monthly' => 12,
        'bimonthly' => 6,
        'quarterly' => 4,
        'semiannual' => 2,
        'annual' => 1,
        'yearly' => 1,
    ];

    /** Months included in each period_index (1-based). Used to aggregate extras into period columns. */
    private const PERIOD_MONTH_RANGES = [
        'bimonthly' => [1 => [1, 2], 2 => [3, 4], 3 => [5, 6], 4 => [7, 8], 5 => [9, 10], 6 => [11, 12]],
        'quarterly' => [1 => [1, 2, 3], 2 => [4, 5, 6], 3 => [7, 8, 9], 4 => [10, 11, 12]],
        'semiannual' => [1 => [1, 2, 3, 4, 5, 6], 2 => [7, 8, 9, 10, 11, 12]],
        'annual' => [1 => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]],
        'yearly' => [1 => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]],
    ];

    public static function getMonthsForPeriod(string $periodType, int $periodIndex): array
    {
        $ranges = self::PERIOD_MONTH_RANGES[$periodType] ?? null;
        if ($ranges && isset($ranges[$periodIndex])) {
            return $ranges[$periodIndex];
        }
        if ($periodType === 'monthly' && $periodIndex >= 1 && $periodIndex <= 12) {
            return [$periodIndex];
        }
        return [];
    }

    /** Short labels for period_index by period_type (used in formatPeriodLabel). */
    private const PERIOD_INDEX_LABELS = [
        'bimonthly' => [1 => 'Jan-Fev', 2 => 'Mar-Abr', 3 => 'Mai-Jun', 4 => 'Jul-Ago', 5 => 'Set-Out', 6 => 'Nov-Dez'],
        'quarterly' => [1 => 'Q1', 2 => 'Q2', 3 => 'Q3', 4 => 'Q4'],
        'semiannual' => [1 => '1º Sem', 2 => '2º Sem'],
        'annual' => [1 => 'Anual'],
        'yearly' => [1 => 'Anual'],
    ];

    /**
     * Build human-readable period label for a fee (e.g. "01/2025", "Q1/2025", "Anual 2025").
     * Fee array must contain period_year and at least one of: period_month, (period_index + period_type).
     */
    public static function formatPeriodLabel(array $f): string
    {
        if (($f['fee_type'] ?? '') === 'extra' && !empty($f['reference'])) {
            return 'Quota extra: ' . $f['reference'];
        }
        $year = (int)($f['period_year'] ?? 0);
        $pt = $f['period_type'] ?? 'monthly';
        $pidx = isset($f['period_index']) ? (int)$f['period_index'] : null;
        $pmonth = isset($f['period_month']) ? (int)$f['period_month'] : null;

        if ($pt === 'monthly' && $pmonth >= 1 && $pmonth <= 12) {
            return 'Quota ' . sprintf('%02d/%d', $pmonth, $year);
        }
        if ($pidx !== null && isset(self::PERIOD_INDEX_LABELS[$pt][$pidx])) {
            $label = self::PERIOD_INDEX_LABELS[$pt][$pidx];
            if ($pt === 'annual' || $pt === 'yearly') {
                return 'Quota ' . $label . ' ' . $year;
            }
            return 'Quota ' . $label . '/' . $year;
        }
        if ($pmonth >= 1 && $pmonth <= 12) {
            return 'Quota ' . sprintf('%02d/%d', $pmonth, $year);
        }
        return 'Quota ' . $year;
    }

    /**
     * Return period display string for receipts, reports, emails (e.g. "01/2025", "Q1/2025", "Anual 2025").
     */
    public static function formatPeriodForDisplay(array $f): string
    {
        $label = self::formatPeriodLabel($f);
        return preg_replace('/^Quota\s+/', '', $label);
    }

    /**
     * Check if annual (regular) fees have been generated for this condominium and year.
     * @param string|null $periodType If null, tries condominium_fee_periods first, else assumes monthly
     */
    public function hasAnnualFeesForYear(int $condominiumId, int $year, ?string $periodType = null): bool
    {
        if (!$this->db) {
            return false;
        }

        if ($periodType === null) {
            $cfp = new \App\Models\CondominiumFeePeriod();
            $periodType = $cfp->get($condominiumId, $year);
        }
        $expectedCount = $periodType ? (self::PERIOD_COUNTS[$periodType] ?? 12) : 12;

        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT COALESCE(period_index, period_month)) as cnt
            FROM fees
            WHERE condominium_id = :condominium_id AND period_year = :year
            AND (fee_type = 'regular' OR fee_type IS NULL)
            AND COALESCE(is_historical, 0) = 0
            AND (period_month BETWEEN 1 AND 12 OR period_index BETWEEN 1 AND 12 OR (period_index IS NULL AND period_month IS NULL))
        ");
        $stmt->execute([':condominium_id' => $condominiumId, ':year' => $year]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $count = (int)($result['cnt'] ?? 0);

        return $count >= $expectedCount;
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
     * Quotas em falta da fração: lista de fees com valor por liquidar > 0.
     * Inclui todos os anos e dívidas históricas. Mesmos critérios que getTotalDueByFraction.
     */
    public function getOutstandingByFraction(int $fractionId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT f.id, f.period_year, f.period_month, f.period_index, f.period_type, f.fee_type, f.reference, f.amount, f.due_date, f.status,
                   COALESCE(paid.t, 0) AS paid,
                   (f.amount - COALESCE(paid.t, 0)) AS remaining
            FROM fees f
            LEFT JOIN (SELECT fee_id, SUM(amount) AS t FROM fee_payments GROUP BY fee_id) paid ON paid.fee_id = f.id
            WHERE f.fraction_id = :fraction_id
            AND f.status IN ('pending', 'overdue')
            AND (f.amount - COALESCE(paid.t, 0)) > 0
            ORDER BY f.period_year ASC, COALESCE(f.period_index, f.period_month) ASC, (CASE WHEN f.fee_type = 'regular' THEN 0 ELSE 1 END) ASC, f.id ASC
        ");
        $stmt->execute([':fraction_id' => $fractionId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Referências de pagamento (movimento financeiro) por fee: todos os pagamentos (incl. parciais)
     * aplicados a cada fee. Retorna [fee_id => [['ref'=>string, 'ft_id'=>int], ...]].
     */
    public function getPaymentRefsByFeeIds(array $feeIds): array
    {
        if (!$this->db || empty($feeIds)) {
            return [];
        }
        $ids = array_map('intval', $feeIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT fp.fee_id, ft.reference AS ref, ft.id AS ft_id
            FROM fee_payments fp
            INNER JOIN fraction_account_movements fam ON fam.source_reference_id = fp.id
                AND fam.type = 'debit' AND fam.source_type = 'quota_application'
            LEFT JOIN financial_transactions ft ON ft.id = fam.source_financial_transaction_id
            WHERE fp.fee_id IN ({$placeholders})
              AND ft.id IS NOT NULL AND ft.reference IS NOT NULL AND TRIM(ft.reference) != ''
            ORDER BY fp.fee_id, fam.created_at ASC
        ");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $fid = (int)$r['fee_id'];
            if (!isset($out[$fid])) {
                $out[$fid] = [];
            }
            $out[$fid][] = ['ref' => trim($r['ref']), 'ft_id' => (int)$r['ft_id']];
        }
        return $out;
    }

    /**
     * Get total amount due (em falta) by fraction: sum of (amount - paid) for fees
     * with status pending/overdue and remaining > 0. Inclui todos os anos e dívidas históricas.
     */
    public function getTotalDueByFraction(int $fractionId): float
    {
        if (!$this->db) {
            return 0;
        }
        $stmt = $this->db->prepare("
            SELECT SUM(f.amount - COALESCE(paid.t, 0)) AS due
            FROM fees f
            LEFT JOIN (SELECT fee_id, SUM(amount) AS t FROM fee_payments GROUP BY fee_id) paid ON paid.fee_id = f.id
            WHERE f.fraction_id = :fraction_id
            AND f.status IN ('pending', 'overdue')
            AND (f.amount - COALESCE(paid.t, 0)) > 0
        ");
        $stmt->execute([':fraction_id' => $fractionId]);
        $r = $stmt->fetch();
        return (float)($r['due'] ?? 0);
    }

    /**
     * Get total amount due (dívidas) by condominium: sum of (amount - paid) for fees
     * with status pending/overdue and remaining > 0, all fractions.
     */
    public function getTotalDueByCondominium(int $condominiumId): float
    {
        if (!$this->db) {
            return 0;
        }
        $stmt = $this->db->prepare("
            SELECT SUM(f.amount - COALESCE(paid.t, 0)) AS due
            FROM fees f
            LEFT JOIN (SELECT fee_id, SUM(amount) AS t FROM fee_payments GROUP BY fee_id) paid ON paid.fee_id = f.id
            WHERE f.condominium_id = :condominium_id
            AND f.status IN ('pending', 'overdue')
            AND (f.amount - COALESCE(paid.t, 0)) > 0
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $r = $stmt->fetch();
        return (float)($r['due'] ?? 0);
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
     * Update fee (for historical fees: amount, due_date, notes, period_month)
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $allowed = ['amount', 'base_amount', 'due_date', 'notes', 'period_month', 'period_year'];
        $set = [];
        $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $set[] = "{$f} = :{$f}";
                $params[":{$f}"] = $data[$f];
            }
        }
        if (empty($set)) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE fees SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = :id");
        return $stmt->execute($params);
    }

    /**
     * Delete fee
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM fees WHERE id = :id");
        return $stmt->execute([':id' => $id]);
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
     * Get fees map by year. Returns [period_index => [fraction_id => fee_data]].
     * Supports mixed scenario: regular fees by period (monthly/bimonthly/quarterly/etc) + extra fees (always monthly).
     * @param string|null $periodType monthly|bimonthly|quarterly|semiannual|annual. If null, infers from data (12 slots = monthly).
     */
    public function getFeesMapByYear(int $condominiumId, int $year, bool $includeHistorical = null, ?string $periodType = null): array
    {
        if (!$this->db) {
            return [];
        }

        if ($includeHistorical === null) {
            $includeHistorical = $year < (int)date('Y');
        }

        $sql = "
            SELECT f.id, f.fraction_id, f.period_month, f.period_index, f.period_type, f.amount,
                f.status, f.due_date, f.paid_at, f.fee_type, f.notes, f.is_historical,
                fr.identifier as fraction_identifier,
                COALESCE((SELECT SUM(fp.amount) FROM fee_payments fp WHERE fp.fee_id = f.id), 0) as paid_amount
            FROM fees f
            INNER JOIN fractions fr ON fr.id = f.fraction_id
            WHERE f.condominium_id = :condominium_id AND f.period_year = :year
            AND (f.period_month IS NOT NULL OR f.period_index IS NOT NULL OR COALESCE(f.is_historical, 0) = 1)";
        if (!$includeHistorical) {
            $sql .= " AND COALESCE(f.is_historical, 0) = 0";
        }
        $sql .= " ORDER BY COALESCE(f.period_index, f.period_month, 1) ASC, fr.identifier ASC, f.fee_type ASC, f.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':condominium_id' => $condominiumId, ':year' => $year]);
        $fees = $stmt->fetchAll() ?: [];

        $periodCount = $periodType ? (self::PERIOD_COUNTS[$periodType] ?? 12) : 12;
        $isMonthly = ($periodType === 'monthly' || $periodType === null);

        $map = [];
        foreach ($fees as $fee) {
            $fractionId = (int)$fee['fraction_id'];
            $feeType = $fee['fee_type'] ?? 'regular';
            $periodMonth = isset($fee['period_month']) ? (int)$fee['period_month'] : null;
            $periodIndex = isset($fee['period_index']) ? (int)$fee['period_index'] : null;

            $slot = null;
            if ($feeType === 'regular') {
                $slot = $periodIndex ?? $periodMonth;
            } else {
                $slot = $this->slotForExtraFee($periodMonth, $periodType ?? 'monthly', $periodCount);
            }
            // Historical fees with no period (yearly) go into slot 1
            if (($slot === null || $slot < 1 || $slot > $periodCount) && !empty($fee['is_historical'])) {
                $slot = 1;
            }
            if ($slot === null || $slot < 1 || $slot > $periodCount) {
                continue;
            }

            if (!isset($map[$slot])) {
                $map[$slot] = [];
            }
            if (!isset($map[$slot][$fractionId])) {
                $isOverdue = false;
                if ($fee['status'] === 'pending' && !empty($fee['due_date'])) {
                    $isOverdue = (new \DateTime($fee['due_date'])) < new \DateTime();
                }
                $map[$slot][$fractionId] = [
                    'id' => (int)$fee['id'],
                    'fraction_id' => $fractionId,
                    'fraction_identifier' => $fee['fraction_identifier'],
                    'amount' => 0,
                    'paid_amount' => 0,
                    'status' => $fee['status'],
                    'due_date' => $fee['due_date'],
                    'paid_at' => $fee['paid_at'],
                    'is_overdue' => $isOverdue,
                    'has_extra' => false,
                    'all_fees' => [],
                ];
            }

            $map[$slot][$fractionId]['all_fees'][] = [
                'id' => (int)$fee['id'],
                'fee_type' => $feeType,
                'amount' => (float)$fee['amount'],
                'notes' => $fee['notes'],
            ];
            $map[$slot][$fractionId]['amount'] += (float)$fee['amount'];
            $map[$slot][$fractionId]['paid_amount'] += (float)$fee['paid_amount'];
            if ($feeType === 'extra') {
                $map[$slot][$fractionId]['has_extra'] = true;
            }
            if ($fee['status'] === 'pending' && !empty($fee['due_date']) && (new \DateTime($fee['due_date'])) < new \DateTime()) {
                $map[$slot][$fractionId]['is_overdue'] = true;
                $map[$slot][$fractionId]['status'] = 'overdue';
            }
            $map[$slot][$fractionId]['remaining_amount'] =
                $map[$slot][$fractionId]['amount'] - $map[$slot][$fractionId]['paid_amount'];
        }

        // Derive status from actual payments (incl. credit application) - paid_amount is source of truth
        foreach ($map as $slot => $fractions) {
            foreach ($fractions as $fractionId => $data) {
                $amt = (float)($data['amount'] ?? 0);
                $paid = (float)($data['paid_amount'] ?? 0);
                if ($amt > 0 && $paid >= $amt) {
                    $map[$slot][$fractionId]['status'] = 'paid';
                    $map[$slot][$fractionId]['is_overdue'] = false;
                    $map[$slot][$fractionId]['remaining_amount'] = 0;
                }
            }
        }

        return $map;
    }

    private function slotForExtraFee(?int $periodMonth, string $periodType, int $periodCount): ?int
    {
        if ($periodMonth === null || $periodMonth < 1 || $periodMonth > 12) {
            return null;
        }
        if ($periodType === 'monthly') {
            return $periodMonth;
        }
        $ranges = self::PERIOD_MONTH_RANGES[$periodType] ?? null;
        if (!$ranges) {
            return 1;
        }
        foreach ($ranges as $idx => $months) {
            if (in_array($periodMonth, $months)) {
                return $idx;
            }
        }
        return 1;
    }

    /**
     * Check if the given year has any regular (non-historical) fees for the condominium.
     */
    public function hasRegularFeesInYear(int $condominiumId, int $year): bool
    {
        if (!$this->db) {
            return false;
        }
        $stmt = $this->db->prepare("
            SELECT 1 FROM fees
            WHERE condominium_id = :condominium_id AND period_year = :year
            AND COALESCE(is_historical, 0) = 0
            LIMIT 1
        ");
        $stmt->execute([':condominium_id' => $condominiumId, ':year' => $year]);
        return $stmt->fetch() !== false;
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

