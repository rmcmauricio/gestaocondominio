<?php

namespace Tests\Integration\Controllers;

use App\Controllers\ProfileController;
use App\Models\User;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use Tests\Helpers\TestCase;

class ProfileControllerTest extends TestCase
{
    protected $profileController;
    protected $userModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profileController = new ProfileController();
        $this->userModel = new User();
        
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Test profile update returns false when database is not available
     */
    public function testProfileUpdateReturnsFalseWithoutDatabase(): void
    {
        // Create and login user (mock data)
        $userData = $this->createUser([
            'email' => 'profileupdate@example.com',
            'name' => 'Original Name',
            'phone' => '123456789',
            'status' => 'active'
        ]);

        $this->loginAs($userData);

        // Update profile should return false without database
        $result = $this->userModel->update($userData['id'], [
            'name' => 'Updated Name',
            'phone' => '987654321',
            'nif' => '123456789'
        ]);

        $this->assertFalse($result);
    }

    /**
     * Test findByEmail returns null when database is not available
     */
    public function testFindByEmailReturnsNullWithoutDatabase(): void
    {
        // Create mock users
        $user1 = $this->createUser([
            'email' => 'user1@example.com',
            'name' => 'User One'
        ]);

        // Since we never use database, findByEmail should return null
        $existingUser = $this->userModel->findByEmail('user1@example.com');
        $this->assertNull($existingUser);
    }

    /**
     * Test password update returns false when database is not available
     */
    public function testPasswordUpdateReturnsFalseWithoutDatabase(): void
    {
        // Create user with known password (mock data)
        $userData = $this->createUser([
            'email' => 'passwordupdate@example.com',
            'password' => 'oldpassword',
            'name' => 'Password User'
        ]);

        // Update password should return false without database
        $result = $this->userModel->update($userData['id'], [
            'password' => 'newpassword123'
        ]);

        $this->assertFalse($result);
    }
}
