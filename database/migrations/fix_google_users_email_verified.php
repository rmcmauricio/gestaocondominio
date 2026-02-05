<?php
/**
 * Migration: Fix email_verified_at for existing Google OAuth users
 * 
 * This migration updates all users who authenticated via Google OAuth
 * but don't have email_verified_at set. Since Google verifies emails,
 * these users should be marked as verified.
 */

require_once __DIR__ . '/../bootstrap.php';

global $db;

if (!$db) {
    echo "Database connection not available.\n";
    exit(1);
}

try {
    // Update users who authenticated via Google but don't have email_verified_at set
    $isSQLite = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $timestampFunc = $isSQLite ? 'CURRENT_TIMESTAMP' : 'NOW()';
    
    $stmt = $db->prepare("
        UPDATE users 
        SET email_verified_at = {$timestampFunc}
        WHERE auth_provider = 'google' 
        AND (email_verified_at IS NULL OR email_verified_at = '')
    ");
    
    $stmt->execute();
    $affectedRows = $stmt->rowCount();
    
    echo "Migration completed successfully.\n";
    echo "Updated {$affectedRows} user(s) who authenticated via Google OAuth.\n";
    
} catch (\Exception $e) {
    echo "Error running migration: " . $e->getMessage() . "\n";
    exit(1);
}
