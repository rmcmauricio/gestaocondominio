<?php

namespace Tests\Helpers;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PDO;

/**
 * Base test case class for all tests
 * Provides common setup/teardown and helper methods
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected $db;
    protected static $pdo;

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear session
        $_SESSION = [];
        
        // NEVER use real database - always use null to force mock data
        $this->db = null;
        
        // Set up test environment
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = 'off';
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        // Clear session
        $_SESSION = [];

        // Clear POST/GET
        $_POST = [];
        $_GET = [];
        $_FILES = [];

        parent::tearDown();
    }

    /**
     * Create a test user (mock data only - never writes to database)
     */
    protected function createUser(array $attributes = []): array
    {
        $defaults = [
            'id' => rand(1000, 9999), // Random ID to avoid conflicts
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'role' => 'condomino',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $data = array_merge($defaults, $attributes);

        // Always return mock data - never write to database
        if (isset($data['password'])) {
            $passwordInfo = @password_get_info($data['password']);
            if (!$passwordInfo || !isset($passwordInfo['algo']) || $passwordInfo['algo'] === 0) {
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
        }
        
        return $data;
    }

    /**
     * Create a test condominium (mock data only - never writes to database)
     */
    protected function createCondominium(array $attributes = []): array
    {
        $defaults = [
            'id' => rand(1000, 9999), // Random ID to avoid conflicts
            'name' => 'Test Condominium',
            'address' => 'Test Address',
            'city' => 'Lisbon',
            'postal_code' => '1000-001',
            'user_id' => 1,
            'is_demo' => false,
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $data = array_merge($defaults, $attributes);
        
        // Ensure is_demo is boolean
        if (isset($data['is_demo'])) {
            $data['is_demo'] = (bool)$data['is_demo'];
        }
        
        // Always return mock data - never write to database
        return $data;
    }

    /**
     * Create a test fraction (mock data only - never writes to database)
     */
    protected function createFraction(int $condominiumId, array $attributes = []): array
    {
        $defaults = [
            'id' => rand(1000, 9999), // Random ID to avoid conflicts
            'condominium_id' => $condominiumId,
            'identifier' => 'A1',
            'permillage' => 100.0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $data = array_merge($defaults, $attributes);
        
        // Always return mock data - never write to database
        return $data;
    }

    /**
     * Login as a user (set session)
     */
    protected function loginAs(array $user): void
    {
        $_SESSION['user'] = [
            'id' => $user['id'] ?? 1,
            'email' => $user['email'] ?? 'test@example.com',
            'name' => $user['name'] ?? 'Test User',
            'role' => $user['role'] ?? 'condomino'
        ];
    }

    /**
     * Logout (clear session)
     */
    protected function logout(): void
    {
        $_SESSION = [];
    }

    /**
     * Assert that user is authenticated
     */
    protected function assertAuthenticated(): void
    {
        $this->assertArrayHasKey('user', $_SESSION, 'User should be authenticated');
        $this->assertIsArray($_SESSION['user']);
    }

    /**
     * Assert that user is not authenticated
     */
    protected function assertGuest(): void
    {
        $this->assertArrayNotHasKey('user', $_SESSION, 'User should not be authenticated');
    }

    /**
     * Assert that user has specific role
     */
    protected function assertUserRole(string $expectedRole): void
    {
        $this->assertAuthenticated();
        $this->assertEquals($expectedRole, $_SESSION['user']['role'] ?? null, "User should have role: {$expectedRole}");
    }

    /**
     * Clean up test data from database
     * NOTE: This method does nothing as we never write to the database
     */
    protected function cleanUpDatabase(): void
    {
        // No-op: Tests never write to database, so no cleanup needed
        return;
    }

    /**
     * Get a mock PDO connection for testing
     */
    protected function getMockPDO(): PDO
    {
        return new PDO('sqlite::memory:');
    }
}
