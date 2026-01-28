<?php

namespace App\Controllers\Api;

use App\Models\Document;

class DocumentApiController extends ApiController
{
    protected $documentModel;

    public function __construct()
    {
        parent::__construct();
        $this->documentModel = new Document();
    }

    /**
     * List documents for a condominium
     * GET /api/condominiums/{condominium_id}/documents
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $filters = [];
        if (isset($_GET['folder'])) {
            $filters['folder'] = $_GET['folder'] === 'null' ? null : (int)$_GET['folder'];
        }
        if (isset($_GET['document_type'])) {
            $filters['document_type'] = $_GET['document_type'];
        }
        if (isset($_GET['visibility'])) {
            $filters['visibility'] = $_GET['visibility'];
        }

        $documents = $this->documentModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'documents' => $documents,
            'total' => count($documents)
        ]);
    }

    /**
     * Get document details
     * GET /api/documents/{id}
     */
    public function show(int $id)
    {
        $document = $this->documentModel->findById($id);

        if (!$document) {
            $this->error('Document not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($document['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['document' => $document]);
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
