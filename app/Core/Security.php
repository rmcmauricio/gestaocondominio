<?php

namespace App\Core;

class Security
{
    /**
     * Hash password using Argon2ID
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /**
     * Verify password against hash
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate random token
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate secure random string
     */
    public static function generateRandomString(int $length = 16): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Sanitize input
     */
    public static function sanitize(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validate email
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = self::generateToken();
        }
        
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCSRFToken(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // In development, log for debugging
        if (defined('APP_ENV') && APP_ENV === 'development') {
            if (!isset($_SESSION['csrf_token'])) {
                error_log("CSRF Error: No token in session");
            } elseif (empty($token)) {
                error_log("CSRF Error: Empty token received");
            } elseif (!hash_equals($_SESSION['csrf_token'], $token)) {
                error_log("CSRF Error: Token mismatch. Session: " . substr($_SESSION['csrf_token'], 0, 10) . "... Received: " . substr($token, 0, 10) . "...");
            }
        }
        
        return isset($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate TOTP secret for 2FA
     */
    public static function generateTOTPSecret(): string
    {
        return base32_encode(random_bytes(20));
    }

    /**
     * Verify TOTP code
     */
    public static function verifyTOTP(string $secret, string $code): bool
    {
        // Simple TOTP verification (in production, use a library like Google Authenticator)
        $time = floor(time() / 30);
        $expectedCode = self::generateTOTP($secret, $time);
        
        return hash_equals($expectedCode, $code);
    }

    /**
     * Generate TOTP code
     */
    protected static function generateTOTP(string $secret, int $time): string
    {
        $key = base32_decode($secret);
        $time = pack('N*', 0) . pack('N*', $time);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord($hash[19]) & 0xf;
        $code = (
            ((ord($hash[$offset+0]) & 0x7f) << 24) |
            ((ord($hash[$offset+1]) & 0xff) << 16) |
            ((ord($hash[$offset+2]) & 0xff) << 8) |
            (ord($hash[$offset+3]) & 0xff)
        ) % 1000000;
        
        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Base32 encoding helper
 */
function base32_encode($data): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $encoded = '';
    $bits = 0;
    $value = 0;
    
    for ($i = 0; $i < strlen($data); $i++) {
        $value = ($value << 8) | ord($data[$i]);
        $bits += 8;
        
        while ($bits >= 5) {
            $encoded .= $chars[($value >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }
    
    if ($bits > 0) {
        $encoded .= $chars[($value << (5 - $bits)) & 31];
    }
    
    return $encoded;
}

/**
 * Base32 decoding helper
 */
function base32_decode($data): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $decoded = '';
    $bits = 0;
    $value = 0;
    
    for ($i = 0; $i < strlen($data); $i++) {
        $value = ($value << 5) | strpos($chars, $data[$i]);
        $bits += 5;
        
        if ($bits >= 8) {
            $decoded .= chr(($value >> ($bits - 8)) & 255);
            $bits -= 8;
        }
    }
    
    return $decoded;
}

