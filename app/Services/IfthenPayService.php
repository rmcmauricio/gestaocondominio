<?php

namespace App\Services;

class IfthenPayService
{
    protected $apiKey;
    protected $antiPhishingKey;
    protected $ddKey;
    protected $multibancoEntity;
    protected $multibancoSubEntity;
    protected $mbwayKey;
    protected $environment;
    protected $baseUrl;

    public function __construct()
    {
        global $config;
        
        $this->apiKey = $config['IFTHENPAY_API_KEY'] ?? getenv('IFTHENPAY_API_KEY') ?: '';
        $this->antiPhishingKey = $config['IFTHENPAY_ANTI_PHISHING_KEY'] ?? getenv('IFTHENPAY_ANTI_PHISHING_KEY') ?: '';
        $this->ddKey = $config['IFTHENPAY_DD_KEY'] ?? getenv('IFTHENPAY_DD_KEY') ?: '';
        $this->multibancoEntity = $config['MULTIBANCO_ENTITY'] ?? getenv('MULTIBANCO_ENTITY') ?: '';
        $this->multibancoSubEntity = $config['MULTIBANCO_SUB_ENTITY'] ?? getenv('MULTIBANCO_SUB_ENTITY') ?: '';
        $this->mbwayKey = $config['MBWAY_KEY'] ?? getenv('MBWAY_KEY') ?: '';
        $this->environment = $config['PSP_ENVIRONMENT'] ?? getenv('PSP_ENVIRONMENT') ?: 'sandbox';
        
        if ($this->environment === 'production') {
            $this->baseUrl = $config['IFTHENPAY_PRODUCTION_URL'] ?? getenv('IFTHENPAY_PRODUCTION_URL') ?: 'https://ifthenpay.com/api';
        } else {
            $this->baseUrl = $config['IFTHENPAY_SANDBOX_URL'] ?? getenv('IFTHENPAY_SANDBOX_URL') ?: 'https://ifthenpay.com/api/sandbox';
        }
    }

