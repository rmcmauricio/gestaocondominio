<?php

namespace Tests\Unit\Middleware;

use App\Middleware\AuthMiddleware;
use Tests\Helpers\TestCase;

class AuthMiddlewareTest extends TestCase
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
     * Test handle method returns false when user is not authenticated
     */
    public function testHandleReturnsFalseWhenNotAuthenticated(): void
    {
        $_SESSION = [];
        
        $result = AuthMiddleware::handle();
        
        $this->assertFalse($result);
    }

    /**
     * Test handle method returns true when user is authenticated
     */
    public function testHandleReturnsTrueWhenAuthenticated(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'role' => 'condomino'
        ];
        
        $result = AuthMiddleware::handle();
        
        $this->assertTrue($result);
    }

    /**
     * Test handle returns false when user array exists but id is missing
     */
    public function testHandleReturnsFalseWhenUserIdIsMissing(): void
    {
        $_SESSION['user'] = [
            'email' => 'test@example.com',
            'name' => 'Test User'
        ];
        
        $result = AuthMiddleware::handle();
        
        $this->assertFalse($result);
    }

    /**
     * Test handle returns false when user id is empty
     */
    public function testHandleReturnsFalseWhenUserIdIsEmpty(): void
    {
        $_SESSION['user'] = [
            'id' => null,
            'email' => 'test@example.com'
        ];
        
        $result = AuthMiddleware::handle();
        
        $this->assertFalse($result);
    }

    /**
     * Test user method returns null when not authenticated
     */
    public function testUserReturnsNullWhenNotAuthenticated(): void
    {
        $_SESSION = [];
        
        $result = AuthMiddleware::user();
        
        $this->assertNull($result);
    }

    /**
     * Test user method returns user array when authenticated
     */
    public function testUserReturnsUserArrayWhenAuthenticated(): void
    {
        $userData = [
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'role' => 'condomino'
        ];
        $_SESSION['user'] = $userData;
        
        $result = AuthMiddleware::user();
        
        $this->assertIsArray($result);
        $this->assertEquals($userData, $result);
    }

    /**
     * Test userId method returns null when not authenticated
     */
    public function testUserIdReturnsNullWhenNotAuthenticated(): void
    {
        $_SESSION = [];
        
        $result = AuthMiddleware::userId();
        
        $this->assertNull($result);
    }

    /**
     * Test userId method returns user id when authenticated
     */
    public function testUserIdReturnsUserIdWhenAuthenticated(): void
    {
        $_SESSION['user'] = [
            'id' => 42,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'role' => 'condomino'
        ];
        
        $result = AuthMiddleware::userId();
        
        $this->assertEquals(42, $result);
    }

    /**
     * Test guest method returns true when not authenticated
     */
    public function testGuestReturnsTrueWhenNotAuthenticated(): void
    {
        $_SESSION = [];
        
        $result = AuthMiddleware::guest();
        
        $this->assertTrue($result);
    }

    /**
     * Test guest method returns false when authenticated
     */
    public function testGuestReturnsFalseWhenAuthenticated(): void
    {
        $_SESSION['user'] = [
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'role' => 'condomino'
        ];
        
        $result = AuthMiddleware::guest();
        
        $this->assertFalse($result);
    }
}
