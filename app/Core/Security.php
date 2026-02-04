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
     * @param int $length Desired length in characters (will generate length/2 bytes)
     */
    public static function generateRandomString(int $length = 16): string
    {
        return bin2hex(random_bytes((int)ceil($length / 2)));
    }

    /**
     * Sanitize input
     */
    public static function sanitize(?string $input): string
    {
        if ($input === null) {
            return '';
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitize input (nullable)
     */
    public static function sanitizeNullable(?string $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize HTML content - allows safe HTML tags but removes scripts and dangerous attributes
     * @param string|null $html HTML content to sanitize
     * @return string Sanitized HTML
     */
    public static function sanitizeHtml(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // First, remove dangerous tags and their content
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $html ?? '') ?? '';
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/i', '', $html ?? '') ?? '';
        $html = preg_replace('/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/i', '', $html ?? '') ?? '';
        $html = preg_replace('/<(object|embed)\b[^<]*(?:(?!<\/\1>)<[^<]*)*<\/\1>/i', '', $html ?? '') ?? '';
        
        // Temporarily replace img and a tags with placeholders to preserve them during strip_tags
        $placeholders = [];
        $placeholderIndex = 0;
        
        // Process and preserve img and a tags with safe attributes (including self-closing tags and tags without attributes)
        $html = preg_replace_callback('/<(a|img)(?:\s+([^>]*?))?(?:\s*\/)?>/i', function($matches) use (&$placeholders, &$placeholderIndex) {
            $tag = $matches[1];
            $attrs = $matches[2] ?? '';
            
            // Parse attributes
            $allowedAttrs = [];
            $allowedImgAttrs = ['src', 'alt', 'title', 'width', 'height', 'class', 'style'];
            $allowedLinkAttrs = ['href', 'title', 'target', 'rel', 'class'];
            
            // Extract individual attributes (handle both quoted and unquoted values)
            // Match: attr="value", attr='value', or attr=value
            preg_match_all('/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $attrs, $attrMatches, PREG_SET_ORDER);
            
            foreach ($attrMatches as $attrMatch) {
                $attrName = strtolower($attrMatch[1]);
                // Get value from whichever group matched (quoted double, quoted single, or unquoted)
                $attrValue = !empty($attrMatch[2]) ? $attrMatch[2] : (!empty($attrMatch[3]) ? $attrMatch[3] : ($attrMatch[4] ?? ''));
                
                // Skip dangerous attributes
                if (preg_match('/^on\w+/i', $attrName) || 
                    stripos($attrValue, 'javascript:') !== false ||
                    (stripos($attrValue, 'data:') !== false && stripos($attrValue, 'data:image/') === false)) {
                    continue;
                }
                
                // For img tags, only allow specific safe attributes
                if ($tag === 'img' && in_array($attrName, $allowedImgAttrs)) {
                    // Validate src attribute - allow http/https, data:image/, or paths containing storage/
                    if ($attrName === 'src') {
                        // Allow absolute URLs (http/https), data URIs, or any path containing storage/ (safe relative paths)
                        // This covers: http://..., https://..., /storage/..., storage/..., /predio/storage/..., data:image/...
                        // Also allow any URL that contains 'storage/' as it's from our own storage system
                        $attrValueTrimmed = trim($attrValue);
                        if (!empty($attrValueTrimmed)) {
                            $isHttp = preg_match('/^https?:\/\//i', $attrValueTrimmed);
                            $isDataImage = stripos($attrValueTrimmed, 'data:image/') === 0;
                            $hasStorage = strpos($attrValueTrimmed, 'storage/') !== false || strpos($attrValueTrimmed, '/storage/') !== false;
                            $isSafe = stripos($attrValueTrimmed, 'javascript:') === false && 
                                     (stripos($attrValueTrimmed, 'data:') === false || $isDataImage);
                            
                            if (($isHttp || $isDataImage || $hasStorage) && $isSafe) {
                                $allowedAttrs[] = $attrName . '="' . htmlspecialchars($attrValueTrimmed, ENT_QUOTES, 'UTF-8') . '"';
                            }
                        }
                    } else {
                        $allowedAttrs[] = $attrName . '="' . htmlspecialchars($attrValue, ENT_QUOTES, 'UTF-8') . '"';
                    }
                }
                
                // For link tags, only allow specific safe attributes
                if ($tag === 'a' && in_array($attrName, $allowedLinkAttrs)) {
                    // Validate href attribute - only allow http/https
                    if ($attrName === 'href') {
                        if (preg_match('/^https?:\/\//i', $attrValue)) {
                            $allowedAttrs[] = $attrName . '="' . htmlspecialchars($attrValue, ENT_QUOTES, 'UTF-8') . '"';
                        }
                    } else {
                        $allowedAttrs[] = $attrName . '="' . htmlspecialchars($attrValue, ENT_QUOTES, 'UTF-8') . '"';
                    }
                }
            }
            
            // Store the sanitized tag in a placeholder
            $placeholder = '___TAG_PLACEHOLDER_' . $placeholderIndex . '___';
            $placeholders[$placeholder] = '<' . $tag . (!empty($allowedAttrs) ? ' ' . implode(' ', $allowedAttrs) : '') . '>';
            $placeholderIndex++;
            
            return $placeholder;
        }, $html) ?? $html;
        
        // List of allowed HTML tags for rich text content
        $allowedTags = '<p><br><br/><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><a><img><blockquote><code><pre><div><span>';
        
        // Now strip all tags except allowed ones (placeholders will be preserved)
        $html = strip_tags($html, $allowedTags);
        
        // Restore the preserved img and a tags
        foreach ($placeholders as $placeholder => $tag) {
            $html = str_replace($placeholder, $tag, $html);
        }
        
        return trim($html ?? '');
    }

    /**
     * Validate email
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate IBAN format (basic structure validation)
     * IBAN format: 2 letters (country code) + 2 digits (check digits) + up to 30 alphanumeric characters
     */
    public static function validateIban(string $iban): bool
    {
        // Remove spaces and convert to uppercase
        $iban = strtoupper(str_replace(' ', '', trim($iban)));
        
        // Basic structure: 2 letters + 2 digits + 15-30 alphanumeric characters
        // Total length: 15-34 characters
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }
        
        // Must start with 2 letters (country code)
        if (!preg_match('/^[A-Z]{2}/', $iban)) {
            return false;
        }
        
        // After country code, must have 2 digits (check digits)
        if (!preg_match('/^[A-Z]{2}[0-9]{2}/', $iban)) {
            return false;
        }
        
        // Rest should be alphanumeric
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }
        
        return true;
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
     * Validate password strength
     * 
     * @param string $password Password to validate
     * @param string|null $email Optional email to check if password contains user info
     * @param string|null $name Optional name to check if password contains user info
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public static function validatePasswordStrength(string $password, ?string $email = null, ?string $name = null): array
    {
        $errors = [];
        
        // Minimum length
        if (strlen($password) < 8) {
            $errors[] = 'A senha deve ter pelo menos 8 caracteres.';
        }
        
        // Maximum length (prevent DoS)
        if (strlen($password) > 128) {
            $errors[] = 'A senha não pode ter mais de 128 caracteres.';
        }
        
        // Check for uppercase letter
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos uma letra maiúscula.';
        }
        
        // Check for lowercase letter
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos uma letra minúscula.';
        }
        
        // Check for number
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos um número.';
        }
        
        // Check for special character
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos um caractere especial.';
        }
        
        // Check against common passwords
        $commonPasswords = [
            'password', '12345678', '123456789', '1234567890', 'qwerty', 'abc123',
            'password123', 'admin123', 'letmein', 'welcome', 'monkey', '1234567',
            'sunshine', 'princess', 'qwerty123', 'football', 'iloveyou', '123123'
        ];
        
        if (in_array(strtolower($password), $commonPasswords)) {
            $errors[] = 'A senha é muito comum. Por favor, escolha uma senha mais segura.';
        }
        
        // Check if password contains email (if provided)
        if ($email) {
            $emailParts = explode('@', $email);
            $emailLocal = strtolower($emailParts[0] ?? '');
            if (!empty($emailLocal) && strlen($emailLocal) >= 3 && stripos($password, $emailLocal) !== false) {
                $errors[] = 'A senha não deve conter partes do seu email.';
            }
        }
        
        // Check if password contains name (if provided)
        if ($name) {
            $nameParts = explode(' ', strtolower($name));
            foreach ($nameParts as $part) {
                if (strlen($part) >= 3 && stripos($password, $part) !== false) {
                    $errors[] = 'A senha não deve conter partes do seu nome.';
                    break;
                }
            }
        }
        
        // Check for repeated characters (e.g., "aaaa" or "1111")
        if (preg_match('/(.)\1{3,}/', $password)) {
            $errors[] = 'A senha não deve conter caracteres repetidos.';
        }
        
        // Check for sequential characters (e.g., "1234" or "abcd")
        if (preg_match('/(012|123|234|345|456|567|678|789|abc|bcd|cde|def|efg|fgh|ghi|hij|ijk|jkl|klm|lmn|mno|nop|opq|pqr|qrs|rst|stu|tuv|uvw|vwx|wxy|xyz)/i', $password)) {
            $errors[] = 'A senha não deve conter sequências simples.';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
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

