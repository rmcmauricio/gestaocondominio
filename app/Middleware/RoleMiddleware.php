<?php

namespace App\Middleware;

use App\Middleware\AuthMiddleware;

class RoleMiddleware
{
    /**
     * Check if user has specific role
     */
    public static function hasRole(string $role): bool
    {
        $user = AuthMiddleware::user();
        
        if (!$user) {
            return false;
        }

        // Check if user is in demo mode and has selected a profile
        $demoProfile = $_SESSION['demo_profile'] ?? null;
        
        // If demo profile is set, use that role instead of user's actual role
        if ($demoProfile === 'condomino' && $role === 'condomino') {
            return true;
        } elseif ($demoProfile === 'admin' && ($role === 'admin' || $role === 'super_admin')) {
            return true;
        }

        $userRole = $user['role'] ?? null;
        return $userRole === $role;
    }

    /**
     * Check if user has any of the specified roles
     */
    public static function hasAnyRole(array $roles): bool
    {
        $user = AuthMiddleware::user();
        
        if (!$user) {
            return false;
        }

        // Check if user is in demo mode and has selected a profile
        $demoProfile = $_SESSION['demo_profile'] ?? null;
        
        // If demo profile is set, use that role instead of user's actual role
        if ($demoProfile === 'condomino') {
            return in_array('condomino', $roles);
        } elseif ($demoProfile === 'admin') {
            return in_array('admin', $roles) || in_array('super_admin', $roles);
        }

        $userRole = $user['role'] ?? null;
        return $userRole !== null && in_array($userRole, $roles);
    }

