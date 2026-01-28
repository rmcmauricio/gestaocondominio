<?php

namespace App\Controllers\Api;

use App\Models\Reservation;

class ReservationApiController extends ApiController
{
    protected $reservationModel;

    public function __construct()
    {
        parent::__construct();
        $this->reservationModel = new Reservation();
    }

    /**
     * List reservations for a condominium
     * GET /api/condominiums/{condominium_id}/reservations
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
        if (isset($_GET['space_id'])) {
            $filters['space_id'] = (int)$_GET['space_id'];
        }
        if (isset($_GET['start_date'])) {
            $filters['start_date'] = $_GET['start_date'];
        }
        if (isset($_GET['end_date'])) {
            $filters['end_date'] = $_GET['end_date'];
        }

        $reservations = $this->reservationModel->getByCondominium($condominiumId, $filters);

        $this->success([
            'reservations' => $reservations,
            'total' => count($reservations)
        ]);
    }

    /**
     * Get reservation details
     * GET /api/reservations/{id}
     */
    public function show(int $id)
    {
        $reservation = $this->reservationModel->findById($id);

        if (!$reservation) {
            $this->error('Reservation not found', 404);
        }

        // Verify access to the condominium
        if (!$this->hasAccess($reservation['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['reservation' => $reservation]);
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
