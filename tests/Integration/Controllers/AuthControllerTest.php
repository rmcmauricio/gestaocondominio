<?php

namespace Tests\Integration\Controllers;

use App\Controllers\AuthController;
use App\Models\User;
use App\Core\Security;
use Tests\Helpers\TestCase;

class AuthControllerTest extends TestCase
{
    protected $authController;
    protected $userModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authController = new AuthController();
        $this->userModel = new User();
        
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Test verifyPassword returns false when database is not available
     */
    public function testVerifyPasswordReturnsFalseWithoutDatabase(): void
    {
        // Since we never use database, verifyPassword should return false
        $result = $this->userModel->verifyPassword('logintest@example.com', 'password123');
        
        $this->assertFalse($result);
    }

    /**
     * Test findByEmail returns null when database is not available
     */
    public function testFindByEmailReturnsNullWithoutDatabase(): void
    {
        // Since we never use database, findByEmail should return null
        $user = $this->userModel->findByEmail('invalidtest@example.com');
        
        $this->assertNull($user);
    }

    /**
     * Test findById returns null when database is not available
     */
    public function testFindByIdReturnsNullWithoutDatabase(): void
    {
        // Create mock user data
        $userData = $this->createUser([
            'email' => 'inactive@example.com',
            'status' => 'inactive'
        ]);

        // Since we never use database, findById should return null
        $user = $this->userModel->findById($userData['id']);
        $this->assertNull($user);
    }

    /**
     * Test logout clears session
     */
    public function testLogoutClearsSession(): void
    {
        // Set up authenticated user
        $this->loginAs([
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'role' => 'condomino'
        ]);

        $this->assertAuthenticated();

        // Simulate logout
        $this->logout();

        $this->assertGuest();
    }
}
