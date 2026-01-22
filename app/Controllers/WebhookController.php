<?php

namespace App\Controllers;

use App\Services\PaymentService;
use App\Services\IfthenPayService;

class WebhookController
{
    protected $paymentService;
    protected $ifthenPayService;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
        
        // Initialize IfthenPay service if provider is ifthenpay
        global $config;
        $pspProvider = $config['PSP_PROVIDER'] ?? getenv('PSP_PROVIDER') ?: '';
        if ($pspProvider === 'ifthenpay') {
            $this->ifthenPayService = new IfthenPayService();
        }
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
     * Handle IfthenPay callback (GET with query parameters)
     */
    public function ifthenpayCallback()
    {
        if (!$this->ifthenPayService) {
            http_response_code(503);
            echo json_encode(['error' => 'IfthenPay service not configured']);
            exit;
        }

        // Get callback data from GET parameters
        $callbackData = $_GET;
        
        // Validate callback
        if (!$this->ifthenPayService->validateCallback($callbackData)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid anti-phishing key']);
            exit;
        }

        $requestId = $callbackData['requestId'] ?? $callbackData['idpedido'] ?? null;
        $orderId = $callbackData['orderId'] ?? null;

        if (!$requestId && !$orderId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing payment identifier']);
            exit;
        }

        try {
            // Identify payment type by checking callback parameters
            $paymentType = null;
            if (isset($callbackData['entity']) && isset($callbackData['reference'])) {
                $paymentType = 'multibanco';
            } elseif (isset($callbackData['mbway_phone'])) {
                $paymentType = 'mbway';
            } elseif (isset($callbackData['dd_mandate_reference'])) {
                $paymentType = 'direct_debit';
            }

            if (!$paymentType) {
                http_response_code(400);
                echo json_encode(['error' => 'Unable to identify payment type']);
                exit;
            }

            // Process callback based on type
            $isValid = false;
            switch ($paymentType) {
                case 'multibanco':
                    $isValid = $this->ifthenPayService->processMultibancoCallback($callbackData);
                    break;
                case 'mbway':
                    $isValid = $this->ifthenPayService->processMBWayCallback($callbackData);
                    break;
                case 'direct_debit':
                    $isValid = $this->ifthenPayService->processDirectDebitCallback($callbackData);
                    break;
            }

            if (!$isValid) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid callback data']);
                exit;
            }

            // Find payment by requestId or orderId
            $paymentModel = new \App\Models\Payment();
            $payment = null;
            
            if ($requestId) {
                $payment = $paymentModel->findByRequestId($requestId);
            }
            
            if (!$payment && $orderId) {
                // Try to find by orderId (might be stored in metadata or external_payment_id)
                $payment = $paymentModel->findByExternalId($orderId);
            }

            if (!$payment) {
                error_log("IfthenPay callback: Payment not found for requestId: {$requestId}, orderId: {$orderId}");
                http_response_code(404);
                echo json_encode(['error' => 'Payment not found']);
                exit;
            }

            // Check if already processed
            if ($payment['status'] === 'completed') {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Already processed']);
                exit;
            }

            // Confirm payment
            $this->paymentService->confirmPayment($payment['external_payment_id'] ?? $requestId, $callbackData);
            
            http_response_code(200);
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            error_log("IfthenPay callback error: " . $e->getMessage());
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

