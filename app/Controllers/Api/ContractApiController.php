<?php

namespace App\Controllers\Api;

use App\Models\Contract;

class ContractApiController extends ApiController
{
    protected $contractModel;

    public function __construct()
    {
        parent::__construct();
        $this->contractModel = new Contract();
    }

    /**
     * List contracts for a condominium
     * GET /api/condominiums/{condominium_id}/contracts
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $contracts = $this->contractModel->getByCondominium($condominiumId);

        $this->success([
            'contracts' => $contracts,
            'total' => count($contracts)
        ]);
    }

    /**
     * Get contract details
     * GET /api/contracts/{id}
     */
    public function show(int $id)
    {
        $contract = $this->contractModel->findById($id);

        if (!$contract) {
            $this->error('Contract not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($contract['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['contract' => $contract]);
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
