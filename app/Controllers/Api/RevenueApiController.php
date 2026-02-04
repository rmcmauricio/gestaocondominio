<?php

namespace App\Controllers\Api;

use App\Models\Revenue;

class RevenueApiController extends ApiController
{
    protected $revenueModel;

    public function __construct()
    {
        parent::__construct();
        $this->revenueModel = new Revenue();
    }

    /**
     * List revenues for a condominium
     * GET /api/condominiums/{condominium_id}/revenues
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
        if (isset($_GET['fraction_id'])) {
            $filters['fraction_id'] = (int)$_GET['fraction_id'];
        }

        $revenues = $this->revenueModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'revenues' => $revenues,
            'total' => count($revenues)
        ]);
    }

    /**
     * Get revenue details
     * GET /api/revenues/{id}
     */
    public function show(int $id)
    {
        $revenue = $this->revenueModel->findById($id);

        if (!$revenue) {
            $this->error('Revenue not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($revenue['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['revenue' => $revenue]);
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
