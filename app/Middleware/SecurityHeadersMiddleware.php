<?php

namespace App\Middleware;

class SecurityHeadersMiddleware
{
    /**
     * Set security headers for HTTP responses
     */
    public static function setHeaders(): void
    {
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Enable XSS protection (legacy browsers)
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer Policy - only send referrer for same-origin requests
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Permissions Policy (formerly Feature-Policy)
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        
        // Strict Transport Security (HSTS) - only if HTTPS
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
        if ($isHttps && defined('APP_ENV') && APP_ENV === 'production') {
            // HSTS: Force HTTPS for 1 year, include subdomains
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        
        // Content Security Policy
        // Note: CSP is already set in .htaccess, but we can override or complement it here if needed
        // For now, we'll let .htaccess handle CSP to avoid conflicts
    }
    
    /**
     * Apply security headers (call this in Router or Controller base class)
     */
    public static function apply(): void
    {
        // Only set headers if not already sent
        if (!headers_sent()) {
            self::setHeaders();
        }
    }
}
