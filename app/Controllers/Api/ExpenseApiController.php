<?php

namespace App\Controllers\Api;

use App\Models\Expense;

class ExpenseApiController extends ApiController
{
    protected $expenseModel;

    public function __construct()
    {
        parent::__construct();
        $this->expenseModel = new Expense();
    }

    /**
     * List expenses for a condominium
     * GET /api/condominiums/{condominium_id}/expenses
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $filters = [];
        if (isset($_GET['year'])) {
            $filters['year'] = (int)$_GET['year'];
        }
        if (isset($_GET['month'])) {
            $filters['month'] = (int)$_GET['month'];
        }
        if (isset($_GET['category'])) {
            $filters['category'] = $_GET['category'];
        }
        if (isset($_GET['type'])) {
            $filters['type'] = $_GET['type'];
        }

        $expenses = $this->expenseModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'expenses' => $expenses,
            'total' => count($expenses)
        ]);
    }

    /**
     * Get expense details
     * GET /api/expenses/{id}
     */
    public function show(int $id)
    {
        $expense = $this->expenseModel->findById($id);

        if (!$expense) {
            $this->error('Expense not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($expense['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['expense' => $expense]);
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
