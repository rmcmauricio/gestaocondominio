<?php

namespace App\Controllers\Api;

use App\Models\Space;

class SpaceApiController extends ApiController
{
    protected $spaceModel;

    public function __construct()
    {
        parent::__construct();
        $this->spaceModel = new Space();
    }

    /**
     * List spaces for a condominium
     * GET /api/condominiums/{condominium_id}/spaces
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $spaces = $this->spaceModel->getByCondominium($condominiumId);

        $this->success([
            'spaces' => $spaces,
            'total' => count($spaces)
        ]);
    }

    /**
     * Get space details
     * GET /api/spaces/{id}
     */
    public function show(int $id)
    {
        $space = $this->spaceModel->findById($id);

        if (!$space) {
            $this->error('Space not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($space['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['space' => $space]);
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
