<?php

namespace App\Models;

use App\Core\Model;
use App\Middleware\DemoProtectionMiddleware;

class UserEmailPreference extends Model
{
    protected $table = 'user_email_preferences';

    /**
     * Get user email preferences
     * For demo users, always returns false for all preferences
     */
    public function getPreferences(int $userId): array
    {
        // Demo users never have email enabled
        if (DemoProtectionMiddleware::isDemoUser($userId)) {
            return [
                'email_notifications_enabled' => false,
                'email_messages_enabled' => false
            ];
        }

        if (!$this->db) {
            // Return defaults if no database
            return [
                'email_notifications_enabled' => true,
                'email_messages_enabled' => true
            ];
        }

        $stmt = $this->db->prepare("
            SELECT email_notifications_enabled, email_messages_enabled 
            FROM user_email_preferences 
            WHERE user_id = :user_id 
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $preferences = $stmt->fetch();

        if ($preferences) {
            return [
                'email_notifications_enabled' => (bool)$preferences['email_notifications_enabled'],
                'email_messages_enabled' => (bool)$preferences['email_messages_enabled']
            ];
        }

        // Return defaults if no preferences found (lazy initialization)
        return [
            'email_notifications_enabled' => true,
            'email_messages_enabled' => true
        ];
    }

    /**
     * Check if email is enabled for a specific type
     * For demo users, always returns false
     */
    public function hasEmailEnabled(int $userId, string $emailType): bool
    {
        // Demo users never have email enabled
        if (DemoProtectionMiddleware::isDemoUser($userId)) {
            return false;
        }

        $preferences = $this->getPreferences($userId);

        if ($emailType === 'notification') {
            return $preferences['email_notifications_enabled'] ?? true;
        } elseif ($emailType === 'message') {
            return $preferences['email_messages_enabled'] ?? true;
        }

        // Default to true if type not specified
        return true;
    }

    /**
     * Update user email preferences
     * Prevents demo users from updating preferences
     */
    public function updatePreferences(int $userId, array $preferences): bool
    {
        // Prevent demo users from updating preferences
        if (DemoProtectionMiddleware::isDemoUser($userId)) {
            throw new \Exception('Não é possível alterar preferências de email para utilizadores demo.');
        }

        if (!$this->db) {
            return false;
        }

        $emailNotificationsEnabled = isset($preferences['email_notifications_enabled']) 
            ? (bool)$preferences['email_notifications_enabled'] 
            : true;
        
        $emailMessagesEnabled = isset($preferences['email_messages_enabled']) 
            ? (bool)$preferences['email_messages_enabled'] 
            : true;

        // Check if preferences exist
        $stmt = $this->db->prepare("SELECT id FROM user_email_preferences WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing preferences
            $updateStmt = $this->db->prepare("
                UPDATE user_email_preferences 
                SET email_notifications_enabled = :email_notifications_enabled,
                    email_messages_enabled = :email_messages_enabled,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
            return $updateStmt->execute([
                ':user_id' => $userId,
                ':email_notifications_enabled' => $emailNotificationsEnabled ? 1 : 0,
                ':email_messages_enabled' => $emailMessagesEnabled ? 1 : 0
            ]);
        } else {
            // Create new preferences
            $insertStmt = $this->db->prepare("
                INSERT INTO user_email_preferences 
                (user_id, email_notifications_enabled, email_messages_enabled, created_at, updated_at)
                VALUES (:user_id, :email_notifications_enabled, :email_messages_enabled, NOW(), NOW())
            ");
            return $insertStmt->execute([
                ':user_id' => $userId,
                ':email_notifications_enabled' => $emailNotificationsEnabled ? 1 : 0,
                ':email_messages_enabled' => $emailMessagesEnabled ? 1 : 0
            ]);
        }
    }

    /**
     * Initialize preferences with defaults for a user (lazy initialization)
     */
    public function initializePreferences(int $userId): bool
    {
        // Don't initialize for demo users
        if (DemoProtectionMiddleware::isDemoUser($userId)) {
            return false;
        }

        if (!$this->db) {
            return false;
        }

        // Check if already exists
        $stmt = $this->db->prepare("SELECT id FROM user_email_preferences WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        if ($stmt->fetch()) {
            return true; // Already exists
        }

        // Create with defaults (both enabled)
        $insertStmt = $this->db->prepare("
            INSERT INTO user_email_preferences 
            (user_id, email_notifications_enabled, email_messages_enabled, created_at, updated_at)
            VALUES (:user_id, 1, 1, NOW(), NOW())
        ");
        return $insertStmt->execute([':user_id' => $userId]);
    }
}
