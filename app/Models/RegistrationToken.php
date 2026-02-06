<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Security;

class RegistrationToken extends Model
{
    protected $table = 'registration_tokens';

    /**
     * Create a new registration token
     * 
     * @param string $email Email address
     * @param int $createdBy User ID of super admin who created the token
     * @param int $expiresInDays Number of days until expiration (default 7)
     * @return string Token string
     */
    public function createToken(string $email, int $createdBy, int $expiresInDays = 7): string
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Generate unique token
        $token = Security::generateToken(32);
        
        // Set expiration
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days"));

        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (
                email, token, expires_at, created_by
            )
            VALUES (
                :email, :token, :expires_at, :created_by
            )
        ");

        $stmt->execute([
            ':email' => $email,
            ':token' => $token,
            ':expires_at' => $expiresAt,
            ':created_by' => $createdBy
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
            SELECT * FROM {$this->table} 
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
            UPDATE {$this->table} 
            SET used_at = NOW() 
            WHERE token = :token
        ");

        return $stmt->execute([':token' => $token]);
    }

    /**
     * Check if token is valid (not used and not expired)
     * 
     * @param string $token Token string
     * @return bool True if valid
     */
    public function isValid(string $token): bool
    {
        $tokenData = $this->findByToken($token);
        return $tokenData !== null;
    }

    /**
     * Get all tokens for an email
     * 
     * @param string $email Email address
     * @return array Array of token records
     */
    public function getByEmail(string $email): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table} 
            WHERE email = :email 
            ORDER BY created_at DESC
        ");

        $stmt->execute([':email' => $email]);
        $results = $stmt->fetchAll();

        return $results ?: [];
    }

    /**
     * Cleanup expired tokens (older than expiration date)
     * 
     * @return int Number of deleted tokens
     */
    public function cleanupExpiredTokens(): int
    {
        if (!$this->db) {
            return 0;
        }

        // Delete tokens that expired more than 7 days ago
        $stmt = $this->db->prepare("
            DELETE FROM {$this->table} 
            WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");

        $stmt->execute();
        return $stmt->rowCount();
    }
}
