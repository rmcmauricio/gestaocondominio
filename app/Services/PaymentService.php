<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Payment;
use App\Models\Invoice;

class PaymentService
{
    protected $subscriptionModel;
    protected $paymentModel;
    protected $invoiceModel;

    public function __construct()
    {
        $this->subscriptionModel = new Subscription();
        $this->paymentModel = new Payment();
        $this->invoiceModel = new Invoice();
    }

    /**
     * Generate Multibanco reference
     * In production, integrate with actual PSP (e.g., Easypay, Ifthenpay, etc.)
     * 
     * Configuration needed in .env:
     * - MULTIBANCO_ENTITY: Código de entidade fornecido pelo PSP
     * - PSP_PROVIDER: easypay, ifthenpay, unipay, stripe
     * - PSP_ENVIRONMENT: sandbox ou production
     * 
     * See docs/PAYMENT_SETUP.md for detailed setup instructions
     */
    public function generateMultibancoReference(float $amount, int $subscriptionId, ?int $invoiceId = null): array
    {
        // Get entity code from config (.env) or use default for development
        global $config;
        $entity = $config['MULTIBANCO_ENTITY'] ?? getenv('MULTIBANCO_ENTITY') ?: '12345';
        
        // Generate unique reference (9 digits)
        // In production, this should come from PSP API
        $reference = str_pad($subscriptionId . time() % 10000, 9, '0', STR_PAD_LEFT);
        
        // Create payment record
        $paymentId = $this->paymentModel->create([
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'user_id' => $this->getUserIdFromSubscription($subscriptionId),
            'amount' => $amount,
            'payment_method' => 'multibanco',
            'status' => 'pending',
            'reference' => $entity . ' ' . substr($reference, 0, 3) . ' ' . substr($reference, 3, 3) . ' ' . substr($reference, 6, 3),
            'metadata' => [
                'entity' => $entity,
                'reference' => $reference,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+3 days'))
            ]
        ]);
        
        return [
            'payment_id' => $paymentId,
            'entity' => $entity,
            'reference' => $entity . ' ' . substr($reference, 0, 3) . ' ' . substr($reference, 3, 3) . ' ' . substr($reference, 6, 3),
            'amount' => number_format($amount, 2, ',', ''),
            'expires_at' => date('d/m/Y H:i', strtotime('+3 days'))
        ];
    }

