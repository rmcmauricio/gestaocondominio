<?php

namespace App\Controllers\Api;

use App\Models\Fraction;

class FractionApiController extends ApiController
{
    protected $fractionModel;

    public function __construct()
    {
        parent::__construct();
        $this->fractionModel = new Fraction();
    }

    /**
     * List fractions for a condominium
     * GET /api/condominiums/{condominium_id}/fractions
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $fractions = $this->fractionModel->getByCondominiumId($condominiumId);

        $this->success([
            'fractions' => $fractions,
            'total' => count($fractions)
        ]);
    }

    /**
     * Get fraction details
     * GET /api/fractions/{id}
     */
    public function show(int $id)
    {
        $fraction = $this->fractionModel->findById($id);

        if (!$fraction) {
            $this->error('Fraction not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($fraction['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['fraction' => $fraction]);
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
