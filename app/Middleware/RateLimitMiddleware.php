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
        'demo_access' => ['max_attempts' => 3, 'window' => 3600], // 3 attempts per identifier (email/IP) per hour
        'pilot_signup' => ['max_attempts' => 3, 'window' => 3600], // 3 attempts per IP per hour (persistent by IP)
    ];

    /**
     * Endpoints that use DB-backed rate limiting (by IP), so they work for cookie-less clients (e.g. bots).
     */
    protected static $persistentEndpoints = ['pilot_signup'];

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

        $identifier = $identifier ?? self::getClientIdentifier();

        if (in_array($endpoint, self::$persistentEndpoints, true)) {
            return self::checkPersistent($endpoint, $identifier);
        }

        $limit = self::$limits[$endpoint];
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
     * Check rate limit using DB (persistent by IP) for cookie-less clients.
     */
    protected static function checkPersistent(string $endpoint, string $identifier): array
    {
        $limit = self::$limits[$endpoint] ?? null;
        if (!$limit) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'reset_at' => 0];
        }

        global $db;
        if (!$db) {
            return ['allowed' => true, 'remaining' => $limit['max_attempts'], 'reset_at' => 0];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("
            SELECT attempts, window_ends_at FROM rate_limits
            WHERE endpoint = :endpoint AND identifier = :identifier LIMIT 1
        ");
        $stmt->execute([':endpoint' => $endpoint, ':identifier' => $identifier]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['allowed' => true, 'remaining' => $limit['max_attempts'], 'reset_at' => time() + $limit['window']];
        }

        $windowEndsAt = strtotime($row['window_ends_at']);
        if (time() >= $windowEndsAt) {
            return ['allowed' => true, 'remaining' => $limit['max_attempts'], 'reset_at' => time() + $limit['window']];
        }

        $attempts = (int) $row['attempts'];
        $allowed = $attempts < $limit['max_attempts'];
        $remaining = max(0, $limit['max_attempts'] - $attempts);

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $windowEndsAt
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

        $identifier = $identifier ?? self::getClientIdentifier();

        if (in_array($endpoint, self::$persistentEndpoints, true)) {
            self::recordAttemptPersistent($endpoint, $identifier);
            return;
        }

        $limit = self::$limits[$endpoint];
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
     * Record an attempt in DB for persistent rate limiting.
     */
    protected static function recordAttemptPersistent(string $endpoint, string $identifier): void
    {
        $limit = self::$limits[$endpoint] ?? null;
        if (!$limit) {
            return;
        }

        global $db;
        if (!$db) {
            return;
        }

        $windowSeconds = $limit['window'];
        $windowEndsAt = date('Y-m-d H:i:s', time() + $windowSeconds);

        $stmt = $db->prepare("
            INSERT INTO rate_limits (endpoint, identifier, attempts, window_ends_at, updated_at)
            VALUES (:endpoint, :identifier, 1, :window_ends_at, NOW())
            ON DUPLICATE KEY UPDATE
                attempts = IF(NOW() >= window_ends_at, 1, attempts + 1),
                window_ends_at = IF(NOW() >= window_ends_at, :window_ends_at2, window_ends_at),
                updated_at = NOW()
        ");
        $stmt->execute([
            ':endpoint' => $endpoint,
            ':identifier' => $identifier,
            ':window_ends_at' => $windowEndsAt,
            ':window_ends_at2' => $windowEndsAt
        ]);
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

        if (in_array($endpoint, self::$persistentEndpoints, true)) {
            global $db;
            if ($db) {
                $stmt = $db->prepare("DELETE FROM rate_limits WHERE endpoint = :endpoint AND identifier = :identifier");
                $stmt->execute([':endpoint' => $endpoint, ':identifier' => $identifier]);
            }
            return;
        }

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
            $resetTime = isset($check['reset_at']) && $check['reset_at'] ? date('H:i:s', $check['reset_at']) : 'alguns minutos';
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
