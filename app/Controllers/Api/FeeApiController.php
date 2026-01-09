<?php

namespace App\Controllers\Api;

use App\Models\Fee;
use App\Models\Condominium;

class FeeApiController extends ApiController
{
    protected $feeModel;
    protected $condominiumModel;

    public function __construct()
    {
        parent::__construct();
        $this->feeModel = new Fee();
        $this->condominiumModel = new Condominium();
    }

    /**
     * Get fees for a condominium
     * GET /api/condominiums/{condominium_id}/fees
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
        if (isset($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }

        $fees = $this->feeModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'fees' => $fees,
            'total' => count($fees)
        ]);
    }

    /**
     * Get fees for a specific fraction
     * GET /api/fractions/{fraction_id}/fees
     */
    public function byFraction(int $fractionId)
    {
        global $db;
        if (!$db) {
            $this->error('Database unavailable', 500);
        }

        // Verify fraction belongs to user's condominium
        $stmt = $db->prepare("
            SELECT f.condominium_id
            FROM fractions f
            INNER JOIN condominium_users cu ON cu.condominium_id = f.condominium_id
            WHERE f.id = :fraction_id
            AND cu.user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            ':fraction_id' => $fractionId,
            ':user_id' => $this->user['id']
        ]);

        $result = $stmt->fetch();
        if (!$result) {
            $this->error('Fraction not found or access denied', 404);
        }

        $filters = [];
        if (isset($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        if (isset($_GET['year'])) {
            $filters['year'] = (int)$_GET['year'];
        }

        $fees = $this->feeModel->getByFraction($fractionId, $filters);

        $this->success([
            'fees' => $fees,
            'total' => count($fees)
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





