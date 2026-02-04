<?php

namespace App\Controllers\Api;

use App\Models\Budget;
use App\Models\BudgetItem;

class BudgetApiController extends ApiController
{
    protected $budgetModel;
    protected $budgetItemModel;

    public function __construct()
    {
        parent::__construct();
        $this->budgetModel = new Budget();
        $this->budgetItemModel = new BudgetItem();
    }

    /**
     * List budgets for a condominium
     * GET /api/condominiums/{condominium_id}/budgets
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $budgets = $this->budgetModel->getByCondominium($condominiumId);

        $this->success([
            'budgets' => $budgets,
            'total' => count($budgets)
        ]);
    }

    /**
     * Get budget details
     * GET /api/budgets/{id}
     */
    public function show(int $id)
    {
        $budget = $this->budgetModel->findById($id);

        if (!$budget) {
            $this->error('Budget not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($budget['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['budget' => $budget]);
    }

    /**
     * Get budget items
     * GET /api/budgets/{id}/items
     */
    public function items(int $id)
    {
        $budget = $this->budgetModel->findById($id);

        if (!$budget) {
            $this->error('Budget not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($budget['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $items = $this->budgetItemModel->getByBudget($id);

        $this->success([
            'items' => $items,
            'total' => count($items)
        ]);
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
