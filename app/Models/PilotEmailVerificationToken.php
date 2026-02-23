<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Security;

class PilotEmailVerificationToken extends Model
{
    protected $table = 'pilot_email_verification_tokens';

    /**
     * Create or replace verification token for email (24h expiry).
     * If email already has a pending token, it is replaced.
     *
     * @param string $email Email address
     * @return string Token string
     */
    public function createToken(string $email): string
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $token = Security::generateToken(32);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // Replace any existing token for this email (so resend = new link)
        $del = $this->db->prepare("DELETE FROM pilot_email_verification_tokens WHERE email = :email");
        $del->execute([':email' => $email]);

        $stmt = $this->db->prepare("
            INSERT INTO pilot_email_verification_tokens (email, token, expires_at)
            VALUES (:email, :token, :expires_at)
        ");
        $stmt->execute([
            ':email' => $email,
            ':token' => $token,
            ':expires_at' => $expiresAt
        ]);

        return $token;
    }

    /**
     * Check if this email has a recent verification request (to limit re-sends / spam).
     *
     * @param string $email Email address
     * @param int $withinSeconds Consider "recent" if last request was within this many seconds (default 1 hour)
     * @return bool True if there was a recent request
     */
    public function hasRecentRequest(string $email, int $withinSeconds = 3600): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT 1 FROM {$this->table}
            WHERE email = :email AND created_at > DATE_SUB(NOW(), INTERVAL :seconds SECOND)
            LIMIT 1
        ");
        $stmt->bindValue(':email', $email, \PDO::PARAM_STR);
        $stmt->bindValue(':seconds', $withinSeconds, \PDO::PARAM_INT);
        $stmt->execute();

        return (bool) $stmt->fetch();
    }

    /**
     * Find valid token (not expired).
     *
     * @param string $token Token string
     * @return array|null Token row or null
     */
    public function findByToken(string $token): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM pilot_email_verification_tokens
            WHERE token = :token AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([':token' => $token]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Delete token after successful verification.
     *
     * @param string $token Token string
     * @return bool Success
     */
    public function deleteByToken(string $token): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM pilot_email_verification_tokens WHERE token = :token");
        return $stmt->execute([':token' => $token]);
    }
}
