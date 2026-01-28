<?php

namespace App\Controllers\Api;

use App\Models\BankAccount;

class BankAccountApiController extends ApiController
{
    protected $bankAccountModel;

    public function __construct()
    {
        parent::__construct();
        $this->bankAccountModel = new BankAccount();
    }

    /**
     * List bank accounts for a condominium
     * GET /api/condominiums/{condominium_id}/bank-accounts
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $filters = [];
        if (isset($_GET['account_type'])) {
            $filters['account_type'] = $_GET['account_type'];
        }
        if (isset($_GET['is_active'])) {
            $filters['is_active'] = $_GET['is_active'] === 'true' || $_GET['is_active'] === '1';
        }

        $accounts = $this->bankAccountModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'bank_accounts' => $accounts,
            'total' => count($accounts)
        ]);
    }

    /**
     * Get bank account details
     * GET /api/bank-accounts/{id}
     */
    public function show(int $id)
    {
        $account = $this->bankAccountModel->findById($id);

        if (!$account) {
            $this->error('Bank account not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($account['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['bank_account' => $account]);
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
