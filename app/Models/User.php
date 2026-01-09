<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Security;

class User extends Model
{
    protected $table = 'users';

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Find user by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Create new user
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO users (email, password, name, role, phone, nif, status)
            VALUES (:email, :password, :name, :role, :phone, :nif, :status)
        ");

        $stmt->execute([
            ':email' => $data['email'],
            ':password' => Security::hashPassword($data['password']),
            ':name' => $data['name'],
            ':role' => $data['role'] ?? 'condomino',
            ':phone' => $data['phone'] ?? null,
            ':nif' => $data['nif'] ?? null,
            ':status' => $data['status'] ?? 'active'
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update user
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            if ($key === 'password') {
                $fields[] = "password = :password";
                $params[':password'] = Security::hashPassword($value);
            } else {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE users SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Verify user password
     */
    public function verifyPassword(string $email, string $password): bool
    {
        $user = $this->findByEmail($email);
        
        if (!$user) {
            return false;
        }

        return Security::verifyPassword($password, $user['password']);
    }

    /**
     * Enable 2FA for user
     */
    public function enable2FA(int $id, string $secret): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE users 
            SET two_factor_secret = :secret, two_factor_enabled = TRUE 
            WHERE id = :id
        ");

        return $stmt->execute([
            ':secret' => $secret,
            ':id' => $id
        ]);
    }

    /**
     * Disable 2FA for user
     */
    public function disable2FA(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE users 
            SET two_factor_secret = NULL, two_factor_enabled = FALSE 
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $id]);
    }

    /**
     * Create password reset token
     */
    public function createPasswordResetToken(string $email): ?string
    {
        if (!$this->db) {
            return null;
        }

        $user = $this->findByEmail($email);
        if (!$user) {
            return null;
        }

        $token = Security::generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $stmt = $this->db->prepare("
            INSERT INTO password_resets (email, token, expires_at)
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
     * Verify password reset token
     */
    public function verifyPasswordResetToken(string $token): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM password_resets 
            WHERE token = :token 
            AND expires_at > NOW() 
            AND used_at IS NULL
            LIMIT 1
        ");

        $stmt->execute([':token' => $token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Mark password reset token as used
     */
    public function markPasswordResetTokenAsUsed(string $token): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE password_resets 
            SET used_at = NOW() 
            WHERE token = :token
        ");

        return $stmt->execute([':token' => $token]);
    }

    /**
     * Reset password using token
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        if (!$this->db) {
            return false;
        }

        $reset = $this->verifyPasswordResetToken($token);
        if (!$reset) {
            return false;
        }

        $this->db->beginTransaction();

        try {
            // Update password
            $stmt = $this->db->prepare("UPDATE users SET password = :password WHERE email = :email");
            $stmt->execute([
                ':password' => Security::hashPassword($newPassword),
                ':email' => $reset['email']
            ]);

            // Mark token as used
            $this->markPasswordResetTokenAsUsed($token);

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Check if user has role
     */
    public function hasRole(int $userId, string $role): bool
    {
        $user = $this->findById($userId);
        return $user && $user['role'] === $role;
    }

    /**
     * Check if user is admin or super admin
     */
    public function isAdmin(int $userId): bool
    {
        $user = $this->findById($userId);
        return $user && in_array($user['role'], ['admin', 'super_admin']);
    }

    /**
     * Generate API key for user
     */
    public function generateApiKey(int $id): string
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $apiKey = 'pk_' . Security::generateToken(32);

        $stmt = $this->db->prepare("
            UPDATE users 
            SET api_key = :api_key, 
                api_key_created_at = NOW(),
                api_key_last_used_at = NULL
            WHERE id = :id
        ");

        $stmt->execute([
            ':api_key' => $apiKey,
            ':id' => $id
        ]);

        return $apiKey;
    }

    /**
     * Revoke API key
     */
    public function revokeApiKey(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE users 
            SET api_key = NULL, 
                api_key_created_at = NULL,
                api_key_last_used_at = NULL
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $id]);
    }

    /**
     * Get API key info
     */
    public function getApiKeyInfo(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT api_key, api_key_created_at, api_key_last_used_at
            FROM users 
            WHERE id = :id
        ");

        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();

        if (!$result || !$result['api_key']) {
            return null;
        }

        // Mask API key for display (show only first 8 and last 4 characters)
        $key = $result['api_key'];
        $maskedKey = substr($key, 0, 8) . '...' . substr($key, -4);

        return [
            'api_key' => $maskedKey,
            'full_key' => $key, // Only return on generation
            'created_at' => $result['api_key_created_at'],
            'last_used_at' => $result['api_key_last_used_at']
        ];
    }
}

