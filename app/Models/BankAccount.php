<?php

namespace App\Models;

use App\Core\Model;

class BankAccount extends Model
{
    protected $table = 'bank_accounts';

    /**
     * Get accounts by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $sql = "SELECT * FROM bank_accounts WHERE condominium_id = :condominium_id";
        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['account_type'])) {
            $sql .= " AND account_type = :account_type";
            $params[':account_type'] = $filters['account_type'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = :is_active";
            $params[':is_active'] = $filters['is_active'] ? 1 : 0;
        }

        $sql .= " ORDER BY account_type ASC, name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get active accounts
     */
    public function getActiveAccounts(int $condominiumId): array
    {
        return $this->getByCondominium($condominiumId, ['is_active' => true]);
    }

    /**
     * Get cash account for condominium
     */
    public function getCashAccount(int $condominiumId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM bank_accounts 
            WHERE condominium_id = :condominium_id 
            AND account_type = 'cash' 
            LIMIT 1
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $result = $stmt->fetch();
        
        return $result ?: null;
    }

    /**
     * Create cash account if it doesn't exist
     */
    public function createCashAccount(int $condominiumId): int
    {
        $existing = $this->getCashAccount($condominiumId);
        if ($existing) {
            return $existing['id'];
        }

        return $this->create([
            'condominium_id' => $condominiumId,
            'name' => 'Caixa',
            'account_type' => 'cash',
            'initial_balance' => 0.00,
            'current_balance' => 0.00,
            'is_active' => true
        ]);
    }

    /**
     * Calculate current balance for an account
     */
    public function calculateBalance(int $accountId): float
    {
        if (!$this->db) {
            return 0.0;
        }

        // Get initial balance
        $stmt = $this->db->prepare("SELECT initial_balance FROM bank_accounts WHERE id = :id");
        $stmt->execute([':id' => $accountId]);
        $account = $stmt->fetch();
        
        if (!$account) {
            return 0.0;
        }

        $initialBalance = (float)$account['initial_balance'];

        // Calculate total income
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM financial_transactions 
            WHERE bank_account_id = :account_id 
            AND transaction_type = 'income'
        ");
        $stmt->execute([':account_id' => $accountId]);
        $income = $stmt->fetch();
        $totalIncome = (float)($income['total'] ?? 0);

        // Calculate total expense
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM financial_transactions 
            WHERE bank_account_id = :account_id 
            AND transaction_type = 'expense'
        ");
        $stmt->execute([':account_id' => $accountId]);
        $expense = $stmt->fetch();
        $totalExpense = (float)($expense['total'] ?? 0);

        return $initialBalance + $totalIncome - $totalExpense;
    }

    /**
     * Update current balance for an account
     */
    public function updateBalance(int $accountId): bool
    {
        if (!$this->db) {
            return false;
        }

        $balance = $this->calculateBalance($accountId);
        
        $stmt = $this->db->prepare("UPDATE bank_accounts SET current_balance = :balance WHERE id = :id");
        return $stmt->execute([
            ':balance' => $balance,
            ':id' => $accountId
        ]);
    }

    /**
     * Create account
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO bank_accounts (
                condominium_id, name, account_type, bank_name, account_number,
                iban, swift, initial_balance, current_balance, is_active
            )
            VALUES (
                :condominium_id, :name, :account_type, :bank_name, :account_number,
                :iban, :swift, :initial_balance, :current_balance, :is_active
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':name' => $data['name'],
            ':account_type' => $data['account_type'] ?? 'bank',
            ':bank_name' => $data['bank_name'] ?? null,
            ':account_number' => $data['account_number'] ?? null,
            ':iban' => $data['iban'] ?? null,
            ':swift' => $data['swift'] ?? null,
            ':initial_balance' => $data['initial_balance'] ?? 0.00,
            ':current_balance' => $data['current_balance'] ?? ($data['initial_balance'] ?? 0.00),
            ':is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update account
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['name', 'account_type', 'bank_name', 'account_number', 'iban', 'swift', 'initial_balance', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        // Recalculate balance if initial_balance changed
        if (isset($data['initial_balance'])) {
            $this->updateBalance($id);
        }

        $sql = "UPDATE bank_accounts SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Find account by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM bank_accounts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Check if account has transactions
     */
    public function hasTransactions(int $accountId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM financial_transactions 
            WHERE bank_account_id = :account_id
        ");
        $stmt->execute([':account_id' => $accountId]);
        $result = $stmt->fetch();
        
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Delete bank account
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM bank_accounts WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
