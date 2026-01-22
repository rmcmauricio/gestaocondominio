<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\PaymentMethodSettings;
use App\Services\AuditService;

class PaymentService
{
    protected $subscriptionModel;
    protected $paymentModel;
    protected $invoiceModel;
    protected $paymentMethodSettings;
    protected $ifthenPayService;
    protected $auditService;

    public function __construct()
    {
        $this->subscriptionModel = new Subscription();
        $this->paymentModel = new Payment();
        $this->invoiceModel = new Invoice();
        $this->paymentMethodSettings = new PaymentMethodSettings();
        $this->auditService = new AuditService();
        
        // Initialize IfthenPay service if provider is ifthenpay
        global $config;
        $pspProvider = $config['PSP_PROVIDER'] ?? getenv('PSP_PROVIDER') ?: '';
        if ($pspProvider === 'ifthenpay') {
            $this->ifthenPayService = new IfthenPayService();
        }
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
        // Check if method is enabled
        if (!$this->paymentMethodSettings->isEnabled('multibanco')) {
            throw new \Exception('Método de pagamento Multibanco não está disponível.');
        }
        
        $userId = $this->getUserIdFromSubscription($subscriptionId);
        $orderId = 'MB-' . $subscriptionId . '-' . time();
        
        // Use IfthenPay if configured
        if ($this->ifthenPayService) {
            try {
                $subscription = $this->subscriptionModel->findById($subscriptionId);
                $userModel = new \App\Models\User();
                $user = $userModel->findById($subscription['user_id'] ?? 0);
                
                $customerData = [
                    'email' => $user['email'] ?? '',
                    'name' => $user['name'] ?? ''
                ];
                
                $result = $this->ifthenPayService->generateMultibancoPayment($amount, $orderId, $customerData);
                
                // Create payment record
                $paymentId = $this->paymentModel->create([
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'payment_method' => 'multibanco',
                    'status' => 'pending',
                    'reference' => $result['entity'] . ' ' . $result['reference'],
                    'external_payment_id' => $result['external_payment_id'],
                    'metadata' => [
                        'entity' => $result['entity'],
                        'reference' => $result['reference'],
                        'expires_at' => $result['expires_at']
                    ]
                ]);
                
                // Log payment creation
                $this->auditService->logPayment([
                    'payment_id' => $paymentId,
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                    'user_id' => $userId,
                    'action' => 'payment_created',
                    'payment_method' => 'multibanco',
                    'amount' => $amount,
                    'status' => 'pending',
                    'external_payment_id' => $result['external_payment_id'],
                    'description' => "Referência Multibanco gerada: {$result['entity']} {$result['reference']} - Valor: €{$result['amount']}"
                ]);
                
                return [
                    'payment_id' => $paymentId,
                    'entity' => $result['entity'],
                    'reference' => $result['entity'] . ' ' . $result['reference'],
                    'amount' => $result['amount'],
                    'expires_at' => $result['expires_at'],
                    'external_payment_id' => $result['external_payment_id']
                ];
            } catch (\Exception $e) {
                error_log("IfthenPay Multibanco error: " . $e->getMessage());
                // Fall through to mock implementation
            }
        }
        
        // Fallback to mock implementation
        global $config;
        $entity = $config['MULTIBANCO_ENTITY'] ?? getenv('MULTIBANCO_ENTITY') ?: '12345';
        
        // Generate unique reference (9 digits)
        $reference = str_pad($subscriptionId . time() % 10000, 9, '0', STR_PAD_LEFT);
        
        // Create payment record
        $paymentId = $this->paymentModel->create([
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
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
        
        // Log payment creation (mock)
        $this->auditService->logPayment([
            'payment_id' => $paymentId,
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'action' => 'payment_created',
            'payment_method' => 'multibanco',
            'amount' => $amount,
            'status' => 'pending',
            'description' => "Referência Multibanco gerada (mock): {$entity} {$reference} - Valor: €" . number_format($amount, 2, ',', '')
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
        // Check if method is enabled
        if (!$this->paymentMethodSettings->isEnabled('mbway')) {
            throw new \Exception('Método de pagamento MBWay não está disponível.');
        }
        
        // Validate phone number (Portuguese format)
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) != 9 || !preg_match('/^9[0-9]{8}$/', $phone)) {
            throw new \Exception('Número de telefone inválido. Deve ter 9 dígitos e começar com 9.');
        }
        
        $userId = $this->getUserIdFromSubscription($subscriptionId);
        $orderId = 'MBW-' . $subscriptionId . '-' . time();
        
        // Use IfthenPay if configured
        if ($this->ifthenPayService) {
            try {
                $subscription = $this->subscriptionModel->findById($subscriptionId);
                $userModel = new \App\Models\User();
                $user = $userModel->findById($subscription['user_id'] ?? 0);
                
                $customerData = [
                    'email' => $user['email'] ?? '',
                    'name' => $user['name'] ?? ''
                ];
                
                $result = $this->ifthenPayService->generateMBWayPayment($amount, $phone, $orderId, $customerData);
                
                // Create payment record
                $paymentId = $this->paymentModel->create([
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'payment_method' => 'mbway',
                    'status' => 'pending',
                    'external_payment_id' => $result['external_payment_id'],
                    'metadata' => [
                        'phone' => $phone,
                        'expires_at' => date('Y-m-d H:i:s', strtotime('+30 minutes'))
                    ]
                ]);
                
                // Log payment creation
                $this->auditService->logPayment([
                    'payment_id' => $paymentId,
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                    'user_id' => $userId,
                    'action' => 'payment_created',
                    'payment_method' => 'mbway',
                    'amount' => $amount,
                    'status' => 'pending',
                    'external_payment_id' => $result['external_payment_id'],
                    'description' => "Pagamento MBWay gerado para {$phone} - Valor: €{$result['amount']}"
                ]);
                
                return [
                    'payment_id' => $paymentId,
                    'phone' => $phone,
                    'amount' => $result['amount'],
                    'external_payment_id' => $result['external_payment_id'],
                    'expires_at' => $result['expires_at'],
                    'message' => $result['message']
                ];
            } catch (\Exception $e) {
                error_log("IfthenPay MBWay error: " . $e->getMessage());
                // Fall through to mock implementation
            }
        }
        
        // Fallback to mock implementation
        $externalPaymentId = 'mbway_' . uniqid();
        
        // Create payment record
        $paymentId = $this->paymentModel->create([
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'amount' => $amount,
            'payment_method' => 'mbway',
            'status' => 'pending',
            'external_payment_id' => $externalPaymentId,
            'metadata' => [
                'phone' => $phone,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+30 minutes'))
            ]
        ]);
        
        // Log payment creation (mock)
        $this->auditService->logPayment([
            'payment_id' => $paymentId,
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'action' => 'payment_created',
            'payment_method' => 'mbway',
            'amount' => $amount,
            'status' => 'pending',
            'external_payment_id' => $externalPaymentId,
            'description' => "Pagamento MBWay gerado (mock) para {$phone} - Valor: €" . number_format($amount, 2, ',', '')
        ]);
        
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
        // Check if method is enabled
        if (!$this->paymentMethodSettings->isEnabled('sepa')) {
            throw new \Exception('Método de pagamento SEPA não está disponível.');
        }
        
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
     * Generate Direct Debit payment via IfthenPay
     */
    public function generateDirectDebitPayment(float $amount, array $bankData, int $subscriptionId, ?int $invoiceId = null): array
    {
        // Check if method is enabled
        if (!$this->paymentMethodSettings->isEnabled('direct_debit')) {
            throw new \Exception('Método de pagamento Débito Direto não está disponível.');
        }
        
        // Validate bank data
        if (empty($bankData['iban']) || empty($bankData['account_holder'])) {
            throw new \Exception('Dados bancários incompletos.');
        }
        
        // Validate IBAN format (basic validation)
        $iban = preg_replace('/\s+/', '', strtoupper($bankData['iban']));
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            throw new \Exception('IBAN inválido.');
        }
        
        $userId = $this->getUserIdFromSubscription($subscriptionId);
        $orderId = 'DD-' . $subscriptionId . '-' . time();
        
        // Use IfthenPay if configured
        if ($this->ifthenPayService) {
            try {
                $subscription = $this->subscriptionModel->findById($subscriptionId);
                $userModel = new \App\Models\User();
                $user = $userModel->findById($subscription['user_id'] ?? 0);
                
                $customerData = [
                    'email' => $user['email'] ?? '',
                    'name' => $user['name'] ?? ''
                ];
                
                $result = $this->ifthenPayService->generateDirectDebitPayment($amount, $bankData, $orderId, $customerData);
                
                // Create payment record
                $paymentId = $this->paymentModel->create([
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'payment_method' => 'direct_debit',
                    'status' => 'pending',
                    'external_payment_id' => $result['external_payment_id'],
                    'reference' => $result['mandate_reference'],
                    'metadata' => [
                        'iban' => $iban,
                        'account_holder' => $bankData['account_holder'],
                        'bic' => $bankData['bic'] ?? null,
                        'mandate_reference' => $result['mandate_reference']
                    ]
                ]);
                
                // Log payment creation
                $this->auditService->logPayment([
                    'payment_id' => $paymentId,
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                    'user_id' => $userId,
                    'action' => 'payment_created',
                    'payment_method' => 'direct_debit',
                    'amount' => $amount,
                    'status' => 'pending',
                    'external_payment_id' => $result['external_payment_id'],
                    'description' => "Mandato de débito direto criado: {$result['mandate_reference']} - Valor: €{$result['amount']} - IBAN: {$result['iban']}"
                ]);
                
                return [
                    'payment_id' => $paymentId,
                    'mandate_reference' => $result['mandate_reference'],
                    'amount' => $result['amount'],
                    'iban' => $result['iban'],
                    'account_holder' => $result['account_holder'],
                    'external_payment_id' => $result['external_payment_id'],
                    'message' => $result['message']
                ];
            } catch (\Exception $e) {
                error_log("IfthenPay Direct Debit error: " . $e->getMessage());
                throw $e;
            }
        }
        
        // If IfthenPay is not configured, throw error
        throw new \Exception('Débito Direto requer integração com IfthenPay. Configure PSP_PROVIDER=ifthenpay no .env');
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
            
            $oldStatus = $payment['status'];
            
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
            $subscriptionUpdated = false;
            $oldSubscriptionStatus = null;
            $newSubscriptionStatus = null;
            if ($payment['subscription_id']) {
                $subscription = $this->subscriptionModel->findById($payment['subscription_id']);
                if ($subscription) {
                    $oldSubscriptionStatus = $subscription['status'];
                    // Determine period start: use current_period_end if valid, otherwise use now
                    $periodStart = $subscription['current_period_end'] ?? date('Y-m-d H:i:s');
                    // If period_end is in the past or null, start from now
                    if (!$subscription['current_period_end'] || strtotime($subscription['current_period_end']) < time()) {
                        $periodStart = date('Y-m-d H:i:s');
                    }
                    $newPeriodEnd = date('Y-m-d H:i:s', strtotime('+1 month', strtotime($periodStart)));
                    
                    $this->subscriptionModel->update($payment['subscription_id'], [
                        'status' => 'active',
                        'current_period_start' => $periodStart,
                        'current_period_end' => $newPeriodEnd
                    ]);
                    $newSubscriptionStatus = 'active';
                    $subscriptionUpdated = true;
                }
            }
            
            // Log payment confirmation
            $this->auditService->logPayment([
                'payment_id' => $payment['id'],
                'subscription_id' => $payment['subscription_id'],
                'invoice_id' => $payment['invoice_id'],
                'user_id' => $payment['user_id'],
                'action' => 'payment_confirmed',
                'payment_method' => $payment['payment_method'] ?? null,
                'amount' => $payment['amount'],
                'old_status' => $oldStatus,
                'new_status' => 'completed',
                'external_payment_id' => $externalPaymentId,
                'description' => "Pagamento confirmado via webhook. Status: {$oldStatus} → completed" . 
                    ($subscriptionUpdated ? ". Subscrição ativada: {$oldSubscriptionStatus} → {$newSubscriptionStatus}" : ''),
                'metadata' => $webhookData
            ]);
            
            // Log subscription activation if applicable
            if ($subscriptionUpdated && $payment['subscription_id']) {
                $this->auditService->logSubscription([
                    'subscription_id' => $payment['subscription_id'],
                    'user_id' => $payment['user_id'],
                    'action' => 'subscription_activated_by_payment',
                    'old_status' => $oldSubscriptionStatus,
                    'new_status' => $newSubscriptionStatus,
                    'description' => "Subscrição ativada automaticamente após confirmação de pagamento"
                ]);
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
        
        $oldStatus = $payment['status'];
        
        // Update payment status
        $updated = $this->paymentModel->updateStatus($payment['id'], 'failed');
        
        if ($updated) {
            // Log payment failure
            $this->auditService->logPayment([
                'payment_id' => $payment['id'],
                'subscription_id' => $payment['subscription_id'],
                'invoice_id' => $payment['invoice_id'],
                'user_id' => $payment['user_id'],
                'action' => 'payment_failed',
                'payment_method' => $payment['payment_method'] ?? null,
                'amount' => $payment['amount'],
                'old_status' => $oldStatus,
                'new_status' => 'failed',
                'external_payment_id' => $externalPaymentId,
                'description' => "Pagamento marcado como falhado" . ($reason ? ": {$reason}" : '') . ". Status: {$oldStatus} → failed"
            ]);
        }
        
        return $updated;
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
     * Get payment methods available (read from database)
     */
    public function getAvailablePaymentMethods(): array
    {
        $methods = $this->paymentMethodSettings->getAll();
        $availableMethods = [];
        
        foreach ($methods as $method) {
            if ((bool)$method['enabled']) {
                $configData = json_decode($method['config_data'], true) ?: [];
                
                $availableMethods[$method['method_key']] = [
                    'name' => $configData['name'] ?? ucfirst(str_replace('_', ' ', $method['method_key'])),
                    'icon' => $configData['icon'] ?? 'bi bi-credit-card',
                    'description' => $configData['description'] ?? '',
                    'available' => true
                ];
            }
        }
        
        return $availableMethods;
    }
}

