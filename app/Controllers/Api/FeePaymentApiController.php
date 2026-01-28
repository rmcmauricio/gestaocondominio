<?php

namespace App\Controllers\Api;

use App\Models\FeePayment;
use App\Models\Fee;

class FeePaymentApiController extends ApiController
{
    protected $feePaymentModel;
    protected $feeModel;

    public function __construct()
    {
        parent::__construct();
        $this->feePaymentModel = new FeePayment();
        $this->feeModel = new Fee();
    }

    /**
     * List payments for a fee
     * GET /api/fees/{fee_id}/payments
     */
    public function index(int $feeId)
    {
        // Verify access to the fee's condominium
        $fee = $this->feeModel->findById($feeId);
        if (!$fee) {
            $this->error('Fee not found', 404);
        }

        if (!$this->hasAccess($fee['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $payments = $this->feePaymentModel->getByFee($feeId);

        $this->success([
            'payments' => $payments,
            'total' => count($payments)
        ]);
    }

    /**
     * Get payment details
     * GET /api/fee-payments/{id}
     */
    public function show(int $id)
    {
        $payment = $this->feePaymentModel->findById($id);

        if (!$payment) {
            $this->error('Payment not found', 404);
        }

        // Verify access to the fee's condominium
        $fee = $this->feeModel->findById($payment['fee_id']);
        if (!$fee) {
            $this->error('Fee not found', 404);
        }

        if (!$this->hasAccess($fee['condominium_id'])) {
            $this->error('Access denied', 403);
        }

        $this->success(['payment' => $payment]);
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