    /**
     * Generate MBWay payment
     * In production, integrate with actual PSP (e.g., Easypay, Ifthenpay, etc.)
     * 
     * Configuration needed in .env:
     * - MBWAY_API_KEY: Chave de API MBWay fornecida pelo PSP
     * - MBWAY_ACCOUNT_ID: ID da conta MBWay (se aplicável)
     * - PSP_PROVIDER: easypay, ifthenpay, unipay, stripe
     * - PSP_ENVIRONMENT: sandbox ou production
     * 
     * See docs/PAYMENT_SETUP.md for detailed setup instructions
     */
    public function generateMBWayPayment(float $amount, string $phone, int $subscriptionId, ?int $invoiceId = null): array
    {
        // Validate phone number (Portuguese format)
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) != 9 || !preg_match('/^9[0-9]{8}$/', $phone)) {
            throw new \Exception('Número de telefone inválido. Deve ter 9 dígitos e começar com 9.');
        }
        
        $externalPaymentId = 'mbway_' . uniqid();
        
        // Create payment record
        $paymentId = $this->paymentModel->create([
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'user_id' => $this->getUserIdFromSubscription($subscriptionId),
            'amount' => $amount,
            'payment_method' => 'mbway',
            'status' => 'pending',
            'external_payment_id' => $externalPaymentId,
            'metadata' => [
                'phone' => $phone,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+30 minutes'))
            ]
        ]);
        
        // In production, call PSP API here to initiate payment
        // For now, return mock data
        return [
            'payment_id' => $paymentId,
            'phone' => $phone,
            'amount' => number_format($amount, 2, ',', ''),
            'external_payment_id' => $externalPaymentId,
            'expires_at' => date('d/m/Y H:i', strtotime('+30 minutes')),
            'message' => 'Será enviada uma notificação para o seu telemóvel. Confirme o pagamento na app MBWay.'
        ];
    }
    
    /**
     * Generate SEPA Direct Debit mandate
     */
    public function generateSEPAMandate(float $amount, array $bankData, int $subscriptionId, ?int $invoiceId = null): array
    {
        // Validate bank data
        if (empty($bankData['iban']) || empty($bankData['account_holder'])) {
            throw new \Exception('Dados bancários incompletos.');
        }
        
        // Validate IBAN format (basic validation)
        $iban = preg_replace('/\s+/', '', strtoupper($bankData['iban']));
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            throw new \Exception('IBAN inválido.');
        }
        
        $mandateReference = 'SEPA-' . date('Ymd') . '-' . $subscriptionId;
        $externalPaymentId = 'sepa_' . uniqid();
        
        // Create payment record
        $paymentId = $this->paymentModel->create([
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'user_id' => $this->getUserIdFromSubscription($subscriptionId),
            'amount' => $amount,
            'payment_method' => 'sepa',
            'status' => 'pending',
            'external_payment_id' => $externalPaymentId,
            'reference' => $mandateReference,
            'metadata' => [
                'iban' => $iban,
                'account_holder' => $bankData['account_holder'],
                'bic' => $bankData['bic'] ?? null,
                'mandate_reference' => $mandateReference
            ]
        ]);
        
        return [
            'payment_id' => $paymentId,
            'mandate_reference' => $mandateReference,
            'amount' => number_format($amount, 2, ',', ''),
            'iban' => $this->maskIBAN($iban),
            'account_holder' => $bankData['account_holder'],
            'message' => 'O débito direto será processado em 2-3 dias úteis.'
        ];
    }

    /**
     * Confirm payment completion (called by webhook)
     */
    public function confirmPayment(string $externalPaymentId, array $webhookData = []): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        try {
            $db->beginTransaction();
            
            $payment = $this->paymentModel->findByExternalId($externalPaymentId);
            if (!$payment) {
                throw new \Exception("Payment not found: {$externalPaymentId}");
            }
            
            if ($payment['status'] === 'completed') {
                // Already processed
                $db->commit();
                return true;
            }
            
            // Update payment status
            $this->paymentModel->updateStatus($payment['id'], 'completed', $externalPaymentId);
            
            // Update invoice if exists
            if ($payment['invoice_id']) {
                $this->invoiceModel->markAsPaid($payment['invoice_id']);
            }
            
            // Update subscription
            if ($payment['subscription_id']) {
                $subscription = $this->subscriptionModel->findById($payment['subscription_id']);
                if ($subscription) {
                    $newPeriodEnd = date('Y-m-d H:i:s', strtotime('+1 month', strtotime($subscription['current_period_end'])));
                    $this->subscriptionModel->update($payment['subscription_id'], [
                        'status' => 'active',
                        'current_period_start' => $subscription['current_period_end'],
                        'current_period_end' => $newPeriodEnd
                    ]);
                }
            }
            
            $db->commit();
            return true;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Payment confirmation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark payment as failed
     */
    public function markPaymentAsFailed(string $externalPaymentId, string $reason = ''): bool
    {
        $payment = $this->paymentModel->findByExternalId($externalPaymentId);
        if (!$payment) {
            return false;
        }
        
        return $this->paymentModel->updateStatus($payment['id'], 'failed');
    }

    /**
     * Verify payment status
     */
    public function verifyPaymentStatus(string $externalPaymentId): ?array
    {
        return $this->paymentModel->findByExternalId($externalPaymentId);
    }
    
    /**
     * Get user ID from subscription
     */
    protected function getUserIdFromSubscription(int $subscriptionId): int
    {
        $subscription = $this->subscriptionModel->findById($subscriptionId);
        return $subscription['user_id'] ?? 0;
    }
    
    /**
     * Mask IBAN for display (show only last 4 digits)
     */
    protected function maskIBAN(string $iban): string
    {
        if (strlen($iban) <= 8) {
            return $iban;
        }
        return '****' . substr($iban, -4);
    }
    
    /**
     * Get payment methods available
     */
    public function getAvailablePaymentMethods(): array
    {
        return [
            'multibanco' => [
                'name' => 'Multibanco',
                'icon' => 'bi bi-bank',
                'description' => 'Pague com referência Multibanco',
                'available' => true
            ],
            'mbway' => [
                'name' => 'MBWay',
                'icon' => 'bi bi-phone',
                'description' => 'Pague com MBWay',
                'available' => true
            ],
            'sepa' => [
                'name' => 'Débito Direto SEPA',
                'icon' => 'bi bi-arrow-repeat',
                'description' => 'Débito automático mensal',
                'available' => true
            ],
            'card' => [
                'name' => 'Cartão de Crédito/Débito',
                'icon' => 'bi bi-credit-card',
                'description' => 'Pague com cartão',
                'available' => false // To be implemented
            ]
        ];
    }
}

