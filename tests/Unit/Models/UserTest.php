<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\Helpers\TestCase;

class UserTest extends TestCase
{
    protected $userModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userModel = new User();
    }

    /**
     * Test findByEmail returns null when database is not available
     */
    public function testFindByEmailReturnsNullWhenNoDatabase(): void
    {
        $result = $this->userModel->findByEmail('test@example.com');
        
        $this->assertNull($result);
    }

    /**
     * Test findById returns null when database is not available
     */
    public function testFindByIdReturnsNullWhenNoDatabase(): void
    {
        $result = $this->userModel->findById(1);
        
        $this->assertNull($result);
    }

    /**
     * Test create throws exception when database is not available
     */
    public function testCreateThrowsExceptionWhenNoDatabase(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database connection not available');
        
        $this->userModel->create([
            'email' => 'test@example.com',
            'password' => 'password123',
            'name' => 'Test User',
            'role' => 'condomino'
        ]);
    }

    /**
     * Test update returns false when database is not available
     */
    public function testUpdateReturnsFalseWhenNoDatabase(): void
    {
        $result = $this->userModel->update(1, [
            'name' => 'Updated Name',
            'phone' => '123456789'
        ]);

        $this->assertFalse($result);
    }

    /**
     * Test update returns false when database is not available (password hashing test skipped)
     */
    public function testUpdateReturnsFalseWithoutDatabase(): void
    {
        $result = $this->userModel->update(1, [
            'password' => 'newpassword123'
        ]);

        $this->assertFalse($result);
    }

    /**
     * Test verifyPassword returns false when database is not available
     */
    public function testVerifyPasswordReturnsFalseWhenNoDatabase(): void
    {
        $result = $this->userModel->verifyPassword('test@example.com', 'password123');
        
        $this->assertFalse($result);
    }

    /**
     * Test updateLastLogin returns false when database is not available
     */
    public function testUpdateLastLoginReturnsFalseWhenNoDatabase(): void
    {
        $result = $this->userModel->updateLastLogin(1);
        
        $this->assertFalse($result);
    }
}
