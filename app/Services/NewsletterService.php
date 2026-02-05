<?php

namespace App\Services;

class NewsletterService
{
    protected $db;

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Subscribe email to newsletter
     * 
     * @param string $email Email address
     * @param string $source Source of subscription (demo_access, profile, etc.)
     * @return bool Success
     */
    public function subscribe(string $email, string $source = 'demo_access'): bool
    {
        if (!$this->db) {
            return false;
        }

        try {
            // Check if email already exists
            $stmt = $this->db->prepare("SELECT * FROM newsletter_subscribers WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update existing record - resubscribe if unsubscribed
                if ($existing['unsubscribed_at'] !== null) {
                    $updateStmt = $this->db->prepare("
                        UPDATE newsletter_subscribers 
                        SET subscribed_at = NOW(), 
                            unsubscribed_at = NULL,
                            source = :source
                        WHERE email = :email
                    ");
                    return $updateStmt->execute([
                        ':email' => $email,
                        ':source' => $source
                    ]);
                }
                // Already subscribed, no action needed (idempotent)
                return true;
            } else {
                // Create new subscription
                $insertStmt = $this->db->prepare("
                    INSERT INTO newsletter_subscribers (email, source, subscribed_at)
                    VALUES (:email, :source, NOW())
                ");
                return $insertStmt->execute([
                    ':email' => $email,
                    ':source' => $source
                ]);
            }
        } catch (\Exception $e) {
            error_log("NewsletterService::subscribe error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unsubscribe email from newsletter
     * 
     * @param string $email Email address
     * @return bool Success
     */
    public function unsubscribe(string $email): bool
    {
        if (!$this->db) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                UPDATE newsletter_subscribers 
                SET unsubscribed_at = NOW() 
                WHERE email = :email AND unsubscribed_at IS NULL
            ");
            return $stmt->execute([':email' => $email]);
        } catch (\Exception $e) {
            error_log("NewsletterService::unsubscribe error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if email is subscribed to newsletter
     * 
     * @param string $email Email address
     * @return bool True if subscribed
     */
    public function isSubscribed(string $email): bool
    {
        if (!$this->db) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count FROM newsletter_subscribers 
                WHERE email = :email 
                AND unsubscribed_at IS NULL
            ");
            $stmt->execute([':email' => $email]);
            $result = $stmt->fetch();

            return ($result && $result['count'] > 0);
        } catch (\Exception $e) {
            error_log("NewsletterService::isSubscribed error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Toggle subscription status
     * 
     * @param string $email Email address
     * @param bool $subscribe True to subscribe, false to unsubscribe
     * @param string $source Source of subscription change
     * @return bool Success
     */
    public function toggleSubscription(string $email, bool $subscribe, string $source = 'profile'): bool
    {
        if ($subscribe) {
            return $this->subscribe($email, $source);
        } else {
            return $this->unsubscribe($email);
        }
    }
}
