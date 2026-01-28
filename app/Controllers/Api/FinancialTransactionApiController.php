<?php

namespace App\Controllers\Api;

use App\Models\FinancialTransaction;

class FinancialTransactionApiController extends ApiController
{
    protected $transactionModel;

    public function __construct()
    {
        parent::__construct();
        $this->transactionModel = new FinancialTransaction();
    }

    /**
     * List financial transactions for a condominium
     * GET /api/condominiums/{condominium_id}/financial-transactions
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $filters = [];
        if (isset($_GET['bank_account_id'])) {
            $filters['bank_account_id'] = (int)$_GET['bank_account_id'];
        }
        if (isset($_GET['transaction_type'])) {
            $filters['transaction_type'] = $_GET['transaction_type'];
        }
        if (isset($_GET['from_date'])) {
            $filters['from_date'] = $_GET['from_date'];
        }
        if (isset($_GET['to_date'])) {
            $filters['to_date'] = $_GET['to_date'];
        }

        $transactions = $this->transactionModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'financial_transactions' => $transactions,
            'total' => count($transactions)
        ]);
    }

    /**
     * Get financial transaction details
     * GET /api/financial-transactions/{id}
     */
    public function show(int $id)
    {
        $transaction = $this->transactionModel->findById($id);

        if (!$transaction) {
            $this->error('Financial transaction not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($transaction['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['financial_transaction' => $transaction]);
    }

    /**
     * Check if user has access to condominium
     */
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