    /**
     * Generate Multibanco payment reference via IfthenPay API
     */
    public function generateMultibancoPayment(float $amount, string $orderId, array $customerData = []): array
    {
        $this->logApiCall('multibanco', 'generate', ['amount' => $amount, 'orderId' => $orderId]);
        
        try {
            $url = $this->baseUrl . '/multibanco/create';
            
            $payload = [
                'chave' => $this->apiKey,
                'entidade' => $this->multibancoEntity,
                'subentidade' => $this->multibancoSubEntity,
                'valor' => number_format($amount, 2, '.', ''),
                'idpedido' => $orderId,
                'validade' => date('Y-m-d', strtotime('+3 days')),
                'backoffice' => $this->getCallbackUrl('multibanco'),
                'url_retorno' => $this->getReturnUrl($orderId)
            ];
            
            if (!empty($customerData['email'])) {
                $payload['email'] = $customerData['email'];
            }
            
            $response = $this->makeHttpRequest($url, 'POST', $payload);
            
            if (!$response || !isset($response['entidade'])) {
                throw new \Exception('Invalid response from IfthenPay API');
            }
            
            $this->logApiCall('multibanco', 'response', $response);
            
            return [
                'success' => true,
                'entity' => $response['entidade'],
                'reference' => $response['referencia'],
                'amount' => number_format($amount, 2, ',', ''),
                'expires_at' => $response['validade'] ?? date('d/m/Y H:i', strtotime('+3 days')),
                'request_id' => $response['idpedido'] ?? $orderId,
                'external_payment_id' => $response['idpedido'] ?? $orderId
            ];
        } catch (\Exception $e) {
            $this->logError('multibanco', 'generate', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate MBWay payment via IfthenPay API
     */
    public function generateMBWayPayment(float $amount, string $phone, string $orderId, array $customerData = []): array
    {
        $this->logApiCall('mbway', 'generate', ['amount' => $amount, 'phone' => $phone, 'orderId' => $orderId]);
        
        try {
            $url = $this->baseUrl . '/mbway/create';
            
            $payload = [
                'chave' => $this->mbwayKey,
                'valor' => number_format($amount, 2, '.', ''),
                'idpedido' => $orderId,
                'telemovel' => $phone,
                'email' => $customerData['email'] ?? '',
                'backoffice' => $this->getCallbackUrl('mbway'),
                'url_retorno' => $this->getReturnUrl($orderId)
            ];
            
            $response = $this->makeHttpRequest($url, 'POST', $payload);
            
            if (!$response || !isset($response['idpedido'])) {
                throw new \Exception('Invalid response from IfthenPay API');
            }
            
            $this->logApiCall('mbway', 'response', $response);
            
            return [
                'success' => true,
                'phone' => $phone,
                'amount' => number_format($amount, 2, ',', ''),
                'request_id' => $response['idpedido'],
                'external_payment_id' => $response['idpedido'],
                'expires_at' => date('d/m/Y H:i', strtotime('+30 minutes')),
                'message' => 'Será enviada uma notificação para o seu telemóvel. Confirme o pagamento na app MBWay.'
            ];
        } catch (\Exception $e) {
            $this->logError('mbway', 'generate', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate Direct Debit mandate via IfthenPay API
     */
    public function generateDirectDebitPayment(float $amount, array $bankData, string $orderId, array $customerData = []): array
    {
        $this->logApiCall('direct_debit', 'generate', ['amount' => $amount, 'orderId' => $orderId]);
        
        try {
            $url = $this->baseUrl . '/dd/create';
            
            $iban = preg_replace('/\s+/', '', strtoupper($bankData['iban']));
            
            $payload = [
                'chave' => $this->ddKey,
                'valor' => number_format($amount, 2, '.', ''),
                'idpedido' => $orderId,
                'iban' => $iban,
                'titular' => $bankData['account_holder'],
                'email' => $customerData['email'] ?? '',
                'backoffice' => $this->getCallbackUrl('direct_debit'),
                'url_retorno' => $this->getReturnUrl($orderId)
            ];
            
            if (!empty($bankData['bic'])) {
                $payload['bic'] = $bankData['bic'];
            }
            
            $response = $this->makeHttpRequest($url, 'POST', $payload);
            
            if (!$response || !isset($response['idpedido'])) {
                throw new \Exception('Invalid response from IfthenPay API');
            }
            
            $this->logApiCall('direct_debit', 'response', $response);
            
            $mandateReference = $response['referencia_mandato'] ?? 'DD-' . date('Ymd') . '-' . $orderId;
            
            return [
                'success' => true,
                'mandate_reference' => $mandateReference,
                'amount' => number_format($amount, 2, ',', ''),
                'iban' => $this->maskIBAN($iban),
                'account_holder' => $bankData['account_holder'],
                'request_id' => $response['idpedido'],
                'external_payment_id' => $response['idpedido'],
                'message' => 'O mandato de débito direto será processado em 2-3 dias úteis.'
            ];
        } catch (\Exception $e) {
            $this->logError('direct_debit', 'generate', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check payment status
     */
    public function checkPaymentStatus(string $requestId): ?array
    {
        $this->logApiCall('status', 'check', ['requestId' => $requestId]);
        
        try {
            $url = $this->baseUrl . '/status/' . urlencode($requestId);
            
            $response = $this->makeHttpRequest($url, 'GET');
            
            if (!$response) {
                return null;
            }
            
            return $response;
        } catch (\Exception $e) {
            $this->logError('status', 'check', $e->getMessage());
            return null;
        }
    }

    /**
     * Validate callback using anti-phishing key
     */
    public function validateCallback(array $callbackData): bool
    {
        $receivedKey = $callbackData['key'] ?? '';
        
        if (empty($this->antiPhishingKey)) {
            // In development, accept if key is not configured
            $appEnv = $this->environment === 'production' ? 'production' : 'development';
            if ($appEnv === 'development') {
                return true;
            }
            return false;
        }
        
        return hash_equals($this->antiPhishingKey, $receivedKey);
    }

    /**
     * Process Multibanco callback
     */
    public function processMultibancoCallback(array $data): bool
    {
        $this->logCallback('multibanco', $data);
        
        if (!$this->validateCallback($data)) {
            $this->logError('multibanco', 'callback', 'Invalid anti-phishing key');
            return false;
        }
        
        // Validate required fields
        $requiredFields = ['orderId', 'amount', 'requestId', 'entity', 'reference'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $this->logError('multibanco', 'callback', "Missing required field: {$field}");
                return false;
            }
        }
        
        return true;
    }

    /**
     * Process MBWay callback
     */
    public function processMBWayCallback(array $data): bool
    {
        $this->logCallback('mbway', $data);
        
        if (!$this->validateCallback($data)) {
            $this->logError('mbway', 'callback', 'Invalid anti-phishing key');
            return false;
        }
        
        // Validate required fields
        $requiredFields = ['orderId', 'amount', 'requestId', 'mbway_phone'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $this->logError('mbway', 'callback', "Missing required field: {$field}");
                return false;
            }
        }
        
        return true;
    }

    /**
     * Process Direct Debit callback
     */
    public function processDirectDebitCallback(array $data): bool
    {
        $this->logCallback('direct_debit', $data);
        
        if (!$this->validateCallback($data)) {
            $this->logError('direct_debit', 'callback', 'Invalid anti-phishing key');
            return false;
        }
        
        // Validate required fields
        $requiredFields = ['orderId', 'amount', 'requestId', 'dd_mandate_reference'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $this->logError('direct_debit', 'callback', "Missing required field: {$field}");
                return false;
            }
        }
        
        return true;
    }

    /**
     * Make HTTP request to IfthenPay API with retry logic
     */
    protected function makeHttpRequest(string $url, string $method = 'GET', array $data = [], int $maxRetries = 3): ?array
    {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxRetries) {
            $attempt++;
            
            try {
                $ch = curl_init();
                
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json'
                    ]
                ]);
                
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                $curlErrno = curl_errno($ch);
                
                curl_close($ch);
                
                // Check for cURL errors
                if ($error) {
                    // Retry on timeout or connection errors
                    if ($curlErrno === CURLE_OPERATION_TIMEOUTED || 
                        $curlErrno === CURLE_COULDNT_CONNECT || 
                        $curlErrno === CURLE_COULDNT_RESOLVE_HOST) {
                        if ($attempt < $maxRetries) {
                            $this->logError('http', 'request', "Attempt {$attempt} failed: {$error}. Retrying...");
                            usleep(500000 * $attempt); // Exponential backoff: 0.5s, 1s, 1.5s
                            continue;
                        }
                    }
                    throw new \Exception("cURL error: {$error}");
                }
                
                // Check for HTTP errors
                if ($httpCode >= 500 && $attempt < $maxRetries) {
                    // Retry on server errors (5xx)
                    $this->logError('http', 'request', "HTTP {$httpCode} on attempt {$attempt}. Retrying...");
                    usleep(500000 * $attempt); // Exponential backoff
                    continue;
                }
                
                if ($httpCode >= 400) {
                    // Don't retry on client errors (4xx)
                    throw new \Exception("HTTP error: {$httpCode}");
                }
                
                // Success - decode and return
                $decoded = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception("Invalid JSON response: " . json_last_error_msg());
                }
                
                return $decoded;
                
            } catch (\Exception $e) {
                $lastException = $e;
                
                // Check if we should retry
                $isRetryable = (
                    strpos($e->getMessage(), 'timeout') !== false ||
                    strpos($e->getMessage(), 'HTTP error: 5') === 0 ||
                    strpos($e->getMessage(), 'CURLE_') !== false
                );
                
                if ($isRetryable && $attempt < $maxRetries) {
                    $this->logError('http', 'request', "Attempt {$attempt} failed: {$e->getMessage()}. Retrying...");
                    usleep(500000 * $attempt); // Exponential backoff
                    continue;
                }
                
                // Not retryable or max retries reached
                throw $e;
            }
        }
        
        // Should never reach here, but just in case
        if ($lastException) {
            throw $lastException;
        }
        
        throw new \Exception("Failed to make HTTP request after {$maxRetries} attempts");
    }

    /**
     * Get callback URL for payment type
     */
    protected function getCallbackUrl(string $type): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = defined('BASE_PATH') ? BASE_PATH : '';
        $baseUrl = $protocol . '://' . $host . ($basePath ? '/' . $basePath : '');
        
        return $baseUrl . '/webhooks/ifthenpay';
    }

    /**
     * Get return URL for payment
     */
    protected function getReturnUrl(string $orderId): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = defined('BASE_PATH') ? BASE_PATH : '';
        $baseUrl = $protocol . '://' . $host . ($basePath ? '/' . $basePath : '');
        
        return $baseUrl . '/payments/status/' . urlencode($orderId);
    }

    /**
     * Mask IBAN for display
     */
    protected function maskIBAN(string $iban): string
    {
        if (strlen($iban) <= 8) {
            return $iban;
        }
        return '****' . substr($iban, -4);
    }

    /**
     * Log API call
     */
    protected function logApiCall(string $type, string $action, array $data): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/payments.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] API Call - Type: {$type}, Action: {$action}, Data: " . json_encode($data) . "\n";
        
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
    }

    /**
     * Log callback
     */
    protected function logCallback(string $type, array $data): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/payments.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] Callback - Type: {$type}, Data: " . json_encode($data) . "\n";
        
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
    }

    /**
     * Log error
     */
    protected function logError(string $type, string $action, string $message): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/payments.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] ERROR - Type: {$type}, Action: {$action}, Message: {$message}\n";
        
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
        error_log("IfthenPay Error [{$type}] [{$action}]: {$message}");
    }
}
