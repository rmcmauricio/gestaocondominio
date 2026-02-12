<?php

namespace App\Models;

use App\Core\Model;

class FractionAccountMovement extends Model
{
    protected $table = 'fraction_account_movements';

    /**
     * Create movement
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $cols = ['fraction_account_id', 'type', 'amount', 'source_type', 'source_reference_id', 'description'];
        $vals = [':fraction_account_id', ':type', ':amount', ':source_type', ':source_reference_id', ':description'];
        if (array_key_exists('source_financial_transaction_id', $data)) {
            $cols[] = 'source_financial_transaction_id';
            $vals[] = ':source_financial_transaction_id';
        }
        $stmt = $this->db->prepare("
            INSERT INTO fraction_account_movements (" . implode(', ', $cols) . ")
            VALUES (" . implode(', ', $vals) . ")
        ");
        $params = [
            ':fraction_account_id' => $data['fraction_account_id'],
            ':type' => $data['type'],
            ':amount' => $data['amount'],
            ':source_type' => $data['source_type'],
            ':source_reference_id' => $data['source_reference_id'] ?? null,
            ':description' => $data['description'] ?? null
        ];
        if (array_key_exists('source_financial_transaction_id', $data)) {
            $params[':source_financial_transaction_id'] = $data['source_financial_transaction_id'];
        }
        $stmt->execute($params);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Get movements by fraction account
     */
    public function getByFractionAccount(int $fractionAccountId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT * FROM fraction_account_movements WHERE fraction_account_id = :fraction_account_id";
        $params = [':fraction_account_id' => $fractionAccountId];

        if (isset($filters['type'])) {
            $sql .= " AND type = :type";
            $params[':type'] = $filters['type'];
        }

        $sql .= " ORDER BY created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get movements by fraction account with fee info for debits (quota_application)
     */
    public function getByFractionAccountWithFeeInfo(int $fractionAccountId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT fam.*,
            f.id as fee_id, f.amount as fee_amount, f.period_year as fee_period_year, f.period_month as fee_period_month, f.reference as fee_reference, f.fee_type,
            (SELECT COALESCE(SUM(fp2.amount), 0) FROM fee_payments fp2 WHERE fp2.fee_id = f.id) as fee_total_paid,
            fp.reference as fee_payment_reference,
            ft.id as ft_id, ft.reference as ft_reference, ft.amount as ft_amount, ft.transaction_date as ft_date, ft.description as ft_description
            FROM fraction_account_movements fam
            LEFT JOIN fee_payments fp ON fp.id = fam.source_reference_id AND fam.type = 'debit' AND fam.source_type = 'quota_application'
            LEFT JOIN fees f ON f.id = fp.fee_id
            LEFT JOIN financial_transactions ft ON ft.id = COALESCE(fam.source_financial_transaction_id,
                (SELECT fam2.source_reference_id
                 FROM fraction_account_movements fam2
                 WHERE fam2.fraction_account_id = fam.fraction_account_id
                   AND fam2.type = 'credit' AND fam2.source_type = 'quota_payment'
                   AND fam2.source_reference_id IS NOT NULL
                   AND fam2.created_at <= fam.created_at
                 ORDER BY fam2.created_at DESC
                 LIMIT 1))
            WHERE fam.fraction_account_id = :fraction_account_id";
        $params = [':fraction_account_id' => $fractionAccountId];

        if (isset($filters['type'])) {
            $sql .= " AND fam.type = :type";
            $params[':type'] = $filters['type'];
        }

        $sql .= " ORDER BY fam.created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Update movement (e.g. description after liquidation)
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }
        $allowed = ['description'];
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
        $stmt = $this->db->prepare("UPDATE fraction_account_movements SET " . implode(', ', $set) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    /**
     * Update amount and/or description (for historical credit edits)
     */
    public function updateAmountAndDescription(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }
        $allowed = ['amount', 'description'];
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
        $stmt = $this->db->prepare("UPDATE fraction_account_movements SET " . implode(', ', $set) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    /**
     * Find movement by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM fraction_account_movements WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
}
