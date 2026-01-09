<?php

namespace App\Controllers\Api;

use App\Models\Condominium;
use App\Middleware\ApiAuthMiddleware;

class CondominiumApiController extends ApiController
{
    protected $condominiumModel;

    public function __construct()
    {
        parent::__construct();
        $this->condominiumModel = new Condominium();
    }

    /**
     * List user's condominiums
     * GET /api/condominiums
     */
    public function index()
    {
        $condominiums = $this->condominiumModel->getByUserId($this->user['id']);
        
        $this->success([
            'condominiums' => $condominiums,
            'total' => count($condominiums)
        ]);
    }

    /**
     * Get condominium details
     * GET /api/condominiums/{id}
     */
    public function show(int $id)
    {
        $condominium = $this->condominiumModel->findById($id);

        if (!$condominium) {
            $this->error('Condominium not found', 404);
        }

        // Check if user has access to this condominium
        if (!$this->hasAccess($id)) {
            $this->error('Access denied', 403);
        }

        $this->success(['condominium' => $condominium]);
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





