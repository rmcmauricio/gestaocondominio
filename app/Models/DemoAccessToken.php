<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Security;

class DemoAccessToken extends Model
{
    protected $table = 'demo_access_tokens';

    /**
     * Create a new demo access token
     * 
     * @param string $email Email address
     * @param bool $wantsNewsletter Whether user wants newsletter
     * @param string|null $ipAddress IP address (optional)
     * @param string|null $userAgent User agent (optional)
     * @return string Token string
     */
    public function createToken(string $email, bool $wantsNewsletter = false, ?string $ipAddress = null, ?string $userAgent = null): string
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Generate unique token
        $token = Security::generateToken(32);
        
        // Set expiration to 24 hours from now
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $stmt = $this->db->prepare("
            INSERT INTO demo_access_tokens (
                email, token, expires_at, ip_address, user_agent, wants_newsletter
            )
            VALUES (
                :email, :token, :expires_at, :ip_address, :user_agent, :wants_newsletter
            )
        ");

        $stmt->execute([
            ':email' => $email,
            ':token' => $token,
            ':expires_at' => $expiresAt,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':wants_newsletter' => $wantsNewsletter ? 1 : 0
        ]);

        return $token;
    }

    /**
     * Find token by token string (valid and not expired)
     * 
     * @param string $token Token string
     * @return array|null Token data or null if not found/invalid
     */
    public function findByToken(string $token): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM demo_access_tokens 
            WHERE token = :token 
            AND used_at IS NULL 
            AND expires_at > NOW()
            LIMIT 1
        ");

        $stmt->execute([':token' => $token]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Mark token as used
     * 
     * @param string $token Token string
     * @return bool Success
     */
    public function markAsUsed(string $token): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE demo_access_tokens 
            SET used_at = NOW() 
            WHERE token = :token
        ");

        return $stmt->execute([':token' => $token]);
    }

    /**
     * Cleanup expired tokens (older than 24 hours past expiration)
     * 
     * @return int Number of deleted tokens
     */
    public function cleanupExpiredTokens(): int
    {
        if (!$this->db) {
            return 0;
        }

        // Delete tokens that expired more than 24 hours ago
        $stmt = $this->db->prepare("
            DELETE FROM demo_access_tokens 
            WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Check if email has a valid (unused, not expired) token
     * 
     * @param string $email Email address
     * @return bool True if has valid token
     */
    public function hasValidToken(string $email): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM demo_access_tokens 
            WHERE email = :email 
            AND used_at IS NULL 
            AND expires_at > NOW()
        ");

        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch();

        return ($result && $result['count'] > 0);
    }
}
