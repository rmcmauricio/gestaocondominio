<?php

namespace App\Services;

class SecurityLogger
{
    protected $logFile;
    protected $logDir;

    public function __construct()
    {
        $this->logDir = __DIR__ . '/../../logs/security';
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }
        $this->logFile = $this->logDir . '/security_' . date('Y-m-d') . '.log';
    }

    /**
     * Get client IP address
     */
    protected function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // If X-Forwarded-For contains multiple IPs, take the first one
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        
        return $ip;
    }

    /**
     * Get user agent
     */
    protected function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }

    /**
     * Get current user ID if available
     */
    protected function getUserId(): ?int
    {
        return $_SESSION['user']['id'] ?? null;
    }

    /**
     * Log security event
     * 
     * @param string $event Event type (e.g., 'login_failed', 'unauthorized_access', 'password_reset')
     * @param array $context Additional context data
     * @param string $severity Severity level: 'low', 'medium', 'high', 'critical'
     */
    public function log(string $event, array $context = [], string $severity = 'medium'): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'severity' => $severity,
            'ip' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
            'user_id' => $this->getUserId(),
            'context' => $context
        ];

        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        
        // Write to log file
        @file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
        
        // For critical events, also log to main error log
        if ($severity === 'critical') {
            error_log("SECURITY CRITICAL: {$event} - " . json_encode($context));
        }
    }

    /**
     * Log failed login attempt
     */
    public function logFailedLogin(string $email, ?string $reason = null): void
    {
        $this->log('login_failed', [
            'email' => $email,
            'reason' => $reason ?? 'invalid_credentials'
        ], 'high');
    }

    /**
     * Log successful login
     */
    public function logSuccessfulLogin(int $userId, string $email): void
    {
        $this->log('login_success', [
            'email' => $email
        ], 'low');
    }

    /**
     * Log unauthorized access attempt
     */
    public function logUnauthorizedAccess(string $resource, ?string $action = null): void
    {
        $this->log('unauthorized_access', [
            'resource' => $resource,
            'action' => $action
        ], 'high');
    }

    /**
     * Log password reset request
     */
    public function logPasswordResetRequest(string $email): void
    {
        $this->log('password_reset_request', [
            'email' => $email
        ], 'medium');
    }

    /**
     * Log password reset success
     */
    public function logPasswordResetSuccess(string $email): void
    {
        $this->log('password_reset_success', [
            'email' => $email
        ], 'medium');
    }

    /**
     * Log sensitive data access
     */
    public function logSensitiveDataAccess(string $dataType, ?int $targetUserId = null): void
    {
        $this->log('sensitive_data_access', [
            'data_type' => $dataType,
            'target_user_id' => $targetUserId
        ], 'high');
    }

    /**
     * Log account modification
     */
    public function logAccountModification(string $action, int $targetUserId, array $changes = []): void
    {
        $this->log('account_modification', [
            'action' => $action,
            'target_user_id' => $targetUserId,
            'changes' => $changes
        ], 'high');
    }

    /**
     * Log API key usage
     */
    public function logApiKeyUsage(string $action, ?int $userId = null): void
    {
        $this->log('api_key_usage', [
            'action' => $action,
            'user_id' => $userId
        ], 'medium');
    }

    /**
     * Log suspicious activity
     */
    public function logSuspiciousActivity(string $activity, array $details = []): void
    {
        $this->log('suspicious_activity', [
            'activity' => $activity,
            'details' => $details
        ], 'critical');
    }

    /**
     * Get recent security events
     * 
     * @param int $limit Number of events to retrieve
     * @param string|null $severity Filter by severity
     * @return array Array of log entries
     */
    public function getRecentEvents(int $limit = 100, ?string $severity = null): array
    {
        $events = [];
        $files = glob($this->logDir . '/security_*.log');
        
        // Sort by modification time, newest first
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $entry = json_decode($line, true);
                if ($entry && (!$severity || $entry['severity'] === $severity)) {
                    $events[] = $entry;
                    if (count($events) >= $limit) {
                        break 2;
                    }
                }
            }
        }
        
        return $events;
    }

    /**
     * Clean old log files (older than specified days)
     * 
     * @param int $days Keep logs newer than this many days
     */
    public function cleanOldLogs(int $days = 90): void
    {
        $files = glob($this->logDir . '/security_*.log');
        $cutoffTime = time() - ($days * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                @unlink($file);
            }
        }
    }
}
