<?php

namespace App\Controllers\Api;

use App\Models\FinancialTransaction;

/**
 * API for expenses - now returns financial_transactions with transaction_type='expense'
 */
class ExpenseApiController extends ApiController
{
    protected $transactionModel;

    public function __construct()
    {
        parent::__construct();
        $this->transactionModel = new FinancialTransaction();
    }

    /**
     * List expenses (financial transactions type expense) for a condominium
     * GET /api/condominiums/{condominium_id}/expenses
     */
    public function index(int $condominiumId)
    {
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $filters = ['transaction_type' => 'expense'];
        if (isset($_GET['year'])) {
            $year = (int)$_GET['year'];
            $filters['from_date'] = "{$year}-01-01";
            $filters['to_date'] = "{$year}-12-31";
        }
        if (isset($_GET['month']) && isset($_GET['year'])) {
            $month = (int)$_GET['month'];
            $year = (int)($_GET['year'] ?? date('Y'));
            $filters['from_date'] = sprintf('%04d-%02d-01', $year, $month);
            $filters['to_date'] = date('Y-m-t', strtotime($filters['from_date']));
        }
        if (!empty($_GET['category'])) {
            $filters['category'] = $_GET['category'];
        }

        $transactions = $this->transactionModel->getByCondominium($condominiumId, $filters);

        $expenses = array_map(function ($t) {
            return [
                'id' => $t['id'],
                'condominium_id' => $t['condominium_id'],
                'description' => $t['description'],
                'amount' => (float)$t['amount'],
                'category' => $t['category'] ?? null,
                'expense_date' => $t['transaction_date'],
                'transaction_date' => $t['transaction_date'],
                'bank_account_id' => $t['bank_account_id'],
                'account_name' => $t['account_name'] ?? null,
            ];
        }, $transactions);

        $this->success([
            'expenses' => $expenses,
            'total' => count($expenses)
        ]);
    }

    /**
     * Get expense (financial transaction) details
     * GET /api/expenses/{id}
     */
    public function show(int $id)
    {
        $transaction = $this->transactionModel->findById($id);

        if (!$transaction || $transaction['transaction_type'] !== 'expense') {
            $this->error('Expense not found', 404);
        }

        if (!$this->hasAccess($transaction['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $expense = [
            'id' => $transaction['id'],
            'condominium_id' => $transaction['condominium_id'],
            'description' => $transaction['description'],
            'amount' => (float)$transaction['amount'],
            'category' => $transaction['category'] ?? null,
            'expense_date' => $transaction['transaction_date'],
            'transaction_date' => $transaction['transaction_date'],
            'bank_account_id' => $transaction['bank_account_id'],
        ];

        $this->success(['expense' => $expense]);
    }

    protected function hasAccess(int $condominiumId): bool
    {
        global $db;
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM condominium_users
            WHERE condominium_id = :condominium_id
            AND user_id = :user_id
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $this->user['id']
        ]);

        $result = $stmt->fetch();
        return ($result['count'] ?? 0) > 0;
    }
}
