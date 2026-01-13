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

        return $user['role'] === $role;
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

        return in_array($user['role'], $roles);
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
        
        // Determine effective role considering demo profile
        $effectiveRole = $user['role'];
        if ($demoProfile === 'condomino') {
            $effectiveRole = 'condomino';
        } elseif ($demoProfile === 'admin' && ($user['role'] === 'admin' || $user['role'] === 'super_admin')) {
            $effectiveRole = $user['role']; // Keep admin or super_admin
        }

        // Super admin can access all
        if ($effectiveRole === 'super_admin') {
            return true;
        }

        // Admin can access their own condominiums
        if ($effectiveRole === 'admin') {
            global $db;
            if ($db) {
                $stmt = $db->prepare("SELECT id FROM condominiums WHERE user_id = :user_id AND id = :condominium_id");
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':condominium_id' => $condominiumId
                ]);
                return $stmt->fetch() !== false;
            }
        }

        // Condomino can access if associated
        if ($effectiveRole === 'condomino') {
            global $db;
            if ($db) {
                $stmt = $db->prepare("
                    SELECT id FROM condominium_users 
                    WHERE user_id = :user_id 
                    AND condominium_id = :condominium_id
                    AND (ended_at IS NULL OR ended_at > CURDATE())
                ");
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':condominium_id' => $condominiumId
                ]);
                return $stmt->fetch() !== false;
            }
        }

        return false;
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
}





