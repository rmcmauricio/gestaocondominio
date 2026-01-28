<?php

namespace App\Controllers\Api;

use App\Models\Message;

class MessageApiController extends ApiController
{
    protected $messageModel;

    public function __construct()
    {
        parent::__construct();
        $this->messageModel = new Message();
    }

    /**
     * List messages for a condominium
     * GET /api/condominiums/{condominium_id}/messages
     */
    public function index(int $condominiumId)
    {
        // Verify access
        if (!$this->hasAccess($condominiumId)) {
            $this->error('Access denied', 403);
        }

        $filters = [];
        if (isset($_GET['recipient_id'])) {
            $filters['recipient_id'] = (int)$_GET['recipient_id'];
        }
        if (isset($_GET['sender_id'])) {
            $filters['sender_id'] = (int)$_GET['sender_id'];
        }
        if (isset($_GET['is_read'])) {
            $filters['is_read'] = $_GET['is_read'] === 'true' || $_GET['is_read'] === '1';
        }

        $messages = $this->messageModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'messages' => $messages,
            'total' => count($messages)
        ]);
    }

    /**
     * Get message details
     * GET /api/messages/{id}
     */
    public function show(int $id)
    {
        $message = $this->messageModel->findById($id);

        if (!$message) {
            $this->error('Message not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($message['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['message' => $message]);
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
