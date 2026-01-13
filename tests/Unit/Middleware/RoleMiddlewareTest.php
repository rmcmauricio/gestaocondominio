<?php

namespace Tests\Unit\Middleware;

use App\Middleware\RoleMiddleware;
use App\Middleware\AuthMiddleware;
use Tests\Helpers\TestCase;

class RoleMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Test hasRole returns false when user is not authenticated
     */
    public function testHasRoleReturnsFalseWhenNotAuthenticated(): void
    {
        $_SESSION = [];
        
        $result = RoleMiddleware::hasRole('admin');
        
        $this->assertFalse($result);
    }

    /**
     * Test hasRole returns true when user has the role
     */
    public function testHasRoleReturnsTrueWhenUserHasRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'admin@example.com',
            'name' => 'Admin User',
            'role' => 'admin'
        ];
        
        $result = RoleMiddleware::hasRole('admin');
        
        $this->assertTrue($result);
    }

    /**
     * Test hasRole returns false when user doesn't have the role
     */
    public function testHasRoleReturnsFalseWhenUserDoesNotHaveRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'user@example.com',
            'name' => 'Regular User',
            'role' => 'condomino'
        ];
        
        $result = RoleMiddleware::hasRole('admin');
        
        $this->assertFalse($result);
    }

    /**
     * Test hasAnyRole returns false when user is not authenticated
     */
    public function testHasAnyRoleReturnsFalseWhenNotAuthenticated(): void
    {
        $_SESSION = [];
        
        $result = RoleMiddleware::hasAnyRole(['admin', 'super_admin']);
        
        $this->assertFalse($result);
    }

    /**
     * Test hasAnyRole returns true when user has one of the roles
     */
    public function testHasAnyRoleReturnsTrueWhenUserHasOneRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'admin@example.com',
            'name' => 'Admin User',
            'role' => 'admin'
        ];
        
        $result = RoleMiddleware::hasAnyRole(['admin', 'super_admin']);
        
        $this->assertTrue($result);
    }

    /**
     * Test hasAnyRole returns false when user doesn't have any of the roles
     */
    public function testHasAnyRoleReturnsFalseWhenUserDoesNotHaveAnyRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'user@example.com',
            'name' => 'Regular User',
            'role' => 'condomino'
        ];
        
        $result = RoleMiddleware::hasAnyRole(['admin', 'super_admin']);
        
        $this->assertFalse($result);
    }

    /**
     * Test isAdmin returns true for admin role
     */
    public function testIsAdminReturnsTrueForAdminRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'admin@example.com',
            'name' => 'Admin User',
            'role' => 'admin'
        ];
        
        $result = RoleMiddleware::isAdmin();
        
        $this->assertTrue($result);
    }

    /**
     * Test isAdmin returns true for super_admin role
     */
    public function testIsAdminReturnsTrueForSuperAdminRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'superadmin@example.com',
            'name' => 'Super Admin User',
            'role' => 'super_admin'
        ];
        
        $result = RoleMiddleware::isAdmin();
        
        $this->assertTrue($result);
    }

    /**
     * Test isAdmin returns false for condomino role
     */
    public function testIsAdminReturnsFalseForCondominoRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'user@example.com',
            'name' => 'Regular User',
            'role' => 'condomino'
        ];
        
        $result = RoleMiddleware::isAdmin();
        
        $this->assertFalse($result);
    }

    /**
     * Test isSuperAdmin returns true for super_admin role
     */
    public function testIsSuperAdminReturnsTrueForSuperAdminRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'superadmin@example.com',
            'name' => 'Super Admin User',
            'role' => 'super_admin'
        ];
        
        $result = RoleMiddleware::isSuperAdmin();
        
        $this->assertTrue($result);
    }

    /**
     * Test isSuperAdmin returns false for admin role
     */
    public function testIsSuperAdminReturnsFalseForAdminRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'admin@example.com',
            'name' => 'Admin User',
            'role' => 'admin'
        ];
        
        $result = RoleMiddleware::isSuperAdmin();
        
        $this->assertFalse($result);
    }

    /**
     * Test isSuperAdmin returns false for condomino role
     */
    public function testIsSuperAdminReturnsFalseForCondominoRole(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'user@example.com',
            'name' => 'Regular User',
            'role' => 'condomino'
        ];
        
        $result = RoleMiddleware::isSuperAdmin();
        
        $this->assertFalse($result);
    }
}
