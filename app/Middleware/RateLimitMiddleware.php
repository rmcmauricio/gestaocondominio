<?php

namespace App\Middleware;

class RateLimitMiddleware
{
    /**
     * Rate limit configuration
     * Format: ['endpoint' => ['max_attempts' => X, 'window' => Y seconds]]
     */
    protected static $limits = [
        'login' => ['max_attempts' => 5, 'window' => 900], // 5 attempts per 15 minutes
        'password_reset' => ['max_attempts' => 3, 'window' => 3600], // 3 attempts per hour
        'register' => ['max_attempts' => 3, 'window' => 3600], // 3 attempts per hour
        'forgot_password' => ['max_attempts' => 5, 'window' => 3600], // 5 attempts per hour
        'api_key_generate' => ['max_attempts' => 3, 'window' => 3600], // 3 attempts per hour
        'file_upload' => ['max_attempts' => 20, 'window' => 3600], // 20 uploads per hour
        'password_change' => ['max_attempts' => 5, 'window' => 3600], // 5 attempts per hour
    ];

    /**
     * Check rate limit for an endpoint
     * 
     * @param string $endpoint Endpoint identifier (e.g., 'login', 'password_reset')
     * @param string|null $identifier Optional identifier (email, IP, etc.) - defaults to IP
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    public static function check(string $endpoint, ?string $identifier = null): array
    {
        if (!isset(self::$limits[$endpoint])) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'reset_at' => 0];
        }

        $limit = self::$limits[$endpoint];
        $identifier = $identifier ?? self::getClientIdentifier();
        $key = "rate_limit_{$endpoint}_{$identifier}";
        
        // Get current attempts from session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $now = time();
        $attempts = $_SESSION[$key] ?? ['count' => 0, 'reset_at' => $now + $limit['window']];
        
        // Reset if window expired
        if ($now >= $attempts['reset_at']) {
            $attempts = ['count' => 0, 'reset_at' => $now + $limit['window']];
        }
        
        $allowed = $attempts['count'] < $limit['max_attempts'];
        $remaining = max(0, $limit['max_attempts'] - $attempts['count']);
        
        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $attempts['reset_at']
        ];
    }

    /**
     * Record an attempt
     * 
     * @param string $endpoint Endpoint identifier
     * @param string|null $identifier Optional identifier
     */
    public static function recordAttempt(string $endpoint, ?string $identifier = null): void
    {
        if (!isset(self::$limits[$endpoint])) {
            return;
        }

        $limit = self::$limits[$endpoint];
        $identifier = $identifier ?? self::getClientIdentifier();
        $key = "rate_limit_{$endpoint}_{$identifier}";
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $now = time();
        $attempts = $_SESSION[$key] ?? ['count' => 0, 'reset_at' => $now + $limit['window']];
        
        // Reset if window expired
        if ($now >= $attempts['reset_at']) {
            $attempts = ['count' => 0, 'reset_at' => $now + $limit['window']];
        }
        
        $attempts['count']++;
        $_SESSION[$key] = $attempts;
    }

    /**
     * Reset rate limit for an endpoint
     * 
     * @param string $endpoint Endpoint identifier
     * @param string|null $identifier Optional identifier
     */
    public static function reset(string $endpoint, ?string $identifier = null): void
    {
        $identifier = $identifier ?? self::getClientIdentifier();
        $key = "rate_limit_{$endpoint}_{$identifier}";
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        unset($_SESSION[$key]);
    }

    /**
     * Require rate limit check - throws exception if limit exceeded
     * 
     * @param string $endpoint Endpoint identifier
     * @param string|null $identifier Optional identifier
     * @throws \Exception If rate limit exceeded
     */
    public static function require(string $endpoint, ?string $identifier = null): void
    {
        $check = self::check($endpoint, $identifier);
        
        if (!$check['allowed']) {
            $resetTime = date('H:i:s', $check['reset_at']);
            throw new \Exception("Muitas tentativas. Por favor, tente novamente após {$resetTime}.");
        }
    }

    /**
     * Get client identifier (IP address)
     * 
     * @return string Client IP address
     */
    protected static function getClientIdentifier(): string
    {
        // Try to get real IP address (considering proxies)
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // If X-Forwarded-For contains multiple IPs, take the first one
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        
        return $ip;
    }
}
