<?php

namespace App\Controllers\Api;

use App\Models\Assembly;

class AssemblyApiController extends ApiController
{
    protected $assemblyModel;

    public function __construct()
    {
        parent::__construct();
        $this->assemblyModel = new Assembly();
    }

    /**
     * List assemblies for a condominium
     * GET /api/condominiums/{condominium_id}/assemblies
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $filters = [];
        if (isset($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }

        $assemblies = $this->assemblyModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'assemblies' => $assemblies,
            'total' => count($assemblies)
        ]);
    }

    /**
     * Get assembly details
     * GET /api/assemblies/{id}
     */
    public function show(int $id)
    {
        $assembly = $this->assemblyModel->findById($id);

        if (!$assembly) {
            $this->error('Assembly not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($assembly['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['assembly' => $assembly]);
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