    /**
     * Check if user is admin (admin or super_admin)
     */
    public static function isAdmin(): bool
    {
        return self::hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Check if user is super admin
     */
    public static function isSuperAdmin(): bool
    {
        return self::hasRole('super_admin');
    }

    /**
     * Require specific role - redirect if user doesn't have it
     */
    public static function requireRole(string $role): void
    {
        AuthMiddleware::require();

        if (!self::hasRole($role)) {
            $_SESSION['error'] = 'Não tem permissão para aceder a esta página.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
    }

    /**
     * Require any of the specified roles
     */
    public static function requireAnyRole(array $roles): void
    {
        AuthMiddleware::require();

        if (!self::hasAnyRole($roles)) {
            $_SESSION['error'] = 'Não tem permissão para aceder a esta página.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
    }

    /**
     * Require admin role
     */
    public static function requireAdmin(): void
    {
        AuthMiddleware::require();

        if (!self::isAdmin()) {
            $_SESSION['error'] = 'Apenas administradores podem aceder a esta página.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
    }

    /**
     * Require super admin role
     */
    public static function requireSuperAdmin(): void
    {
        AuthMiddleware::require();

        if (!self::isSuperAdmin()) {
            $_SESSION['error'] = 'Apenas super administradores podem aceder a esta página.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
    }

    /**
     * Get user's effective role in a specific condominium
     * Returns 'admin', 'condomino', or null if no access
     * Respects view mode if set in session
     */
    public static function getUserRoleInCondominium(int $userId, int $condominiumId): ?string
    {
        global $db;
        
        if (!$db) {
            return null;
        }

        // Super admin is always admin (unless view mode is explicitly set)
        $user = AuthMiddleware::user();
        $userRole = $user['role'] ?? null;
        $isSuperAdmin = $user && $user['id'] === $userId && $userRole === 'super_admin';

        // FIRST: Check if view mode is set for this condominium (allows switching between admin/condomino view)
        // This must be checked BEFORE checking if user is owner, so view mode takes precedence
        $viewModeKey = "condominium_{$condominiumId}_view_mode";
        
        if (isset($_SESSION[$viewModeKey]) && !empty($_SESSION[$viewModeKey])) {
            $viewMode = $_SESSION[$viewModeKey];
            
            // Verify user actually has both roles before allowing view mode switch
            if ($viewMode === 'condomino' || $viewMode === 'admin') {
                $hasAdminRole = false;
                $hasCondominoRole = false;
                
                // Check if user is owner of the condominium
                $stmt = $db->prepare("SELECT id FROM condominiums WHERE id = :condominium_id AND user_id = :user_id");
                $stmt->execute([
                    ':condominium_id' => $condominiumId,
                    ':user_id' => $userId
                ]);
                if ($stmt->fetch()) {
                    $hasAdminRole = true;
                }
                
                // Check condominium_users table for roles
                $stmt = $db->prepare("
                    SELECT role, fraction_id
                    FROM condominium_users 
                    WHERE user_id = :user_id 
                    AND condominium_id = :condominium_id
                    AND (ended_at IS NULL OR ended_at > CURDATE())
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':condominium_id' => $condominiumId
                ]);
                $results = $stmt->fetchAll();
                
                foreach ($results as $result) {
                    if ($result['role'] === 'admin') {
                        $hasAdminRole = true;
                    }
                    if ($result['fraction_id'] !== null) {
                        // Has fraction association (condomino role)
                        $hasCondominoRole = true;
                    }
                }
                
                // Allow view mode switch if:
                // 1. User has both roles (can switch between admin/condomino)
                // 2. User has only the role matching the view_mode (e.g., only condomino and view_mode is condomino)
                if ($hasAdminRole && $hasCondominoRole) {
                    // Return the view mode from session (admin or condomino)
                    // This overrides the default admin role for owners
                    return $viewMode;
                } elseif ($viewMode === 'condomino' && $hasCondominoRole && !$hasAdminRole) {
                    // User is only condomino and view_mode is condomino - respect it
                    return 'condomino';
                } elseif ($viewMode === 'admin' && $hasAdminRole && !$hasCondominoRole) {
                    // User is only admin and view_mode is admin - respect it
                    return 'admin';
                }
            }
        }

        // If no view mode is set or user doesn't have both roles, determine role normally
        // Super admin is always admin
        if ($isSuperAdmin) {
            return 'admin';
        }

        // Check if user is owner of the condominium
        $stmt = $db->prepare("SELECT id FROM condominiums WHERE id = :condominium_id AND user_id = :user_id");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId
        ]);
        if ($stmt->fetch()) {
            // Owner is always admin by default (unless view mode is set to condomino)
            return 'admin';
        }

        // Check condominium_users table for role
        $stmt = $db->prepare("
            SELECT role, fraction_id
            FROM condominium_users 
            WHERE user_id = :user_id 
            AND condominium_id = :condominium_id
            AND (ended_at IS NULL OR ended_at > CURDATE())
            ORDER BY 
                CASE WHEN role = 'admin' THEN 1 ELSE 2 END,
                is_primary DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        $result = $stmt->fetch();
        
        if ($result) {
            $role = $result['role'];
            // Return 'admin' or 'condomino' (other roles like 'proprietario' are treated as 'condomino')
            if ($role === 'admin') {
                return 'admin';
            } else {
                return 'condomino';
            }
        }

        return null;
    }

    /**
     * Check if user can access condominium (is admin or associated condomino)
     */
    public static function canAccessCondominium(int $condominiumId): bool
    {
        $user = AuthMiddleware::user();
        
        if (!$user) {
            return false;
        }

        // Check if user is in demo mode and has selected a profile
        $demoProfile = $_SESSION['demo_profile'] ?? null;
        
        // Super admin can access all
        $userRole = $user['role'] ?? null;
        if ($userRole === 'super_admin') {
            return true;
        }

        // Use getUserRoleInCondominium to check access
        $role = self::getUserRoleInCondominium($user['id'], $condominiumId);
        
        // If demo profile is set, override role check
        if ($demoProfile === 'condomino' && $role === 'admin') {
            // In demo mode as condomino, treat admin role as condomino for access
            return $role !== null; // Still has access, just with condomino permissions
        }

        return $role !== null;
    }

    /**
     * Require access to condominium
     */
    public static function requireCondominiumAccess(int $condominiumId): void
    {
        AuthMiddleware::require();

        if (!self::canAccessCondominium($condominiumId)) {
            $_SESSION['error'] = 'Não tem permissão para aceder a este condomínio.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
    }

    /**
     * Check if user has admin role in specific condominium
     */
    public static function isAdminInCondominium(int $userId, int $condominiumId): bool
    {
        $role = self::getUserRoleInCondominium($userId, $condominiumId);
        return $role === 'admin';
    }

    /**
     * Require admin role in specific condominium
     */
    public static function requireAdminInCondominium(int $condominiumId): void
    {
        AuthMiddleware::require();
        
        $userId = AuthMiddleware::userId();
        if (!self::isAdminInCondominium($userId, $condominiumId)) {
            $_SESSION['error'] = 'Apenas administradores podem aceder a esta página.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId);
            exit;
        }
    }

    /**
     * Check if user has both admin and condomino roles in a condominium
     * This allows them to switch between admin and condomino view
     */
    public static function hasBothRolesInCondominium(int $userId, int $condominiumId): bool
    {
        global $db;
        
        if (!$db) {
            return false;
        }

        $hasAdminRole = false;
        $hasCondominoRole = false;
        
        // Check if user is owner of the condominium
        $stmt = $db->prepare("SELECT id FROM condominiums WHERE id = :condominium_id AND user_id = :user_id");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId
        ]);
        if ($stmt->fetch()) {
            $hasAdminRole = true;
        }
        
        // Check condominium_users table
        $stmt = $db->prepare("
            SELECT role, fraction_id
            FROM condominium_users 
            WHERE user_id = :user_id 
            AND condominium_id = :condominium_id
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        $results = $stmt->fetchAll();
        
        foreach ($results as $result) {
            if ($result['role'] === 'admin') {
                $hasAdminRole = true;
            } elseif ($result['fraction_id'] !== null) {
                // Has fraction association (condomino role)
                $hasCondominoRole = true;
            }
        }
        
        return $hasAdminRole && $hasCondominoRole;
    }
}





