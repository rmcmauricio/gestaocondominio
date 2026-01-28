<?php

namespace App\Controllers\Api;

use App\Models\Occurrence;

class OccurrenceApiController extends ApiController
{
    protected $occurrenceModel;

    public function __construct()
    {
        parent::__construct();
        $this->occurrenceModel = new Occurrence();
    }

    /**
     * List occurrences for a condominium
     * GET /api/condominiums/{condominium_id}/occurrences
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
        if (isset($_GET['priority'])) {
            $filters['priority'] = $_GET['priority'];
        }
        if (isset($_GET['category'])) {
            $filters['category'] = $_GET['category'];
        }
        if (isset($_GET['fraction_id'])) {
            $filters['fraction_id'] = (int)$_GET['fraction_id'];
        }

        $occurrences = $this->occurrenceModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'occurrences' => $occurrences,
            'total' => count($occurrences)
        ]);
    }

    /**
     * Get occurrence details
     * GET /api/occurrences/{id}
     */
    public function show(int $id)
    {
        $occurrence = $this->occurrenceModel->findById($id);

        if (!$occurrence) {
            $this->error('Occurrence not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($occurrence['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['occurrence' => $occurrence]);
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
