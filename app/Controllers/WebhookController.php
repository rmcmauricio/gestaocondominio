<?php

namespace App\Controllers;

use App\Services\PaymentService;

class WebhookController
{
    protected $paymentService;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
    }

    /**
     * Handle payment webhook from PSP
     */
    public function payment()
    {
        // Get webhook data
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        // In production, verify webhook signature here
        // $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
        // if (!$this->verifyWebhookSignature($input, $signature)) {
        //     http_response_code(401);
        //     echo json_encode(['error' => 'Invalid signature']);
        //     exit;
        // }

        $externalPaymentId = $data['payment_id'] ?? $data['external_payment_id'] ?? null;
        $status = $data['status'] ?? null;

        if (!$externalPaymentId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing payment ID']);
            exit;
        }

        try {
            if ($status === 'completed' || $status === 'paid') {
                $this->paymentService->confirmPayment($externalPaymentId, $data);
                http_response_code(200);
                echo json_encode(['success' => true]);
            } elseif ($status === 'failed' || $status === 'rejected') {
                $this->paymentService->markPaymentAsFailed($externalPaymentId, $data['reason'] ?? '');
                http_response_code(200);
                echo json_encode(['success' => true]);
            } else {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Status not processed']);
            }
        } catch (\Exception $e) {
            error_log("Webhook error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    /**
     * Verify webhook signature (to be implemented with actual PSP)
     * 
     * Configuration needed in .env:
     * - PSP_WEBHOOK_SECRET: Secret key for webhook validation
     */
    protected function verifyWebhookSignature(string $payload, string $signature): bool
    {
        global $config;
        $secret = $config['PSP_WEBHOOK_SECRET'] ?? getenv('PSP_WEBHOOK_SECRET');
        
        if (empty($secret)) {
            // In development, accept all webhooks if secret is not configured
            // In production, this should return false
            $appEnv = $config['APP_ENV'] ?? getenv('APP_ENV') ?: 'development';
            return $appEnv === 'development';
        }
        
        // Verify signature using PSP's secret key
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }
}

