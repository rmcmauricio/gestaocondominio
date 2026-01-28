<?php

namespace App\Controllers\Api;

use App\Models\Supplier;

class SupplierApiController extends ApiController
{
    protected $supplierModel;

    public function __construct()
    {
        parent::__construct();
        $this->supplierModel = new Supplier();
    }

    /**
     * List suppliers for a condominium
     * GET /api/condominiums/{condominium_id}/suppliers
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $suppliers = $this->supplierModel->getByCondominium($condominiumId);

        $this->success([
            'suppliers' => $suppliers,
            'total' => count($suppliers)
        ]);
    }

    /**
     * Get supplier details
     * GET /api/suppliers/{id}
     */
    public function show(int $id)
    {
        $supplier = $this->supplierModel->findById($id);

        if (!$supplier) {
            $this->error('Supplier not found', 404);
        }

        // Verify access to the condominium (suppliers can be global or condominium-specific)
        if ($supplier['condominium_id'] && !$this->hasAccess($supplier['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['supplier' => $supplier]);
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
