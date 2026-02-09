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
     * Find user by Google ID
     */
    public function findByGoogleId(string $googleId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE google_id = :google_id LIMIT 1");
        $stmt->execute([':google_id' => $googleId]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Link Google account to existing user
     */
    public function linkGoogleAccount(int $userId, string $googleId): bool
    {
        if (!$this->db) {
            return false;
        }

        // Detect SQLite for compatibility
        $isSQLite = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $timestampFunc = $isSQLite ? 'CURRENT_TIMESTAMP' : 'NOW()';

        $stmt = $this->db->prepare("
            UPDATE users 
            SET google_id = :google_id, 
                auth_provider = 'google',
                email_verified_at = {$timestampFunc}
            WHERE id = :user_id
        ");

        return $stmt->execute([
            ':google_id' => $googleId,
            ':user_id' => $userId
        ]);
    }

    /**
     * Create new user
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Build dynamic SQL based on provided fields
        $fields = [];
        $values = [];
        $params = [];

        // Required fields
        $fields[] = 'email';
        $values[] = ':email';
        $params[':email'] = $data['email'];

        $fields[] = 'name';
        $values[] = ':name';
        $params[':name'] = $data['name'];

        $fields[] = 'role';
        $values[] = ':role';
        $params[':role'] = $data['role'] ?? 'condomino';

        $fields[] = 'status';
        $values[] = ':status';
        $params[':status'] = $data['status'] ?? 'active';

        // Optional fields
        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = 'password';
            $values[] = ':password';
            $params[':password'] = Security::hashPassword($data['password']);
        }

        if (isset($data['phone'])) {
            $fields[] = 'phone';
            $values[] = ':phone';
            $params[':phone'] = $data['phone'];
        }

        if (isset($data['nif'])) {
            $fields[] = 'nif';
            $values[] = ':nif';
            $params[':nif'] = $data['nif'];
        }

        if (isset($data['google_id'])) {
            $fields[] = 'google_id';
            $values[] = ':google_id';
            $params[':google_id'] = $data['google_id'];
        }

        if (isset($data['auth_provider'])) {
            $fields[] = 'auth_provider';
            $values[] = ':auth_provider';
            $params[':auth_provider'] = $data['auth_provider'];
        }

        if (isset($data['email_verified_at'])) {
            $fields[] = 'email_verified_at';
            $values[] = ':email_verified_at';
            $params[':email_verified_at'] = $data['email_verified_at'];
        }

        if (isset($data['is_pioneer'])) {
            $fields[] = 'is_pioneer';
            $values[] = ':is_pioneer';
            $params[':is_pioneer'] = $data['is_pioneer'] ? 1 : 0;
        }

        $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $userId = (int)$this->db->lastInsertId();
        
        // Log audit
        $this->auditCreate($userId, $data);
        
        return $userId;
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

        // Get old data for audit
        $oldData = $this->findById($id);
        
        // Detect SQLite for compatibility
        $isSQLite = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $timestampFunc = $isSQLite ? 'CURRENT_TIMESTAMP' : 'NOW()';
        
        $sql = "UPDATE users SET " . implode(', ', $fields) . ", updated_at = {$timestampFunc} WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        $result = $stmt->execute($params);
        
        // Log audit
        if ($result) {
            $this->auditUpdate($id, $data, $oldData);
        }
        
        return $result;
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        // Detect SQLite for compatibility
        $isSQLite = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $timestampFunc = $isSQLite ? 'CURRENT_TIMESTAMP' : 'NOW()';
        
        $stmt = $this->db->prepare("UPDATE users SET last_login_at = {$timestampFunc} WHERE id = :id");
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
     * Security: Invalidates all previous tokens for the email before creating a new one
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

        // Security: Invalidate all previous tokens for this email
        $invalidateStmt = $this->db->prepare("
            UPDATE password_resets 
            SET used_at = NOW() 
            WHERE email = :email 
            AND used_at IS NULL
        ");
        $invalidateStmt->execute([':email' => $email]);

        // Clean up expired tokens (older than 24 hours)
        $cleanupStmt = $this->db->prepare("
            DELETE FROM password_resets 
            WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $cleanupStmt->execute();

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
     * Security: Includes attempt limit check
     */
    public function verifyPasswordResetToken(string $token): ?array
    {
        if (!$this->db) {
            return null;
        }

        // Security: Check attempt count (prevent brute force on tokens)
        // Store attempts in session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $attemptKey = "password_reset_attempts_{$token}";
        $attempts = $_SESSION[$attemptKey] ?? 0;
        
        if ($attempts >= 5) {
            // Too many attempts - mark token as used for security
            $this->markPasswordResetTokenAsUsed($token);
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
        $result = $stmt->fetch() ?: null;
        
        if (!$result) {
            // Increment attempt counter
            $_SESSION[$attemptKey] = $attempts + 1;
        } else {
            // Reset attempts on successful verification
            unset($_SESSION[$attemptKey]);
        }
        
        return $result;
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

    /**
     * Set default condominium for user
     */
    public function setDefaultCondominium(int $userId, int $condominiumId): bool
    {
        if (!$this->db) {
            return false;
        }

        // Verify user has access to this condominium
        $user = $this->findById($userId);
        if (!$user) {
            return false;
        }

        // Check if user is admin - can be owner OR assigned admin
        if ($user['role'] === 'admin' || $user['role'] === 'super_admin') {
            global $db;
            // Check if user owns the condominium
            $stmt = $db->prepare("SELECT id FROM condominiums WHERE id = :condominium_id AND user_id = :user_id");
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':user_id' => $userId
            ]);
            $isOwner = $stmt->fetch();
            
            // If not owner, check if user is assigned as admin
            if (!$isOwner) {
                $stmt = $db->prepare("
                    SELECT id FROM condominium_users 
                    WHERE user_id = :user_id 
                    AND condominium_id = :condominium_id
                    AND role = 'admin'
                    AND (ended_at IS NULL OR ended_at > CURDATE())
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':condominium_id' => $condominiumId
                ]);
                if (!$stmt->fetch()) {
                    return false;
                }
            }
        } else {
            // Check if user is associated with this condominium
            global $db;
            $stmt = $db->prepare("
                SELECT id FROM condominium_users 
                WHERE user_id = :user_id 
                AND condominium_id = :condominium_id
                AND (ended_at IS NULL OR ended_at > CURDATE())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':condominium_id' => $condominiumId
            ]);
            if (!$stmt->fetch()) {
                return false;
            }
        }

        $stmt = $this->db->prepare("UPDATE users SET default_condominium_id = :condominium_id WHERE id = :user_id");
        return $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId
        ]);
    }

    /**
     * Get default condominium ID for user
     */
    public function getDefaultCondominiumId(int $userId): ?int
    {
        if (!$this->db) {
            return null;
        }

        $user = $this->findById($userId);
        return $user['default_condominium_id'] ?? null;
    }
}

