<?php

namespace App\Core;

/**
 * Debug Logger Helper
 * 
 * Controls debug logging based on environment variables.
 * Add flags to .env file to enable/disable specific log types.
 */
class DebugLogger
{
    /**
     * Check if a specific log type is enabled
     * 
     * @param string $logType The log type to check (e.g., 'router', 'email', 'controller')
     * @return bool True if logging is enabled for this type
     */
    public static function isEnabled(string $logType): bool
    {
        // Get the environment variable for this log type
        $envKey = 'LOG_DEBUG_' . strtoupper($logType);
        $value = $_ENV[$envKey] ?? null;
        
        // Default to false (disabled) if not set
        if ($value === null) {
            return false;
        }
        
        // Accept 'true', '1', 'yes', 'on' as enabled
        $value = strtolower(trim($value));
        return in_array($value, ['true', '1', 'yes', 'on'], true);
    }
    
    /**
     * Log a message if the log type is enabled
     * 
     * @param string $logType The log type (e.g., 'router', 'email', 'controller')
     * @param string $message The message to log
     * @return void
     */
    public static function log(string $logType, string $message): void
    {
        if (self::isEnabled($logType)) {
            error_log($message);
        }
    }
    
    /**
     * Convenience methods for common log types
     */
    public static function router(string $message): void
    {
        self::log('router', $message);
    }
    
    public static function email(string $message): void
    {
        self::log('email', $message);
    }
    
    public static function emailSend(string $message): void
    {
        self::log('email_send', $message);
    }
    
    public static function phpmailer(string $message): void
    {
        self::log('phpmailer', $message);
    }
    
    public static function controller(string $message): void
    {
        self::log('controller', $message);
    }
    
    public static function emailTemplate(string $message): void
    {
        self::log('email_template', $message);
    }
}
