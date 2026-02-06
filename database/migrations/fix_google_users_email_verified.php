<?php
/**
 * Migration: Fix email_verified_at for existing Google OAuth users
 * 
 * This migration updates all users who authenticated via Google OAuth
 * but don't have email_verified_at set. Since Google verifies emails,
 * these users should be marked as verified.
 */

class FixGoogleUsersEmailVerified
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Update users who authenticated via Google but don't have email_verified_at set
        $isSQLite = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $timestampFunc = $isSQLite ? 'CURRENT_TIMESTAMP' : 'NOW()';
        
        // Get all Google OAuth users
        $stmt = $this->db->query("
            SELECT id, email_verified_at FROM users 
            WHERE auth_provider = 'google'
        ");
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        if (empty($users)) {
            return; // No Google OAuth users
        }
        
        // Update each user individually, checking PHP-side for empty/null values
        $updateStmt = $this->db->prepare("
            UPDATE users 
            SET email_verified_at = {$timestampFunc}
            WHERE id = :id
        ");
        
        foreach ($users as $user) {
            // Check if email_verified_at is NULL or empty (in PHP, not SQL)
            $needsUpdate = empty($user['email_verified_at']) || 
                          $user['email_verified_at'] === '0000-00-00 00:00:00' ||
                          $user['email_verified_at'] === '';
            
            if ($needsUpdate) {
                try {
                    $updateStmt->execute([':id' => $user['id']]);
                } catch (\Exception $e) {
                    // Log but continue with other users
                    error_log("Failed to update email_verified_at for user {$user['id']}: " . $e->getMessage());
                }
            }
        }
    }

    public function down(): void
    {
        // Rollback: Set email_verified_at to NULL for users who were updated by this migration
        // Note: This is a best-effort rollback as we can't perfectly identify which users
        // were updated by this migration vs. those who had it set before
        // In practice, this rollback might not be needed, but it's included for completeness
        $stmt = $this->db->prepare("
            UPDATE users 
            SET email_verified_at = NULL
            WHERE auth_provider = 'google'
            AND email_verified_at IS NOT NULL
        ");
        
        $stmt->execute();
    }
}
